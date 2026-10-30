<?php

/**
 * Unit tests for MobileMessageSmsProvider — send(), balance, senders,
 * delivery updates, cancel, opt-outs, history, and capability surface.
 *
 * All tests use injected FakeHttpClient / SpyHttpClient — no network, no
 * PHP constants.
 */

namespace Test\Sms\MobileMessage\SendParsing;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_contains, assert_throws};
use \Sms\{
    PhoneNumber, SenderID,
    SmsDelivery, SmsStatus,
    HttpClient, HttpRequest, HttpResponse};
use Sms\Providers\MobileMessageSmsProvider;
use Sms\Providers\MobileMessageSmsDelivery;

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

/**
 * In-memory SmsCache stub.
 */
final class ArrayCache implements \Sms\SmsCache
{
    public array $store = [];
    public array $deleted = [];

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
        $this->deleted[] = $key;
        unset($this->store[$key]);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeProvider(HttpClient $http, ?\Sms\SmsCache $cache = null, bool $unicodePermitted = true): MobileMessageSmsProvider
{
    return new MobileMessageSmsProvider(
        username: 'testuser',
        password: 'testpass',
        url: 'https://api.test.example',
        unicodePermitted: $unicodePermitted,
        httpClient: $http,
        cache: $cache,
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

function recipient(string $number): PhoneNumber
{
    return new PhoneNumber($number);
}

/**
 * A typical successful per-message result item.
 */
function resultItem(string $messageId, int $cost = 1): array
{
    return ['status' => 'success', 'cost' => $cost, 'message_id' => $messageId, 'encoding' => 'gsm7'];
}

// ---------------------------------------------------------------------------
// MobileMessageSmsDelivery — construction from send-response items
// ---------------------------------------------------------------------------

test('MobileMessageSmsDelivery: success → SENT with remoteId and segments', function () {
    $d = new MobileMessageSmsDelivery(recipient('0400000001'), resultItem('abc-uuid', 2));
    assert_eq($d->status(), SmsStatus::SENT);
    assert_eq($d->remoteId(), 'abc-uuid');
    assert_eq($d->segmentsUsed(), 2);
});

test('MobileMessageSmsDelivery: success with scheduled_for → SCHEDULED, send time parsed as UTC', function () {
    // Recorded live: a +65s scheduled send still reports status "success".
    $d = new MobileMessageSmsDelivery(recipient('0400000001'), [
        'to' => '61400000001', 'status' => 'success', 'cost' => 1, 'message_id' => 'd5f37219',
        'scheduled_for' => '2026-10-17 11:00:53', 'encoding' => 'ucs2',
    ]);
    assert_eq($d->status(), SmsStatus::SCHEDULED, 'a held message must be SCHEDULED so the cool-off Cancel link shows');
    assert_eq($d->sendTimestamp(), gmmktime(11, 0, 53, 10, 17, 2026), 'API times carry no zone and are UTC');
});

test('MobileMessageSmsDelivery: success with scheduled_for null → SENT', function () {
    // Recorded live: a schedule under 60s ahead is silently sent at once.
    $d = new MobileMessageSmsDelivery(recipient('0400000001'), [
        'to' => '61400000001', 'status' => 'success', 'cost' => 1, 'message_id' => '8fcc051c', 'scheduled_for' => null,
    ]);
    assert_eq($d->status(), SmsStatus::SENT);
    assert_eq($d->sendTimestamp(), null);
});

test('MobileMessageSmsDelivery: error → FAILED with error in statusDetail', function () {
    $d = new MobileMessageSmsDelivery(recipient('0400000001'), ['status' => 'error', 'error' => 'Invalid sender']);
    assert_eq($d->status(), SmsStatus::FAILED);
    assert_eq($d->statusDetail(), 'Invalid sender');
    assert_eq($d->remoteId(), null);
});

test('MobileMessageSmsDelivery: blocked → OPTED_OUT (number unsubscribed upstream)', function () {
    $d = new MobileMessageSmsDelivery(recipient('0400000001'), ['status' => 'blocked']);
    assert_eq($d->status(), SmsStatus::OPTED_OUT);
});

test('MobileMessageSmsDelivery: testMode → TEST_MESSAGE (final status)', function () {
    $d = new MobileMessageSmsDelivery(recipient('0400000001'), array_merge(resultItem('fake_1'), ['testMode' => true]));
    assert_eq($d->status(), SmsStatus::TEST_MESSAGE);
});

// ---------------------------------------------------------------------------
// send()
// ---------------------------------------------------------------------------

test('send(): one message per recipient, results paired positionally', function () {
    $body = json_encode([
        'status' => 'complete',
        'total_cost' => 2,
        'results' => [resultItem('uuid-1'), resultItem('uuid-2', 3)],
    ]);
    $spy = new SpyHttpClient(successResponse($body));
    $p = makeProvider($spy);
    $r1 = recipient('0400000001');
    $r2 = recipient('0400000002');
    $result = $p->send([['message' => 'Hello', 'recipients' => [$r1, $r2]]], new SenderID('TestChurch'));
    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 2);
    assert_eq($deliveries[0]->remoteId(), 'uuid-1');
    assert_eq($deliveries[0]->status(), SmsStatus::SENT);
    assert_eq($deliveries[1]->remoteId(), 'uuid-2');
    assert_eq($deliveries[1]->segmentsUsed(), 3);

    // Request body: one MessageInput per recipient with the sender attached
    $sent = json_decode($spy->lastRequest->body, true);
    assert_eq(count($sent['messages']), 2);
    assert_eq($sent['messages'][0]['to'], '0400000001');
    assert_eq($sent['messages'][1]['to'], '0400000002');
    assert_eq($sent['messages'][0]['sender'], 'TestChurch');
    assert_eq($sent['messages'][0]['message'], 'Hello');
    assert_true($sent['enable_unicode'], 'unicode permitted by default');
});

test('send(): missing result for a recipient → FAILED placeholder', function () {
    $body = json_encode(['status' => 'complete', 'results' => [resultItem('uuid-1')]]);
    $p = makeProvider(new FakeHttpClient(successResponse($body)));
    $result = $p->send([['message' => 'Hi', 'recipients' => [recipient('0400000001'), recipient('0400000002')]]], new SenderID('X'));
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 2);
    assert_eq($deliveries[1]->status(), SmsStatus::FAILED);
});

test('send(): blocked result surfaces as OPTED_OUT delivery', function () {
    $body = json_encode(['status' => 'complete', 'results' => [['status' => 'blocked']]]);
    $p = makeProvider(new FakeHttpClient(successResponse($body)));
    $result = $p->send([['message' => 'Hi', 'recipients' => [recipient('0400000001')]]], new SenderID('X'));
    assert_eq($result->getValue()->deliveries[0]->status(), SmsStatus::OPTED_OUT);
});

test('send(): every delivery carries its entry\'s message (logged as _smsdelivery.body)', function () {
    $body = json_encode(['status' => 'complete', 'results' => [resultItem('uuid-1')]]);
    $p = makeProvider(new FakeHttpClient(successResponse($body)));
    $result = $p->send([
        ['message' => 'Hi Ann', 'recipients' => [recipient('0400000001')]],
        ['message' => 'Hi Bob', 'recipients' => [recipient('0400000002')]],
    ], new SenderID('X'));
    $deliveries = $result->getValue()->deliveries;
    assert_eq($deliveries[0]->message(), 'Hi Ann');
    assert_eq($deliveries[1]->message(), 'Hi Bob', 'the FAILED placeholder carries the message too');
});

test('send(): a blocked result invalidates the cached opt-out list', function () {
    $cache = new ArrayCache();
    $body = json_encode(['status' => 'complete', 'results' => [['status' => 'blocked']]]);
    $p = makeProvider(new FakeHttpClient(successResponse($body)), $cache);
    $p->send([['message' => 'Hi', 'recipients' => [recipient('0400000001')]]], new SenderID('X'));
    assert_true(in_array('sms_optouts', $cache->deleted, true));
});

test('send(): API error envelope (e.g. insufficient credits) → failure Result', function () {
    $p = makeProvider(new FakeHttpClient(successResponse(json_encode(['error' => 'Insufficient credits']))));
    $result = $p->send([['message' => 'Hi', 'recipients' => [recipient('0400000001')]]], new SenderID('X'));
    assert_true($result->isFailure());
    assert_contains($result->getError(), 'Insufficient credits');
});

test('send(): transport failure → failure Result', function () {
    $p = makeProvider(new FakeHttpClient(failureResponse('cURL error 28: timeout')));
    $result = $p->send([['message' => 'Hi', 'recipients' => [recipient('0400000001')]]], new SenderID('X'));
    assert_true($result->isFailure());
    assert_contains($result->getError(), 'timeout');
});

test('send(): unicode disallowed → enable_unicode false in request body', function () {
    $spy = new SpyHttpClient(successResponse(json_encode(['status' => 'complete', 'results' => [resultItem('u1')]])));
    $p = makeProvider($spy, unicodePermitted: false);
    $p->send([['message' => 'Hi', 'recipients' => [recipient('0400000001')]]], new SenderID('X'));
    $sent = json_decode($spy->lastRequest->body, true);
    assert_false($sent['enable_unicode']);
});

test('send(): deferred sendAt is clamped well past the 60-second API minimum', function () {
    $spy = new SpyHttpClient(successResponse(json_encode(['status' => 'complete', 'results' => [resultItem('u1')]])));
    $p = makeProvider($spy);
    $before = time();
    $p->send([['message' => 'Hi', 'recipients' => [recipient('0400000001')]]], new SenderID('X'), $before + 30);
    $sent = json_decode($spy->lastRequest->body, true);
    assert_true(isset($sent['messages'][0]['scheduled_for']), 'scheduled_for must be set');
    $scheduledTs = strtotime($sent['messages'][0]['scheduled_for']);
    // 90 s, not 61: the API measures the lead on arrival, so a tight margin can
    // be swallowed by network latency and silently downgraded.
    assert_true($scheduledTs >= $before + 90, 'scheduled_for must be ≥90s ahead, got ' . ($scheduledTs - $before) . 's');
    assert_contains($sent['messages'][0]['scheduled_for'], 'Z');
});

test('send(): immediate send carries no scheduled_for', function () {
    $spy = new SpyHttpClient(successResponse(json_encode(['status' => 'complete', 'results' => [resultItem('u1')]])));
    $p = makeProvider($spy);
    $p->send([['message' => 'Hi', 'recipients' => [recipient('0400000001')]]], new SenderID('X'));
    $sent = json_decode($spy->lastRequest->body, true);
    assert_false(isset($sent['messages'][0]['scheduled_for']));
});

test('send(): preview makes no HTTP call and returns QUEUED with message set', function () {
    // A failing client would break any real HTTP attempt.
    $p = makeProvider(new FakeHttpClient(failureResponse('no network in preview')));
    $result = $p->send([['message' => 'Hi', 'recipients' => [recipient('0400000001')]]], new SenderID('X'), null, true);
    assert_true($result->isSuccess());
    $d = $result->getValue()->deliveries[0];
    assert_eq($d->status(), SmsStatus::QUEUED);
    assert_eq($d->message(), 'Hi');
    assert_eq($d->remoteId(), null);
});

test('send(): invalidates cached balance', function () {
    $cache = new ArrayCache();
    $cache->set('sms_balance', 42);
    $p = makeProvider(new FakeHttpClient(successResponse(json_encode(['status' => 'complete', 'results' => [resultItem('u1')]]))), $cache);
    $p->send([['message' => 'Hi', 'recipients' => [recipient('0400000001')]]], new SenderID('X'));
    assert_true(in_array('sms_balance', $cache->deleted, true), 'sms_balance must be deleted after a send');
});

// ---------------------------------------------------------------------------
// getBalance()
// ---------------------------------------------------------------------------

test('getBalance(): parses credit_balance and caches it', function () {
    $cache = new ArrayCache();
    $p = makeProvider(new FakeHttpClient(successResponse(json_encode(['credit_balance' => 1234, 'credit_price' => '0.049']))), $cache);
    $result = $p->getBalance();
    assert_true($result->isSuccess());
    assert_eq($result->getValue(), 1234);
    assert_eq($cache->store['sms_balance'], 1234, 'balance must be cached');
});

test('getBalance(): cached value short-circuits the API', function () {
    $cache = new ArrayCache();
    $cache->set('sms_balance', 7);
    // A failing client would break a real API call.
    $p = makeProvider(new FakeHttpClient(failureResponse('should not be called')), $cache);
    $result = $p->getBalance();
    assert_true($result->isSuccess());
    assert_eq($result->getValue(), 7);
});

// ---------------------------------------------------------------------------
// getSenderIds() / getSenderNumbers() / verifySenderNumber()
// ---------------------------------------------------------------------------

/**
 * Client that pages through a fixed sender list, honouring limit/offset.
 */
final class PagingSendersHttpClient implements HttpClient
{
    public function __construct(
        private array $senders,
    ) {}

