<?php

/**
 * Unit tests for CellcastSmsProvider — send(), getBalance(), getSenderNumbers(),
 * and CellcastSmsDelivery construction.
 *
 * Pins:
 *
 * All tests use injected FakeHttpClient / SpyHttpClient — no network, no PHP constants.
 */

namespace Test\Sms\Cellcast\SendParsing;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_contains, assert_not_contains, assert_throws};
use \Test\AssertionFailed;
use \Sms\{
    SmsProvider, SmsRecipient, SmsSender, PhoneNumber, SenderID,
    SmsDelivery, SmsStatus,
    HttpClient, HttpRequest, HttpResponse};
use Sms\Providers\CellcastSmsProvider;
use Sms\Providers\CellcastSmsDelivery;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * Fake HttpClient that always returns a fixed response.
 */
final readonly class FakeHttpClient implements HttpClient
{
    public function __construct(
        private \Result $response,
    ) {}

    public function send(HttpRequest $request): \Result
    {
        return $this->response;
    }
}

/**
 * Spy HttpClient that captures the last request and returns a fixed response.
 */
final class SpyHttpClient implements HttpClient
{
    public ?HttpRequest $lastRequest = null;

    public function __construct(
        private \Result $response,
    ) {}

    public function send(HttpRequest $request): \Result
    {
        $this->lastRequest = $request;
        return $this->response;
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeProvider(HttpClient $http): CellcastSmsProvider
{
    return new CellcastSmsProvider(
        apiToken: 'test-token',
        url: 'https://api.cellcast.com',
        httpClient: $http,
    );
}

function successResponse(string $body): \Result
{
    return \Result::success(new HttpResponse($body));
}

function failureResponse(string $error): \Result
{
    return \Result::failure($error);
}

/**
 * Build a SmsRecipient for a given local AU number.
 */
function recipient(string $number): PhoneNumber
{
    return new PhoneNumber($number);
}

/**
 * Build a typical Cellcast queueResponse entry.
 */
function queueEntry(string $number, string $messageId): array
{
    return ['Number' => $number, 'MessageId' => $messageId];
}

// ---------------------------------------------------------------------------
// CellcastSmsDelivery — direct construction from raw payload
// ---------------------------------------------------------------------------

test('CellcastSmsDelivery: sets status to SENT for immediate (non-scheduled) sends', function () {
    $item = ['Number' => '61401000001', 'MessageId' => 'abc123'];
    $d = new CellcastSmsDelivery(new PhoneNumber('0401000001'), $item);
    assert_eq($d->status(), SmsStatus::SENT);
});

test('CellcastSmsDelivery: extracts MessageId as remoteId', function () {
    $item = ['Number' => '61401000001', 'MessageId' => 'msg-xyz'];
    $d = new CellcastSmsDelivery(new PhoneNumber('0401000001'), $item);
    assert_eq($d->remoteId(), 'msg-xyz');
});

test('CellcastSmsDelivery: numeric MessageId coerced to string', function () {
    $item = ['Number' => '61401000001', 'MessageId' => 987654];
    $d = new CellcastSmsDelivery(new PhoneNumber('0401000001'), $item);
    assert_eq($d->remoteId(), '987654');
});

test('CellcastSmsDelivery: empty MessageId yields null remoteId', function () {
    $item = ['Number' => '61401000001', 'MessageId' => ''];
    $d = new CellcastSmsDelivery(new PhoneNumber('0401000001'), $item);
    assert_eq($d->remoteId(), null);
});

test('CellcastSmsDelivery: missing MessageId yields null remoteId', function () {
    $item = ['Number' => '61401000001'];
    $d = new CellcastSmsDelivery(new PhoneNumber('0401000001'), $item);
    assert_eq($d->remoteId(), null);
});

test('CellcastSmsDelivery: rawResponse is JSON-encoded payload', function () {
    $item = ['Number' => '61401000001', 'MessageId' => 'abc'];
    $d = new CellcastSmsDelivery(new PhoneNumber('0401000001'), $item);
    $raw = $d->rawResponse();
    assert_true(is_string($raw));
    assert_contains($raw, '"MessageId"');
    assert_contains($raw, '"abc"');
});

test('CellcastSmsDelivery: recipient stored correctly', function () {
    $phone = new PhoneNumber('0401000001');
    $item = ['Number' => '61401000001', 'MessageId' => 'x'];
    $d = new CellcastSmsDelivery($phone, $item);
    assert_eq($d->recipient()->value, '0401000001');
});

test('CellcastSmsDelivery: queued status → SCHEDULED', function () {
    $item = [
        'Number' => '61401000001',
        'MessageId' => 'abc123',
        'jobInfo' => ['data' => ['messageData' => ['status' => 'queued']]],
    ];
    $d = new CellcastSmsDelivery(new PhoneNumber('0401000001'), $item);
    assert_eq($d->status(), SmsStatus::SCHEDULED);
});

test('CellcastSmsDelivery: pending status → SCHEDULED', function () {
    $item = [
        'Number' => '61401000001',
        'MessageId' => 'abc123',
        'jobInfo' => ['data' => ['messageData' => ['status' => 'pending']]],
    ];
    $d = new CellcastSmsDelivery(new PhoneNumber('0401000001'), $item);
    assert_eq($d->status(), SmsStatus::SCHEDULED);
});

test('CellcastSmsDelivery: absent status → SENT (immediate send)', function () {
    $item = ['Number' => '61401000001', 'MessageId' => 'abc123'];
    $d = new CellcastSmsDelivery(new PhoneNumber('0401000001'), $item);
    assert_eq($d->status(), SmsStatus::SENT);
});

test('CellcastSmsDelivery: sent status → SENT', function () {
    $item = [
        'Number' => '61401000001',
        'MessageId' => 'abc123',
        'jobInfo' => ['data' => ['messageData' => ['status' => 'sent']]],
    ];
    $d = new CellcastSmsDelivery(new PhoneNumber('0401000001'), $item);
    assert_eq($d->status(), SmsStatus::SENT);
});

// ---------------------------------------------------------------------------
// send() — successful queueResponse
// ---------------------------------------------------------------------------

test('send: successful response produces one delivery per queued recipient', function () {
    $response = [
        'data' => [
            'queueResponse' => [
                ['Number' => '61401000001', 'MessageId' => 'id-1'],
                ['Number' => '61402000002', 'MessageId' => 'id-2'],
            ],
        ],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->send(
        entries('Hello', [new PhoneNumber('0401000001'), new PhoneNumber('0402000002')]),
        new PhoneNumber('0400000000'),
    );

    assert_true($result->isSuccess(), 'Expected success. Error: ' . ($result->isFailure() ? $result->getError() : ''));
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 2);
});

test('send: matched deliveries have SENT status', function () {
    $response = [
        'data' => [
            'queueResponse' => [
                ['Number' => '61401000001', 'MessageId' => 'id-1'],
            ],
        ],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->send(entries('Hello', [new PhoneNumber('0401000001')]), new PhoneNumber('0400000000'));
    $deliveries = $result->getValue()->deliveries;
    assert_eq($deliveries[0]->status(), SmsStatus::SENT);
});

test('send: matched deliveries carry the MessageId as remoteId', function () {
    $response = [
        'data' => [
            'queueResponse' => [
                ['Number' => '61401000001', 'MessageId' => 'remote-abc'],
            ],
        ],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->send(entries('Hello', [new PhoneNumber('0401000001')]), new PhoneNumber('0400000000'));
    $deliveries = $result->getValue()->deliveries;
    assert_eq($deliveries[0]->remoteId(), 'remote-abc');
});

test('send: recipient absent from queueResponse is marked FAILED', function () {
    // Only one of two recipients appears in queueResponse
    $response = [
        'data' => [
            'queueResponse' => [
                ['Number' => '61401000001', 'MessageId' => 'id-1'],
                // 61402000002 is missing
            ],
        ],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->send(
        entries('Hello', [new PhoneNumber('0401000001'), new PhoneNumber('0402000002')]),
        new PhoneNumber('0400000000'),
    );

    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 2);

    // First recipient matched → SENT
    assert_eq($deliveries[0]->status(), SmsStatus::SENT);
    // Second recipient not in queueResponse → FAILED
    assert_eq($deliveries[1]->status(), SmsStatus::FAILED);
});

test('send: API-level status:false envelope fails the recipients with the API message', function () {
    $response = ['status' => false, 'message' => 'Invalid credentials', 'data' => null];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));
    $recipients = [new PhoneNumber('0401000001')];

    $result = $provider->send(entries('Hello', $recipients), new PhoneNumber('0400000000'));

    assert_true($result->isSuccess(), 'rejected recipients are returned as FAILED deliveries, so they are logged');
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 1);
    assert_eq($deliveries[0]->status(), SmsStatus::FAILED);
    assert_eq($deliveries[0]->rawResponse(), 'Invalid credentials');
    $summary = \Sms\sendSummary($deliveries, $recipients);
    assert_true($summary instanceof \Sms\Failed);
    assert_eq($summary->error, 'Invalid credentials');
});

test('send: unregistered-sender error surfaces the per-field message', function () {
    // Real Cellcast response when sending from a sender ID not registered on the account.
    $response = [
        'status' => false,
        'message' => 'Your sender id is not registered.',
        'data' => [],
        'error' => ['sender' => 'Your sender id is not registered.'],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->send(entries('Hello', [new PhoneNumber('0401000001')]), new PhoneNumber('0400000000'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->deliveries[0]->status(), SmsStatus::FAILED);
    assert_eq($result->getValue()->deliveries[0]->rawResponse(), 'Your sender id is not registered.');
});

test('send: data with no queueResponse marks the recipients failed, not sent', function () {
    $response = ['status' => true, 'data' => []];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->send(entries('Hello', [new PhoneNumber('0401000001')]), new PhoneNumber('0400000000'));

    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 1);
    assert_eq($deliveries[0]->status(), SmsStatus::FAILED);
});

test('send: missing data field marks the recipients failed with the response body', function () {
    $response = ['status' => true];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->send(entries('Hello', [new PhoneNumber('0401000001')]), new PhoneNumber('0400000000'));

    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 1);
    assert_eq($deliveries[0]->status(), SmsStatus::FAILED);
    assert_contains((string) $deliveries[0]->statusDetail(), 'Unexpected response');
});

test('send: HTTP-level failure propagates as Result failure', function () {
    $provider = makeProvider(new FakeHttpClient(failureResponse('Connection refused')));

    $result = $provider->send(entries('Hello', [new PhoneNumber('0401000001')]), new PhoneNumber('0400000000'));

    assert_true($result->isFailure());
    assert_contains($result->getError(), 'Connection refused');
});

test('send: malformed JSON response propagates as Result failure', function () {
    $provider = makeProvider(new FakeHttpClient(successResponse('not-json')));

    $result = $provider->send(entries('Hello', [new PhoneNumber('0401000001')]), new PhoneNumber('0400000000'));

    assert_true($result->isFailure());
});

test('send: request goes to /api/v1/gateway via POST', function () {
    $response = ['data' => ['queueResponse' => [['Number' => '61401000001', 'MessageId' => 'x']]]];
    $spy = new SpyHttpClient(successResponse(json_encode($response)));
    $provider = makeProvider($spy);

    $provider->send(entries('Hello', [new PhoneNumber('0401000001')]), new PhoneNumber('0400000000'));

    assert_true($spy->lastRequest !== null, 'Expected an HTTP request');
    assert_eq($spy->lastRequest->method, 'POST');
    assert_contains($spy->lastRequest->url, '/api/v1/gateway');
});

test('send: number sent as entered with countryCode 61; international-form response still matches it', function () {
    $response = ['data' => ['queueResponse' => [['Number' => '61401000001', 'MessageId' => 'x']]]];
    $spy = new SpyHttpClient(successResponse(json_encode($response)));
    $provider = makeProvider($spy);

    $result = $provider->send(entries('Hello', [new PhoneNumber('0401000001')]), new PhoneNumber('0400000000'));

    $body = json_decode($spy->lastRequest->body, true);
    assert_eq($body['contacts'], ['0401000001'], 'Number should be sent as entered; Cellcast normalises it');
    assert_eq($body['countryCode'], 61);
    $deliveries = $result->getValue()->deliveries;
    assert_true($deliveries[0]->status() !== SmsStatus::FAILED, "Response number '61401000001' must match recipient '0401000001'");
});

test('send: preview mode returns QUEUED deliveries without HTTP call', function () {
    // Should not call the HTTP client — spy would capture nothing
    $spy = new SpyHttpClient(successResponse('{}'));
    $provider = makeProvider($spy);

    $result = $provider->send(
        entries('Hello', [new PhoneNumber('0401000001'), new PhoneNumber('0402000002')]),
        new PhoneNumber('0400000000'),
        sendAt: null,
        preview: true,
    );

    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 2);
    assert_eq($deliveries[0]->status(), SmsStatus::QUEUED);
    assert_eq($deliveries[1]->status(), SmsStatus::QUEUED);
    // No HTTP call should have been made
    assert_eq($spy->lastRequest, null);
});

