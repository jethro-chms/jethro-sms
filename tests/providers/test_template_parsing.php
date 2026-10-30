<?php

/**
 * Unit tests for TemplateSmsProvider — response parsing and request building.
 *
 * Covers:
 *   - parseResponse() via send() with fake client:
 *       error regex matches → every recipient FAILED (sendSummary → Failed)
 *       rejected request keeps positional pairing across entries
 *       empty body → every recipient FAILED
 *       body 'OK' → all SENT (test-mode artifact)
 *       no OK regex → all FAILED
 *       per-recipient OK regex with _RECIPIENT_ substitution → mixed success/failure
 *       responseIdRegex capture → remoteId extracted
 *   - buildHttpRequest() placeholder substitution:
 *       _MESSAGE_ URL-encoded
 *       _RECIPIENTS_COMMAS_ URL-encoded
 *       _USER_MOBILE_ substituted with sender
 *       international placeholders only substituted when BOTH prefixes set (literal otherwise)
 *
 * Pins improvements:
 */

namespace Test\Sms\TemplateParsing;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_contains, assert_not_contains};
use \Sms\{
    PhoneNumber, SmsStatus,
    HttpClient, HttpRequest, HttpResponse};
use Sms\Providers\TemplateSmsProvider;
use function \Sms\sendSummary;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * Fake HttpClient — captures the last request and returns a fixed Result.
 */
final class FakeHttpClient implements HttpClient
{
    public ?HttpRequest $lastRequest = null;

    public function __construct(private \Result $response) {}