    public function send(HttpRequest $request): \Result
    {
        parse_str(parse_url($request->url, PHP_URL_QUERY), $q);
        $limit = (int) ($q['limit'] ?? 200);
        $offset = (int) ($q['offset'] ?? 0);
        $page = array_slice($this->senders, $offset, $limit);
        return successResponse(json_encode([
            'total' => count($this->senders),
            'limit' => $limit,
            'offset' => $offset,
            'results' => $page,
        ]));
    }
}

function sendersPayload(): array
{
    return [
        ['sender' => 'StBarnabas', 'type' => 'alpha', 'label' => 'Church name', 'is_default' => false],
        ['sender' => '61400000001', 'type' => 'own', 'label' => 'Office mobile', 'is_default' => false],
        ['sender' => '61400000002', 'type' => 'shared', 'label' => 'Shared', 'is_default' => true],
        ['sender' => 'INVALID!!', 'type' => 'own', 'label' => 'Junk', 'is_default' => false],
    ];
}

test('getSenderIds(): returns only alphanumeric senders, ACMA status unknown (null)', function () {
    $p = makeProvider(new PagingSendersHttpClient(sendersPayload()));
    $result = $p->getSenderIds(getAll: true);
    assert_true($result->isSuccess());
    $ids = $result->getValue();
    assert_eq(count($ids), 1);
    assert_eq($ids[0]->value, 'StBarnabas');
    assert_eq($ids[0]->acmaApproved, null);
});

test('getSenderNumbers(): returns numeric senders (own/shared), skipping junk', function () {
    $p = makeProvider(new PagingSendersHttpClient(sendersPayload()));
    $result = $p->getSenderNumbers();
    assert_true($result->isSuccess());
    $numbers = array_map(fn(PhoneNumber $n) => $n->value, $result->getValue());
    assert_eq($numbers, ['61400000001', '61400000002']);
});

test('getSenderIds(): senders list is cached across calls', function () {
    $cache = new ArrayCache();
    $p = makeProvider(new PagingSendersHttpClient(sendersPayload()), $cache);
    $p->getSenderIds();
    assert_true(isset($cache->store['sms_senders']), 'senders must be cached');
});

test('verifySenderNumber(): matches local form against international registered numbers', function () {
    $p = makeProvider(new PagingSendersHttpClient(sendersPayload()));
    $result = $p->verifySenderNumber(new PhoneNumber('0400000001'));
    assert_true($result->isSuccess());
    assert_true($result->getValue());
    $result2 = $p->verifySenderNumber(new PhoneNumber('0411111111'));
    assert_false($result2->getValue());
});

// ---------------------------------------------------------------------------
// updateDelivery()
// ---------------------------------------------------------------------------

/**
 * A GET /v1/messages?message_id= reply, shaped as recorded from the live API:
 * the message is wrapped in results[], and the envelope status is "complete".
 */
function lookupResponse(array $overrides = []): string
{
    return json_encode(['status' => 'complete', 'results' => [array_merge([
        'to' => '61400000001', 'message' => 'Hi', 'sender' => '61400000001', 'custom_ref' => null,
        'status' => 'delivered', 'cost' => '1.00', 'message_id' => 'msg-uuid',
        'requested_at' => '2026-10-17 10:59:49', 'scheduled_for' => null,
    ], $overrides)]]);
}

test('updateDelivery(): delivered lookup → DELIVERED, cost as segments, UTC send time', function () {
    $spy = new SpyHttpClient(successResponse(lookupResponse(['cost' => '2.00'])));
    $p = makeProvider($spy);
    $delivery = new SmsDelivery(recipient: recipient('0400000001'), status: SmsStatus::SENT, remoteId: 'msg-uuid');
    $result = $p->updateDelivery($delivery);
    assert_true($result->isSuccess());
    $updated = $result->getValue();
    assert_eq($updated->status(), SmsStatus::DELIVERED, 'status comes from results[0], not the "complete" envelope');
    assert_eq($updated->segmentsUsed(), 2);
    assert_eq($updated->remoteId(), 'msg-uuid');
    assert_eq($updated->sendTimestamp(), gmmktime(10, 59, 49, 10, 17, 2026));
    assert_contains($spy->lastRequest->url, 'message_id=msg-uuid');
});

test('updateDelivery(): maps every API status', function () {
    $cases = [
        'pending' => SmsStatus::QUEUED,
        'scheduled' => SmsStatus::SCHEDULED,
        'sent' => SmsStatus::SENT,
        'delivered' => SmsStatus::DELIVERED,
        'failed' => SmsStatus::FAILED,
        'cancelled' => SmsStatus::CANCELLED,
    ];
    foreach ($cases as $api => $expected) {
        $p = makeProvider(new FakeHttpClient(successResponse(lookupResponse(['status' => $api]))));
        $delivery = new SmsDelivery(recipient: recipient('0400000001'), status: SmsStatus::SENT, remoteId: 'u');
        $result = $p->updateDelivery($delivery);
        assert_eq($result->getValue()->status(), $expected, "status '$api' must map to {$expected->value}");
    }
});

test('updateDelivery(): scheduled message reports its schedule time as the send time', function () {
    $p = makeProvider(new FakeHttpClient(successResponse(lookupResponse(['status' => 'scheduled', 'scheduled_for' => '2026-10-17 11:00:53']))));
    $delivery = new SmsDelivery(recipient: recipient('0400000001'), status: SmsStatus::SCHEDULED, remoteId: 'u');
    assert_eq($p->updateDelivery($delivery)->getValue()->sendTimestamp(), gmmktime(11, 0, 53, 10, 17, 2026));
});

test('updateDelivery(): 404 envelope → failure carrying the API message', function () {
    $p = makeProvider(new FakeHttpClient(successResponse(json_encode(['error' => 'No message(s) found with the given ID or reference.']))));
    $delivery = new SmsDelivery(recipient: recipient('0400000001'), status: SmsStatus::SENT, remoteId: 'gone');
    $result = $p->updateDelivery($delivery);
    assert_true($result->isFailure());
    assert_contains($result->getError(), 'No message(s) found');
});

test('updateDelivery(): no remote ID → failure without HTTP call', function () {
    $p = makeProvider(new FakeHttpClient(failureResponse('should not be called')));
    $delivery = new SmsDelivery(recipient: recipient('0400000001'), status: SmsStatus::SENT);
    $result = $p->updateDelivery($delivery);
    assert_true($result->isFailure());
    assert_contains($result->getError(), 'No remote ID');
});

// ---------------------------------------------------------------------------
// cancel()
// ---------------------------------------------------------------------------

test('cancel(): DELETE with message_id in a JSON body → CANCELLED with cost zeroed', function () {
    // Recorded live; the API ignores query parameters on DELETE.
    $spy = new SpyHttpClient(successResponse(json_encode(
        ['status' => 'complete', 'message_id' => 'u1', 'cancelled' => true, 'credits_refunded' => 1])));
    $p = makeProvider($spy);
    $delivery = new SmsDelivery(recipient: recipient('0400000001'), status: SmsStatus::SCHEDULED, remoteId: 'u1', segmentsUsed: 2);
    $result = $p->cancel(new \Sms\SmsDeliveryBatch(null, [$delivery]));
    assert_true($result->isSuccess());
    $cancelled = $result->getValue()->deliveries[0];
    assert_eq($cancelled->status(), SmsStatus::CANCELLED);
    assert_eq($cancelled->segmentsUsed(), 0);
    assert_eq($spy->lastRequest->method, 'DELETE');
    assert_eq($spy->lastRequest->url, 'https://api.test.example/v1/messages', 'no query string');
    assert_eq(json_decode($spy->lastRequest->body, true), ['message_id' => 'u1']);
});

test('cancel(): reply without cancelled:true keeps status and reports why', function () {
    $p = makeProvider(new FakeHttpClient(successResponse(json_encode(['status' => 'complete', 'cancelled' => false]))));
    $delivery = new SmsDelivery(recipient: recipient('0400000001'), status: SmsStatus::SCHEDULED, remoteId: 'u1');
    $kept = $p->cancel(new \Sms\SmsDeliveryBatch(null, [$delivery]))->getValue()->deliveries[0];
    assert_eq($kept->status(), SmsStatus::SCHEDULED);
    assert_contains((string) $kept->statusDetail(), 'not confirmed');
});

test('cancel(): refusal ("not in a scheduled state") keeps status and reports reason', function () {
    $p = makeProvider(new FakeHttpClient(successResponse(json_encode(['error' => 'Message is not in a scheduled state.']))));
    $delivery = new SmsDelivery(recipient: recipient('0400000001'), status: SmsStatus::SENT, remoteId: 'u1');
    $result = $p->cancel(new \Sms\SmsDeliveryBatch(null, [$delivery]));
    assert_true($result->isSuccess(), 'refusals are per-delivery outcomes, not operation failures');
    $kept = $result->getValue()->deliveries[0];
    assert_eq($kept->status(), SmsStatus::SENT);
    assert_contains((string) $kept->statusDetail(), 'not in a scheduled state');
});

test('cancel(): no remote ID → unchanged with statusDetail', function () {
    $p = makeProvider(new FakeHttpClient(failureResponse('should not be called')));
    $delivery = new SmsDelivery(recipient: recipient('0400000001'), status: SmsStatus::SCHEDULED);
    $result = $p->cancel(new \Sms\SmsDeliveryBatch(null, [$delivery]));
    $kept = $result->getValue()->deliveries[0];
    assert_eq($kept->status(), SmsStatus::SCHEDULED);
    assert_contains((string) $kept->statusDetail(), 'no remote message ID');
});

// ---------------------------------------------------------------------------
// listOptOuts() / removeOptOut()
// ---------------------------------------------------------------------------

final class PagingUnsubscribesHttpClient implements HttpClient
{
    public function __construct(
        private array $numbers,
        private bool $withTotal = true,
    ) {}

