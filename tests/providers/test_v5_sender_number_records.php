<?php

/**
 * Unit tests for FiveCentSmsV5Provider sender-number records and deletion.
 *
 * Covers:
 *   - getSenderNumberRecords(): live list shape → SenderNumberRecord with
 *     status, record id, and the registration date derived from the record
 *     id (Mongo ObjectId: first 8 hex chars = unix seconds); plain-string
 *     items; alphanumeric sender IDs; non-ObjectId ids → null date
 *   - deleteSenderNumber(): resolve-then-delete (GET list → DELETE by record
 *     id with credentials in the body), unknown number fails without a
 *     DELETE call, upstream error / invalid JSON surface as failures
 *   - cache invalidation ('sms_senderids') after a successful delete
 *   - DELETE_SENDER_NUMBER capability declared
 *
 * Pins the contract documented in
 * docs/reference/_5centsmsv5/senderid/{GET,DELETE}.md.
 */

namespace Test\Sms\V5SenderRecords;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_contains};
use \Sms\{PhoneNumber, HttpClient, HttpRequest, HttpResponse};
use \Sms\SenderNumberRecord;
use Sms\Providers\FiveCentSmsV5Provider;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * Fake HttpClient answering from a queued list of Results, recording every
 * request.  The delete flow makes two calls (GET list, then DELETE), so a
 * single fixed response is not enough.
 */
final class QueueHttpClient implements HttpClient
{
    /** @var HttpRequest[] */
    public array $requests = [];

    /** @var \Result[] */
    private array $queue;

    public function __construct(\Result ...$responses)
    {
        $this->queue = array_values($responses);
    }

    public function send(HttpRequest $request): \Result
    {
        $this->requests[] = $request;
        if ($this->queue === []) {
            throw new \RuntimeException('No queued response for ' . $request->method . ' ' . $request->url);
        }
        return array_shift($this->queue);
    }
}

/**
 * Fake SmsCache — in-memory, no TTL (same shape as test_v5_parsing.php).
 */