    public function send(HttpRequest $request): \Result
    {
        $this->lastRequest = $request;
        return $this->response;
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeProvider(
    HttpClient $http,
    string $responseOkRegex = '',
    string $responseErrorRegex = '',
    string $responseIdRegex = '',
    string $postTemplate = 'to=_RECIPIENTS_COMMAS_&message=_MESSAGE_',
    string $localPrefix = '',
    string $internationalPrefix = '',
): TemplateSmsProvider {
    return new TemplateSmsProvider(
        url: 'https://api.test.example/sms',
        postTemplate: $postTemplate,
        responseOkRegex: $responseOkRegex,
        responseErrorRegex: $responseErrorRegex,
        responseIdRegex: $responseIdRegex,
        localPrefix: $localPrefix,
        internationalPrefix: $internationalPrefix,
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

// ===========================================================================
// parseResponse() — overall error regex
// ===========================================================================

test('parseResponse(): error regex matches → every recipient FAILED with the body, sendSummary() returns Failed', function () {
    $fake = new FakeHttpClient(successResponse('ERROR: invalid credentials'));
    $provider = makeProvider($fake,
        responseOkRegex: 'OK:_RECIPIENT_',
        responseErrorRegex: 'ERROR:',
    );

    $recipients = [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')];
    $result = $provider->send(entries('Hello', $recipients), new PhoneNumber('0411111111'));

    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 2, 'One delivery per recipient, even when rejected');
    foreach ($deliveries as $i => $d) {
        assert_eq($d->recipient()->value, $recipients[$i]->value);
        assert_eq($d->status(), SmsStatus::FAILED);
        assert_eq($d->message(), 'Hello');
        assert_contains((string) $d->statusDetail(), 'ERROR: invalid credentials');
    }

    $summary = sendSummary($deliveries, $recipients);
    assert_true($summary instanceof \Sms\Failed, 'sendSummary() should be Failed when every delivery failed');
});

test('send(): a rejected per-person request keeps later results paired with their recipients', function () {
    $http = new class implements HttpClient {
        private int $n = 0;
        public function send(HttpRequest $request): \Result
        {
            return \Result::success(new HttpResponse($this->n++ === 0 ? 'ERROR: bad number' : "OK:0400000002\n"));
        }
    };
    $provider = makeProvider($http, responseOkRegex: 'OK:_RECIPIENT_', responseErrorRegex: 'ERROR:');

    $entries = [
        ['message' => 'Hi Ann', 'recipients' => [new PhoneNumber('0400000001')]],
        ['message' => 'Hi Bob', 'recipients' => [new PhoneNumber('0400000002')]],
    ];
    $deliveries = $provider->send($entries, new PhoneNumber('0411111111'))->getValue()->deliveries;

    assert_eq(count($deliveries), 2);
    assert_eq($deliveries[0]->recipient()->value, '0400000001');
    assert_eq($deliveries[0]->status(), SmsStatus::FAILED);
    assert_eq($deliveries[0]->message(), 'Hi Ann');
    assert_eq($deliveries[1]->recipient()->value, '0400000002');
    assert_eq($deliveries[1]->status(), SmsStatus::SENT);
    assert_eq($deliveries[1]->message(), 'Hi Bob');
});

test('getDescription(): credentials in the URL are redacted', function () {
    $provider = new TemplateSmsProvider(
        url: 'https://api.test.example/sms?user=me&apikey=s3cret',
        postTemplate: '',
        responseOkRegex: '',
        responseErrorRegex: '',
        responseIdRegex: '',
        localPrefix: '',
        internationalPrefix: '',
        httpClient: new FakeHttpClient(successResponse('')),
    );
    assert_not_contains($provider->getDescription(), 's3cret');
});

test('parseResponse(): error regex does not match → proceeds to OK matching', function () {
    $fake = new FakeHttpClient(successResponse("OK:0400000001\n"));
    $provider = makeProvider($fake,
        responseOkRegex: 'OK:_RECIPIENT_',
        responseErrorRegex: 'FATAL_ERROR:',
    );

    $result = $provider->send(entries('Hi', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));

    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 1);
    assert_eq($deliveries[0]->status(), SmsStatus::SENT);
});

// ===========================================================================
// parseResponse() — empty body
// ===========================================================================

test('parseResponse(): empty body ("") → recipient FAILED', function () {
    $fake = new FakeHttpClient(successResponse(''));
    $provider = makeProvider($fake, responseOkRegex: 'OK');

    $result = $provider->send(entries('Hello', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));

    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 1, 'Empty body still yields one delivery per recipient');
    assert_eq($deliveries[0]->status(), SmsStatus::FAILED);
});

test('parseResponse(): body "0" → recipient FAILED', function () {
    $fake = new FakeHttpClient(successResponse('0'));
    $provider = makeProvider($fake, responseOkRegex: 'OK');

    $result = $provider->send(entries('Hello', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));

    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 1);
    assert_eq($deliveries[0]->status(), SmsStatus::FAILED);
});

// ===========================================================================
// parseResponse() — body === 'OK' (test-mode artifact)
// ===========================================================================

test('parseResponse(): body "OK" → all recipients marked SENT (test-mode artifact)', function () {
    $fake = new FakeHttpClient(successResponse('OK'));
    $provider = makeProvider($fake, responseOkRegex: 'DOES_NOT_MATTER');

    $recipients = [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')];
    $result = $provider->send(entries('Hello', $recipients), new PhoneNumber('0411111111'));

    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 2, 'Both recipients should appear when body is "OK"');
    foreach ($deliveries as $d) {
        assert_eq($d->status(), SmsStatus::SENT, 'All deliveries should be SENT');
    }
});

// ===========================================================================
// parseResponse() — no OK regex configured
// ===========================================================================

test('parseResponse(): no OK regex configured → all recipients marked FAILED', function () {
    $fake = new FakeHttpClient(successResponse('some gateway response'));
    $provider = makeProvider($fake,
        responseOkRegex: '',      // no OK regex
        responseErrorRegex: '',
    );

    $recipients = [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')];
    $result = $provider->send(entries('Hello', $recipients), new PhoneNumber('0411111111'));

    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 2);
    foreach ($deliveries as $d) {
        assert_eq($d->status(), SmsStatus::FAILED, 'No OK regex → all FAILED');
    }
});

// ===========================================================================
// parseResponse() — per-recipient OK regex with _RECIPIENT_ substitution
// ===========================================================================

test('parseResponse(): per-recipient OK regex with _RECIPIENT_ — mixed success/failure', function () {
    // Simulate a multi-line response where only one number appears
    $body = "sent:0400000001\nfailed:0400000002\n";
    $fake = new FakeHttpClient(successResponse($body));
    $provider = makeProvider($fake,
        responseOkRegex: 'sent:_RECIPIENT_',
    );

    $recipients = [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')];
    $result = $provider->send(entries('Hello', $recipients), new PhoneNumber('0411111111'));

    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 2);

    $byNum = [];
    foreach ($deliveries as $d) {
        $byNum[$d->recipient()->value] = $d->status();
    }
    assert_eq($byNum['0400000001'], SmsStatus::SENT, '0400000001 should be SENT');
    assert_eq($byNum['0400000002'], SmsStatus::FAILED, '0400000002 should be FAILED (not in OK lines)');
});

test('parseResponse(): per-recipient OK regex — all match → all SENT', function () {
    $body = "OK:0400000001\nOK:0400000002\n";
    $fake = new FakeHttpClient(successResponse($body));
    $provider = makeProvider($fake, responseOkRegex: 'OK:_RECIPIENT_');

    $recipients = [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')];
    $result = $provider->send(entries('Hello', $recipients), new PhoneNumber('0411111111'));

    assert_true($result->isSuccess());
    foreach ($result->getValue()->deliveries as $d) {
        assert_eq($d->status(), SmsStatus::SENT);
    }
});

// ===========================================================================
// parseResponse() — responseIdRegex extracts remoteId
// ===========================================================================

test('parseResponse(): responseIdRegex capture group → remoteId extracted', function () {
    $body = "OK:0400000001 id=MSG123\nOK:0400000002 id=MSG456\n";
    $fake = new FakeHttpClient(successResponse($body));
    $provider = makeProvider($fake,
        responseOkRegex: 'OK:_RECIPIENT_',
        responseIdRegex: 'OK:_RECIPIENT_ id=([A-Z0-9]+)',
    );

    $recipients = [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')];
    $result = $provider->send(entries('Hello', $recipients), new PhoneNumber('0411111111'));

    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    $byNum = [];
    foreach ($deliveries as $d) {
        $byNum[$d->recipient()->value] = $d;
    }
    assert_eq($byNum['0400000001']->remoteId(), 'MSG123', 'remoteId should be extracted for first recipient');
    assert_eq($byNum['0400000002']->remoteId(), 'MSG456', 'remoteId should be extracted for second recipient');
});

test('parseResponse(): responseIdRegex no match → remoteId remains null', function () {
    $body = "OK:0400000001\n";
    $fake = new FakeHttpClient(successResponse($body));
    $provider = makeProvider($fake,
        responseOkRegex: 'OK:_RECIPIENT_',
        responseIdRegex: 'NOMATCH_PATTERN_([0-9]+)',
    );

    $result = $provider->send(entries('Hi', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));

    assert_true($result->isSuccess());
    $d = $result->getValue()->deliveries[0];
    assert_eq($d->remoteId(), null, 'No ID regex match → remoteId should be null');
});

// ===========================================================================
// buildHttpRequest() — placeholder substitution
// ===========================================================================

test('buildHttpRequest(): _MESSAGE_ is URL-encoded in request body', function () {
    $fake = new FakeHttpClient(successResponse('OK'));
    $provider = makeProvider($fake,
        postTemplate: 'message=_MESSAGE_&to=_RECIPIENTS_COMMAS_',
    );

    $provider->send(entries('Hello World & More', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));

    assert_true($fake->lastRequest !== null);
    $body = $fake->lastRequest->body;
    assert_contains($body, 'message=Hello+World+%26+More', 'Message should be URL-encoded in body');
});

test('buildHttpRequest(): _RECIPIENTS_COMMAS_ URL-encoded comma-separated numbers', function () {
    $fake = new FakeHttpClient(successResponse('OK'));
    $provider = makeProvider($fake,
        postTemplate: 'to=_RECIPIENTS_COMMAS_&msg=_MESSAGE_',
    );

    $provider->send(entries('Hi', [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')]), new PhoneNumber('0411111111'));

    $body = $fake->lastRequest->body;
    // URL-encoded comma: %2C
    assert_contains($body, '0400000001', 'First number should be in body');
    assert_contains($body, '0400000002', 'Second number should be in body');
});

test('buildHttpRequest(): _USER_MOBILE_ substituted with sender number', function () {
    $fake = new FakeHttpClient(successResponse('OK'));
    $provider = makeProvider($fake,
        postTemplate: 'from=_USER_MOBILE_&to=_RECIPIENTS_COMMAS_&msg=_MESSAGE_',
    );

    $provider->send(entries('Hi', [new PhoneNumber('0400000001')]), new PhoneNumber('0411234567'));

    $body = $fake->lastRequest->body;
    assert_contains($body, '0411234567', 'Sender number should appear in body via _USER_MOBILE_');
});

test('buildHttpRequest(): international placeholders only substituted when BOTH prefixes set', function () {
    $fake = new FakeHttpClient(successResponse('OK'));

    // Without prefixes — placeholder should remain literal
    $providerNoPrefixes = new TemplateSmsProvider(
        url: 'https://api.test.example/sms',
        postTemplate: 'to=_RECIPIENTS_INTERNATIONAL_COMMAS_&msg=_MESSAGE_',
        localPrefix: '',
        internationalPrefix: '',
        httpClient: $fake,
    );

    $providerNoPrefixes->send(entries('Hi', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));

    $body = $fake->lastRequest->body;
    // When prefixes are absent, _RECIPIENTS_INTERNATIONAL_COMMAS_ stays literal in the body
    assert_contains($body, '_RECIPIENTS_INTERNATIONAL_COMMAS_',
        'International placeholder should remain literal when EITHER prefix is absent (pinning documented quirk)');
});

test('buildHttpRequest(): international placeholders substituted when BOTH prefixes set', function () {
    $fake = new FakeHttpClient(successResponse('OK'));

    $providerWithPrefixes = new TemplateSmsProvider(
        url: 'https://api.test.example/sms',
        postTemplate: 'to=_RECIPIENTS_INTERNATIONAL_COMMAS_&msg=_MESSAGE_',
        localPrefix: '0',
        internationalPrefix: '61',
        httpClient: $fake,
    );

    $providerWithPrefixes->send(entries('Hi', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));

    $body = $fake->lastRequest->body;
    // 0400000001 → 61400000001 when prefixes '0'/'61'
    assert_not_contains($body, '_RECIPIENTS_INTERNATIONAL_COMMAS_',
        'International placeholder should be replaced when both prefixes are set');
    assert_contains($body, '61400000001', 'Internationalised number should appear in body');
});

test('buildHttpRequest(): URL is sent to configured endpoint', function () {
    $fake = new FakeHttpClient(successResponse('OK'));
    $provider = makeProvider($fake);

    $provider->send(entries('Hi', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));

    assert_eq($fake->lastRequest->url, 'https://api.test.example/sms');
    assert_eq($fake->lastRequest->method, 'POST');
});

// ===========================================================================
// Restored 2.39 behaviour: array params, _USER_EMAIL_, mid-batch failures
// ===========================================================================

test('buildHttpRequest(): _RECIPIENTS_ARRAY_ sends literal brackets with encoded values', function () {
    $fake = new FakeHttpClient(successResponse('OK'));
    $provider = new TemplateSmsProvider(
        url: 'https://api.test.example/sms',
        postTemplate: 'to=_RECIPIENTS_ARRAY_&msg=_MESSAGE_',
        recipientArrayParameter: 'number',
        httpClient: $fake,
    );

    $provider->send(entries('Hi', [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')]), new PhoneNumber('0411111111'));

    // 2.39 sent `number[]=0412…&number[]=0413…`, not one URL-encoded parameter.
    assert_contains($fake->lastRequest->body, 'number[]=0400000001&number[]=0400000002');
});

test('buildHttpRequest(): _USER_EMAIL_ substituted when the email is supplied', function () {
    $fake = new FakeHttpClient(successResponse('OK'));
    $provider = (new TemplateSmsProvider(
        url: 'https://api.test.example/sms',
        postTemplate: 'from=_USER_MOBILE_&email=_USER_EMAIL_&msg=_MESSAGE_',
        httpClient: $fake,
    ))->withUserEmail('staff@example.org');

    $provider->send(entries('Hi', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));

    assert_contains($fake->lastRequest->body, 'staff%40example.org');
    assert_not_contains($fake->lastRequest->body, '_USER_EMAIL_');
});

test('send(): _USER_EMAIL_ without a current user fails the send', function () {
    $fake = new FakeHttpClient(successResponse('OK'));
    $provider = new TemplateSmsProvider(
        url: 'https://api.test.example/sms',
        postTemplate: 'email=_USER_EMAIL_&to=_RECIPIENTS_COMMAS_',
        httpClient: $fake,
    );

    $result = $provider->send(entries('Hi', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));
    assert_true($result->isFailure(), 'a missing user email must fail the send');
    assert_contains($result->getError(), '_USER_EMAIL_', 'the failure names the offending keyword');
    assert_eq($fake->lastRequest, null, 'nothing should reach the gateway');
});

test('send(): a failure after an accepted entry keeps both in the batch', function () {
    // First entry succeeds, second request fails at the transport level.
    $fake = new class implements HttpClient {
        public int $calls = 0;
        public function send(HttpRequest $request): \Result
        {
            $this->calls++;
            return $this->calls === 1
                ? \Result::success(new HttpResponse('OK'))
                : \Result::failure('connection reset');
        }
    };
    $provider = new TemplateSmsProvider(
        url: 'https://api.test.example/sms',
        postTemplate: 'to=_RECIPIENTS_COMMAS_&msg=_MESSAGE_',
        httpClient: $fake,
    );

    $first  = [new PhoneNumber('0400000001')];
    $second = [new PhoneNumber('0400000002')];
    $result = $provider->send(
        [['message' => 'one', 'recipients' => $first], ['message' => 'two', 'recipients' => $second]],
        new PhoneNumber('0411111111'),
    );

    assert_true($result->isSuccess(), 'accepted deliveries must not be thrown away');
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 2, 'one delivery per recipient, with the failed one marked');
    $statuses = array_map(fn($d) => $d->status(), $deliveries);
    assert_eq($statuses[0], SmsStatus::SENT, 'first entry stayed accepted');
    assert_eq($statuses[1], SmsStatus::UNKNOWN, 'failed request may have reached the gateway');
});