    public function send(HttpRequest $request): \Result
    {
        parse_str(parse_url($request->url, PHP_URL_QUERY), $q);
        $limit = (int) ($q['limit'] ?? 200);
        $offset = (int) ($q['offset'] ?? 0);
        $page = array_map(
            fn(string $n) => ['number' => $n, 'updated_at' => '2026-01-01T00:00:00Z'],
            array_slice($this->numbers, $offset, $limit),
        );
        $body = [
            'limit' => $limit,
            'offset' => $offset,
            'results' => $page,
        ];
        if ($this->withTotal) {
            $body['total'] = count($this->numbers);
        }
        return successResponse(json_encode($body));
    }
}

test('listOptOuts(): pages the unsubscribes list and caches it', function () {
    $cache = new ArrayCache();
    $numbers = array_map(fn($i) => '04000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 250));
    $p = makeProvider(new PagingUnsubscribesHttpClient($numbers), $cache);
    $result = $p->listOptOuts();
    assert_true($result->isSuccess());
    assert_eq(count($result->getValue()), 250, 'all pages must be fetched (200 + 50)');
    assert_eq(count($cache->store['sms_optouts']), 250, 'opt-outs cached as primitive arrays');

    // A reply without `total` must not stop paging after a full first page.
    $p = makeProvider(new PagingUnsubscribesHttpClient($numbers, withTotal: false), new ArrayCache());
    assert_eq(count($p->listOptOuts()->getValue()), 250);
});

test('listOptOuts(): cached value short-circuits the API', function () {
    $cache = new ArrayCache();
    $cache->set('sms_optouts', [['number' => '0400000001']]);
    $p = makeProvider(new FakeHttpClient(failureResponse('should not be called')), $cache);
    $result = $p->listOptOuts();
    assert_eq(count($result->getValue()), 1);
});

test('removeOptOut(): DELETE with number in a JSON body, returns true, invalidates the cache', function () {
    $cache = new ArrayCache();
    $cache->set('sms_optouts', [['number' => '0400000001']]);
    // Recorded live (removed is false when the number wasn't on the list).
    $spy = new SpyHttpClient(successResponse(json_encode(['status' => 'complete', 'number' => '61400000001', 'removed' => true])));
    $p = makeProvider($spy, $cache);
    $result = $p->removeOptOut(new PhoneNumber('0400000001'));
    assert_true($result->isSuccess());
    assert_true($result->getValue());
    assert_eq($spy->lastRequest->method, 'DELETE');
    assert_eq($spy->lastRequest->url, 'https://api.test.example/v1/unsubscribes', 'no query string');
    assert_eq(json_decode($spy->lastRequest->body, true), ['number' => '0400000001']);
    assert_true(in_array('sms_optouts', $cache->deleted, true));
});

// ---------------------------------------------------------------------------
// listRecentDeliveries()
// ---------------------------------------------------------------------------

final class HistoryHttpClient implements HttpClient
{
    public function __construct(
        private array $messages,
        private bool $withTotal = true,
    ) {}

    public function send(HttpRequest $request): \Result
    {
        parse_str(parse_url($request->url, PHP_URL_QUERY), $q);
        assert_true(isset($q['from'], $q['to']), 'history queries must carry day filters');
        $page = [
            'limit' => (int) ($q['limit'] ?? 200),
            'offset' => (int) ($q['offset'] ?? 0),
            'results' => array_slice($this->messages, (int) ($q['offset'] ?? 0), (int) ($q['limit'] ?? 200)),
        ];
        if ($this->withTotal) {
            $page['total'] = count($this->messages);
        }
        return successResponse(json_encode($page));
    }
}

test('listRecentDeliveries(): maps history items by message_id, refines by UTC send time', function () {
    $since = gmmktime(0, 0, 0, 10, 17, 2026);
    // Item shape recorded live from GET /v1/messages (history).
    $item = fn(array $o) => array_merge(['message_id' => 'x', 'recipient_number' => '61400000001',
        'sender_id' => '61400000001', 'message_content' => 'Hi', 'status' => 'delivered', 'cost' => '1.00',
        'custom_ref' => null, 'requested_at' => '2026-10-17 10:59:49', 'scheduled_for' => null], $o);
    $messages = [
        $item(['message_id' => '8fcc051c']),
        // Requested before $since but scheduled after it: counts by its schedule time
        $item(['message_id' => 'd5f37219', 'status' => 'cancelled', 'cost' => '0.00',
               'requested_at' => '2026-10-16 23:59:00', 'scheduled_for' => '2026-10-17 11:00:53']),
        // Before $since (UTC) — dropped despite the day-granular server filter
        $item(['message_id' => 'old', 'requested_at' => '2026-10-16 23:59:59']),
        // Malformed number — skipped
        $item(['message_id' => 'junk', 'recipient_number' => '']),
    ];
    $p = makeProvider(new HistoryHttpClient($messages));
    $result = $p->listRecentDeliveries($since);
    assert_true($result->isSuccess());
    $deliveries = $result->getValue();
    assert_eq(array_map(fn($d) => $d->remoteId(), $deliveries), ['8fcc051c', 'd5f37219'],
        'remoteId is the per-message UUID the send returned, so history sync matches local rows');
    assert_eq($deliveries[0]->status(), SmsStatus::DELIVERED);
    assert_eq($deliveries[0]->segmentsUsed(), 1);
    assert_eq($deliveries[0]->message(), 'Hi');
    assert_eq($deliveries[0]->sendTimestamp(), gmmktime(10, 59, 49, 10, 17, 2026));
    assert_eq($deliveries[1]->status(), SmsStatus::CANCELLED);
    assert_eq($deliveries[1]->segmentsUsed(), 0);
});

test('listRecentDeliveries(): pages through the whole history, with or without a total', function () {
    $since = gmmktime(0, 0, 0, 10, 17, 2026);
    $messages = [];
    for ($i = 1; $i <= 250; $i++) {
        $messages[] = ['message_id' => "m$i", 'recipient_number' => '61400000001',
            'sender_id' => '61400000001', 'message_content' => 'Hi', 'status' => 'delivered',
            'cost' => '1.00', 'custom_ref' => null,
            'requested_at' => '2026-10-17 10:00:00', 'scheduled_for' => null];
    }

    $p = makeProvider(new HistoryHttpClient($messages));
    $result = $p->listRecentDeliveries($since);
    assert_eq(count($result->getValue()), 250, 'pages on past the 200-row page size');

    // A reply without `total` must not stop paging after a full first page.
    $p = makeProvider(new HistoryHttpClient($messages, withTotal: false));
    $result = $p->listRecentDeliveries($since);
    assert_eq(count($result->getValue()), 250);
});

// ---------------------------------------------------------------------------
// Capability surface, constants, metadata
// ---------------------------------------------------------------------------

test('capability surface: everything except REGISTER_SENDER_ID', function () {
    $p = makeProvider(new FakeHttpClient(failureResponse('unused')));
    assert_true($p->hasCapability(\Sms\SmsCapability::GET_BALANCE));
    assert_true($p->hasCapability(\Sms\SmsCapability::GET_SENDER_IDS));
    assert_true($p->hasCapability(\Sms\SmsCapability::DEFERRED_SEND));
    assert_true($p->hasCapability(\Sms\SmsCapability::DEFERRED_SEND_CANCEL));
    assert_true($p->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_NUMBER));
    assert_false($p->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_ID), 'ACMA registration happens in the dashboard');
    assert_true($p->hasCapability(\Sms\SmsCapability::LIST_OPT_OUTS));
    assert_true($p->hasCapability(\Sms\SmsCapability::REMOVE_OPT_OUT));
    assert_true($p->hasCapability(\Sms\SmsCapability::BATCH_DELIVERY_QUERY));
    assert_true($p->hasCapability(\Sms\SmsCapability::LIST_HISTORY));
});