test('send: scheduleAt is included in body when sendAt is provided', function () {
    $response = ['data' => ['queueResponse' => [['Number' => '61401000001', 'MessageId' => 'x']]]];
    $spy = new SpyHttpClient(successResponse(json_encode($response)));
    $provider = makeProvider($spy);

    $sendAt = mktime(10, 30, 0, 1, 1, 2026);
    $provider->send(entries('Hello', [new PhoneNumber('0401000001')]), new PhoneNumber('0400000000'), sendAt: $sendAt);

    $body = json_decode($spy->lastRequest->body, true);
    assert_true(isset($body['scheduleAt']), 'Expected scheduleAt in request body');
});

test('send: queueResponse with empty/zero MessageId stored as null remoteId', function () {
    $response = [
        'data' => [
            'queueResponse' => [
                ['Number' => '61401000001', 'MessageId' => '0'],
            ],
        ],
    ];
    // MessageId '0' is non-empty string — should be stored as remoteId '0' (not null)
    // The code checks: MessageId !== '' → cast to string
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->send(entries('Hello', [new PhoneNumber('0401000001')]), new PhoneNumber('0400000000'));
    $deliveries = $result->getValue()->deliveries;
    // '0' is a non-empty string, so remoteId should be '0' (not null)
    assert_eq($deliveries[0]->remoteId(), '0');
});