final class FakeSmsCache implements \Sms\SmsCache
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->store[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeProvider(HttpClient $http, ?\Sms\SmsCache $cache = null): FiveCentSmsV5Provider
{
    return new FiveCentSmsV5Provider(
        url: 'https://api.test.example/v5',
        keyId: 'k',
        keySecret: 's',
        httpClient: $http,
        cache: $cache,
    );
}

function successResponse(string $body): \Result
{
    return \Result::success(new HttpResponse($body));
}

/**
 * The live GET /senderid shape (recorded 2026-10, trimmed to one of each
 * kind: alphanumeric ACMA-approved, pending number, unverified number,
 * plain-string legacy item).
 */
function listBody(): string
{
    return json_encode([
        'error' => '',
        'senderids' => [
            ['senderid' => 'StBarnabas', 'status' => 'acma_approved', 'id' => '6ac5fb44184ad2699b09e485', 'shared' => false],
            ['senderid' => '61491570313', 'status' => 'pending', 'id' => '67ff4a9ab78dd1b7590c22a4', 'shared' => false],
            ['senderid' => '61491570737', 'status' => 'unverified', 'id' => '6ad168bb4fd1e7c1240bbaec', 'shared' => false],
            '61499999999',
        ],
    ]);
}

// ===========================================================================
// getSenderNumberRecords()
// ===========================================================================

test('getSenderNumberRecords(): maps live list shape with status, record id and derived date', function () {
    $provider = makeProvider(new QueueHttpClient(successResponse(listBody())));

    $result = $provider->getSenderNumberRecords();
    assert_true($result->isSuccess(), 'Expected success Result');
    $records = $result->getValue();
    assert_eq(count($records), 4, 'All four list items should map to records');

    $byValue = [];
    foreach ($records as $r) {
        $byValue[$r->value] = $r;
    }

    $pending = $byValue['61491570313'];
    assert_eq($pending->status, 'pending', 'Pending number should carry its status');
    assert_eq($pending->recordId, '67ff4a9ab78dd1b7590c22a4', 'Record id should be carried through');
    assert_eq($pending->registeredAt, 1744784026, 'Date should be derived from the record id timestamp');
    assert_false($pending->isApproved(), 'A pending record is not approved');
    assert_true($pending->isPhoneNumber(), 'A number record is a phone number');

    $acma = $byValue['StBarnabas'];
    assert_eq($acma->status, 'acma_approved', 'ACMA status should be carried through');
    assert_true($acma->isApproved(), 'acma_approved counts as approved');
    assert_false($acma->isPhoneNumber(), 'Alphanumeric IDs are not phone numbers');

    $unverified = $byValue['61491570737'];
    assert_eq($unverified->registeredAt, 1792108731, 'Unverified number date derives from its id');

    $legacy = $byValue['61499999999'];
    assert_eq($legacy->status, null, 'Plain-string items have no status');
    assert_eq($legacy->recordId, null, 'Plain-string items have no record id');
    assert_eq($legacy->registeredAt, null, 'Plain-string items have no date');
});

test('getSenderNumberRecords(): upstream error surfaces as failure', function () {
    $provider = makeProvider(new QueueHttpClient(successResponse(json_encode(['error' => 'Bad key']))));

    $result = $provider->getSenderNumberRecords();
    assert_true($result->isFailure(), 'API-level error should fail, not return an empty list');
    assert_contains($result->getError(), 'Bad key');
});

test('getSenderNumberRecords(): non-ObjectId record id yields null date', function () {
    $body = json_encode(['error' => '', 'senderids' => [
        ['senderid' => '61430000000', 'status' => 'approved', 'id' => 'not-an-objectid'],
    ]]);
    $provider = makeProvider(new QueueHttpClient(successResponse($body)));

    $records = $provider->getSenderNumberRecords()->getValue();
    assert_eq(count($records), 1, 'Record should still map');
    assert_eq($records[0]->registeredAt, null, 'Non-ObjectId id has no derivable date');
});

// ===========================================================================
// deleteSenderNumber()
// ===========================================================================

test('deleteSenderNumber(): resolves the record id, deletes by it, invalidates the cache', function () {
    $cache = new FakeSmsCache();
    $cache->set('sms_senderids', [['value' => '61491570313', 'acmaApproved' => false]]);
    $http = new QueueHttpClient(
        successResponse(listBody()),                     // GET /senderid
        successResponse(json_encode(['error' => ''])),   // DELETE /senderid/{id}
    );
    $provider = makeProvider($http, $cache);

    $result = $provider->deleteSenderNumber(new PhoneNumber('0491570313'));

    assert_true($result->isSuccess(), 'Expected success Result: ' . ($result->isFailure() ? $result->getError() : ''));
    assert_eq($result->getValue(), true, 'Success value should be true');

    assert_eq(count($http->requests), 2, 'Exactly one GET and one DELETE');
    [$listReq, $deleteReq] = $http->requests;

    assert_eq($listReq->method, 'GET', 'First call lists the account');
    assert_eq($listReq->url, 'https://api.test.example/v5/senderid', 'List hits GET /senderid');

    assert_eq($deleteReq->method, 'DELETE', 'Second call deletes');
    assert_eq($deleteReq->url, 'https://api.test.example/v5/senderid/67ff4a9ab78dd1b7590c22a4', 'DELETE keys on the record id');
    $body = json_decode($deleteReq->body, true);
    assert_eq($body['key-id'] ?? null, 'k', 'Credentials ride in the DELETE body (key-id)');
    assert_eq($body['key-secret'] ?? null, 's', 'Credentials ride in the DELETE body (key-secret)');

    assert_true($cache->get('sms_senderids') === null, 'Sender cache should be invalidated after delete');
});

test('deleteSenderNumber(): local-form input matches the listed international number', function () {
    $http = new QueueHttpClient(
        successResponse(listBody()),
        successResponse(json_encode(['error' => ''])),
    );
    $provider = makeProvider($http);

    // Listed as 61491570313; requested as the local form without the prefix
    $result = $provider->deleteSenderNumber(new PhoneNumber('491570313'));
    assert_true($result->isSuccess(), 'sameNumberAs matching should find the listed record');
});

test('deleteSenderNumber(): unknown number fails without a DELETE call', function () {
    $http = new QueueHttpClient(successResponse(listBody()));
    $provider = makeProvider($http);

    $result = $provider->deleteSenderNumber(new PhoneNumber('0412345678'));

    assert_true($result->isFailure(), 'Unknown number should fail');
    assert_contains($result->getError(), 'not registered');
    assert_eq(count($http->requests), 1, 'No DELETE should be sent when the number is absent');
});

test('deleteSenderNumber(): alphanumeric sender IDs are not deleted as numbers', function () {
    $http = new QueueHttpClient(successResponse(listBody()));
    $provider = makeProvider($http);

    $result = $provider->deleteSenderNumber(new PhoneNumber('0412345678'));
    assert_true($result->isFailure(), 'Only digit-valued records match a PhoneNumber');
    assert_contains($result->getError(), 'not registered');
});

test('deleteSenderNumber(): upstream error surfaces as failure', function () {
    $http = new QueueHttpClient(
        successResponse(listBody()),
        successResponse(json_encode(['error' => 'Sender ID not found or already deleted.'])),
    );
    $provider = makeProvider($http);

    $result = $provider->deleteSenderNumber(new PhoneNumber('0491570313'));
    assert_true($result->isFailure(), 'Non-empty upstream error is a hard failure');
    assert_contains($result->getError(), 'Sender ID not found');
});

test('deleteSenderNumber(): invalid JSON on delete response fails', function () {
    $http = new QueueHttpClient(
        successResponse(listBody()),
        successResponse('not-json'),
    );
    $provider = makeProvider($http);

    $result = $provider->deleteSenderNumber(new PhoneNumber('0491570313'));
    assert_true($result->isFailure(), 'Invalid JSON should fail');
    assert_contains($result->getError(), 'Invalid JSON');
});

// ===========================================================================
// Capability
// ===========================================================================

test('DELETE_SENDER_NUMBER capability is declared', function () {
    $provider = makeProvider(new QueueHttpClient());
    assert_true($provider->hasCapability(\Sms\SmsCapability::DELETE_SENDER_NUMBER), '5CentSMS v5 can delete sender numbers');
});