test('getDeferredSendMaxDelay(): null (no documented maximum)', function () {
    $p = makeProvider(new FakeHttpClient(failureResponse('unused')));
    assert_eq($p->getDeferredSendMaxDelay(), null);
});

test('metadata: key, preference, segment cost, description, constants', function () {
    $p = makeProvider(new FakeHttpClient(failureResponse('unused')));
    assert_eq($p->getKey(), 'mobilemessagev1');
    assert_eq(MobileMessageSmsProvider::usagePreference(), 10);
    assert_eq($p->getSegmentCost(), 2000);
    assert_eq(MobileMessageSmsProvider::SEGMENT_COST_MILLICENTS, 2000);
    assert_eq($p->getDescription(), 'MobileMessage (via https://api.test.example)');

    $constants = MobileMessageSmsProvider::getConstants();
    $required = array_column(array_filter($constants, fn($c) => $c[1] === 'required'), 0);
    assert_eq($required, ['SMS_MOBILEMESSAGE_APIKEY_USERNAME', 'SMS_MOBILEMESSAGE_APIKEY_PASSWORD']);
});

test('registerSenderId(): refused with dashboard guidance', function () {
    $p = makeProvider(new FakeHttpClient(failureResponse('unused')));
    $result = $p->registerSenderId(new SenderID('MYID'), ['x' => 'y']);
    assert_true($result->isFailure());
    assert_contains($result->getError(), 'dashboard');
});