// ---------------------------------------------------------------------------
// getBalance()
// ---------------------------------------------------------------------------

test('getBalance: account envelope returns integer credits', function () {
    $response = [
        'meta' => ['code' => 200, 'status' => 'success'],
        'data' => ['sms_balance' => 1500, 'account_id' => 42],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->getBalance();

    assert_true($result->isSuccess(), 'Expected success. Error: ' . ($result->isFailure() ? $result->getError() : ''));
    assert_eq($result->getValue(), 1500);
});

test('getBalance: float sms_balance truncated to int', function () {
    $response = [
        'data' => ['sms_balance' => 99.9],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->getBalance();

    assert_true($result->isSuccess());
    assert_eq($result->getValue(), 99);
});

test('getBalance: string sms_balance coerced to int', function () {
    $response = [
        'data' => ['sms_balance' => '250'],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->getBalance();

    assert_true($result->isSuccess());
    assert_eq($result->getValue(), 250);
});

test('getBalance: missing sms_balance field returns failure', function () {
    $response = [
        'data' => ['account_id' => 42],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->getBalance();

    assert_true($result->isFailure());
    assert_contains($result->getError(), 'sms_balance');
});

test('getBalance: missing data field returns failure', function () {
    $response = [
        'meta' => ['code' => 200],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->getBalance();

    assert_true($result->isFailure());
    assert_contains($result->getError(), 'data');
});

test('getBalance: HTTP failure propagates', function () {
    $provider = makeProvider(new FakeHttpClient(failureResponse('timeout')));

    $result = $provider->getBalance();

    assert_true($result->isFailure());
    assert_contains($result->getError(), 'timeout');
});

test('getBalance: requests /api/v1/apiClient/account via GET', function () {
    $response = ['data' => ['sms_balance' => 100]];
    $spy = new SpyHttpClient(successResponse(json_encode($response)));
    $provider = makeProvider($spy);

    $provider->getBalance();

    assert_true($spy->lastRequest !== null);
    assert_eq($spy->lastRequest->method, 'GET');
    assert_contains($spy->lastRequest->url, '/api/v1/apiClient/account');
});

// ---------------------------------------------------------------------------
// getSenderNumbers()
// ---------------------------------------------------------------------------

test('getSenderNumbers: canned customNumber list returns PhoneNumber objects', function () {
    $response = [
        'data' => [
            ['number' => '61401000001', 'name' => 'Church Main'],
            ['number' => '61402000002', 'name' => 'Youth Group'],
        ],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->getSenderNumbers();

    assert_true($result->isSuccess());
    $numbers = $result->getValue();
    assert_eq(count($numbers), 2);
    assert_eq($numbers[0]->value, '61401000001');
    assert_eq($numbers[1]->value, '61402000002');
});

test('getSenderNumbers: empty data array returns empty list', function () {
    $response = ['data' => []];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->getSenderNumbers();

    assert_true($result->isSuccess());
    assert_eq($result->getValue(), []);
});

test('getSenderNumbers: missing data field returns empty list', function () {
    $response = ['status' => true];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->getSenderNumbers();

    assert_true($result->isSuccess());
    assert_eq($result->getValue(), []);
});

test('getSenderNumbers: Cellcast auth error propagates as failure, not empty list', function () {
    // Cellcast returns {"code":401,"message":"Token expired","stack":"..."}
    // for auth failures.  This must NOT be treated as "no numbers".
    $response = [
        'code' => 401,
        'message' => 'Token expired',
        'stack' => 'APIError: Token expired...',
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->getSenderNumbers();

    assert_true($result->isFailure(), 'Auth error should be a failure');
    assert_contains($result->getError(), '401');
    assert_contains($result->getError(), 'Token expired');
});

test('getSenderNumbers: items missing number field are skipped', function () {
    $response = [
        'data' => [
            ['name' => 'No number here'],           // missing 'number'
            ['number' => '61401000001', 'name' => 'Valid'],
        ],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->getSenderNumbers();

    assert_true($result->isSuccess());
    $numbers = $result->getValue();
    assert_eq(count($numbers), 1);
    assert_eq($numbers[0]->value, '61401000001');
});

test('getSenderNumbers: items with empty number string are skipped', function () {
    $response = [
        'data' => [
            ['number' => '', 'name' => 'Empty'],
            ['number' => '61401000001', 'name' => 'Valid'],
        ],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->getSenderNumbers();

    assert_true($result->isSuccess());
    $numbers = $result->getValue();
    assert_eq(count($numbers), 1);
});

test('getSenderNumbers: no server-side filtering — all items with valid number returned', function () {
    // The Cellcast implementation does NOT filter by verified/approved status;
    // all items with a non-empty 'number' string are included.
    $response = [
        'data' => [
            ['number' => '61401000001', 'status' => 'pending'],
            ['number' => '61402000002', 'status' => 'approved'],
            ['number' => '61403000003', 'verified' => false],
        ],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->getSenderNumbers();

    assert_true($result->isSuccess());
    // All three have non-empty 'number' values, so all three should be returned
    assert_eq(count($result->getValue()), 3);
});

test('getSenderNumbers: HTTP failure propagates', function () {
    $provider = makeProvider(new FakeHttpClient(failureResponse('network error')));

    $result = $provider->getSenderNumbers();

    assert_true($result->isFailure());
    assert_contains($result->getError(), 'network error');
});

test('getSenderNumbers: requests /api/v1/customNumber via GET', function () {
    $spy = new SpyHttpClient(successResponse(json_encode(['data' => []])));
    $provider = makeProvider($spy);

    $provider->getSenderNumbers();

    assert_true($spy->lastRequest !== null);
    assert_eq($spy->lastRequest->method, 'GET');
    assert_contains($spy->lastRequest->url, '/api/v1/customNumber');
});

// ---------------------------------------------------------------------------
// getSenderIds() — business names from GET /api/v1/business
// ---------------------------------------------------------------------------

test('getSenderIds: reads business names from /api/v1/business, not /api/v1/customNumber', function () {
    // Approved Alpha IDs (e.g. "StBarnabas") only appear at /api/v1/business;
    // /api/v1/customNumber lists sender phone numbers.
    $spy = new SpyHttpClient(successResponse(json_encode(['data' => []])));
    $provider = makeProvider($spy);

    $provider->getSenderIds(getAll: true);

    assert_eq($spy->lastRequest->method, 'GET');
    assert_contains($spy->lastRequest->url, '/api/v1/business');
    assert_not_contains($spy->lastRequest->url, 'customNumber');
});

test('getSenderIds: approval from acmaStatus/status; deleted and nameless entries skipped', function () {
    $response = ['status' => true, 'data' => [
        ['businessname' => 'StBarnabas', 'status' => 'ACTIVE', 'acmaStatus' => 'ACMA_APPROVED', 'isDeleted' => false],
        ['businessname' => 'Pending',   'status' => 'REQUESTED', 'acmaStatus' => 'PENDING_ACMA_APPROVAL', 'isDeleted' => false],
        ['businessname' => 'Rejected',  'status' => 'REJECTED', 'acmaStatus' => 'ACMA_REJECTED', 'isDeleted' => false],
        ['businessname' => 'Gone',      'status' => 'ACTIVE', 'acmaStatus' => 'ACMA_APPROVED', 'isDeleted' => true],
        ['status' => 'ACTIVE'],
    ]];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $all = $provider->getSenderIds(getAll: true)->getValue();
    assert_eq(array_map(fn($s) => [$s->value, $s->acmaApproved], $all),
        [['StBarnabas', true], ['Pending', false], ['Rejected', false]]);

    $usable = $provider->getSenderIds()->getValue();
    assert_eq(array_map(fn($s) => $s->value, $usable), ['StBarnabas'], 'Unapproved names are hidden by default');
});

test('getSenderIds: Cellcast auth error propagates as failure', function () {
    $response = ['code' => 401, 'message' => 'Token expired', 'stack' => 'APIError: Token expired'];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));

    $result = $provider->getSenderIds();

    assert_true($result->isFailure());
    assert_contains($result->getError(), '401');
});