test('registerSenderNumber(): discovery returns number-entry schema', function () {
    $p = makeProvider(new FakeHttpClient(failureResponse('unused')));
    $result = $p->registerSenderNumber();
    assert_true($result->isSuccess());
    assert_eq(count($result->getValue()->fields), 2);
});

test('registerSenderNumber(): POSTs the number under "number", the key the API requires', function () {
    // Recorded live: POSTing it as "sender" fails with '"number" is required.'
    $cache = new ArrayCache();
    $cache->set('sms_senders', [['sender' => '61400000001', 'type' => 'own']]);
    $spy = new SpyHttpClient(successResponse(json_encode(['status' => 'complete', 'results' => []])));
    $p = makeProvider($spy, $cache);
    $result = $p->registerSenderNumber(new \Sms\ContactPhoneNumber(new PhoneNumber('0400000001'), 'Office'));
    assert_true($result->isSuccess());
    $body = json_decode($spy->lastRequest->body, true);
    assert_eq('0400000001', $body['number']);
    assert_eq('Office', $body['label']);
    assert_false(isset($body['sender']));
    assert_true(isset($cache->deleted['sms_senders']) || in_array('sms_senders', $cache->deleted, true));
});

test('registerSenderNumber(): an already-active number is a completed registration, not a failure', function () {
    // Recorded live: POSTing a number already on the account returns this error.
    $p = makeProvider(new FakeHttpClient(successResponse(json_encode(
        ['error' => 'This number is already an active sender on your account.']))));
    $result = $p->registerSenderNumber(new \Sms\ContactPhoneNumber(new PhoneNumber('0400000001'), 'Office'));
    assert_true($result->isSuccess());
    $step = $result->getValue();
    assert_true($step->registered);
    assert_eq('0400000001', $step->number);
    assert_true($step->isComplete());
});

test('fromSettings(): throws without credentials', function () {
    assert_throws(
        \RuntimeException::class,
        fn() => MobileMessageSmsProvider::fromSettings(new \Sms\ConstantSettings()),
    );
});

// ===========================================================================
// MobileMessageFakeHttpClient — test mode never mutates the real account
// ===========================================================================

test('test mode: POST /v1/senders is faked (registering texts a real handset otherwise)', function () {
    $fake = new \Sms\Providers\MobileMessageFakeHttpClient(new class implements HttpClient {
        public function send(HttpRequest $request): \Result
        {
            throw new \RuntimeException('test mode must not reach the real API for a POST');
        }
    });
    $result = $fake->send(new HttpRequest(
        url: 'https://api.example/v1/senders',
        method: 'POST',
        headers: '',
        body: json_encode(['number' => '61491570156', 'label' => 'Office']),
    ));
    assert_true($result->isSuccess(), 'the fake must answer');
    $data = json_decode($result->getValue()->body, true);
    assert_eq($data['sender'], '61491570156');
    assert_true($data['testMode']);
});

test('test mode: an unexpected POST fails instead of falling through to the real API', function () {
    $fake = new \Sms\Providers\MobileMessageFakeHttpClient(new class implements HttpClient {
        public function send(HttpRequest $request): \Result
        {
            throw new \RuntimeException('must not be called');
        }
    });
    $result = $fake->send(new HttpRequest(url: 'https://api.example/v1/unknown', method: 'POST', headers: '', body: '{}'));
    assert_true($result->isFailure());
    assert_contains($result->getError(), 'test mode');
});

test('test mode: GETs pass through (reads are harmless)', function () {
    $fake = new \Sms\Providers\MobileMessageFakeHttpClient(new class implements HttpClient {
        public function send(HttpRequest $request): \Result
        {
            return \Result::success(new HttpResponse('{"status":"complete","results":[]}'));
        }
    });
    $result = $fake->send(new HttpRequest(url: 'https://api.example/v1/account', method: 'GET', headers: '', body: ''));
    assert_true($result->isSuccess());
});
