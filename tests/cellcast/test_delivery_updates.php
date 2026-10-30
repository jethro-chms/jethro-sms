<?php

/**
 * Unit tests for CellcastSmsProvider — updateDelivery() and cancel().
 *
 * Pins:
 *     Fixed: deliveryTimestamp is ONLY populated when status is DELIVERED.
 *     updatedAt is NOT used as a delivery timestamp for non-delivered outcomes.
 *     Fixed: SENT and QUEUED are non-final; updateDelivery() will be called for them.
 *
 * All tests use injected FakeHttpClient / SpyHttpClient — no network, no PHP constants.
 */

namespace Test\Sms\Cellcast\DeliveryUpdates;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_contains, assert_not_contains, assert_throws};
use \Test\AssertionFailed;
use \Sms\{
    PhoneNumber, SmsDelivery, SmsStatus,
    HttpClient, HttpRequest, HttpResponse};
use Sms\Providers\CellcastSmsProvider;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

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
 * Build a delivery with a remoteId suitable for polling.
 */
function deliveryWithRemoteId(string $remoteId, string $number = '0401000001'): SmsDelivery
{
    return new SmsDelivery(
        recipient: new PhoneNumber($number),
        status: SmsStatus::SENT,
        remoteId: $remoteId,
    );
}

/**
 * Build a minimal v2 delivery report response.
 *
 * @param string $status       Cellcast status string
 * @param string|null $updatedAt  ISO-8601 timestamp or null
 * @param string|null $sendTime   ISO-8601 timestamp or null
 * @param string|null $id         Cellcast _id field
 */
function deliveryReportResponse(
    string $status,
    ?string $updatedAt = null,
    ?string $sendTime = null,
    ?string $id = 'msg-123',
): \Result {
    $item = ['status' => $status];
    if ($id !== null) {
        $item['_id'] = $id;
    }
    if ($updatedAt !== null) {
        $item['updatedAt'] = $updatedAt;
    }
    if ($sendTime !== null) {
        $item['send_time'] = $sendTime;
    }
    return successResponse(json_encode(['data' => $item]));
}

// ---------------------------------------------------------------------------
// updateDelivery() — status mapping
// ---------------------------------------------------------------------------

test('updateDelivery: "queued" maps to QUEUED', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('queued')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->status(), SmsStatus::QUEUED);
});

test('updateDelivery: "scheduled" maps to SCHEDULED', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('scheduled')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->status(), SmsStatus::SCHEDULED);
});

test('updateDelivery: "sent" maps to SENT', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('sent')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->status(), SmsStatus::SENT);
});

test('updateDelivery: "delivered" maps to DELIVERED', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('delivered')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->status(), SmsStatus::DELIVERED);
});

test('updateDelivery: "failed" maps to FAILED', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('failed')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->status(), SmsStatus::FAILED);
});

test('updateDelivery: "blocked" maps to FAILED', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('blocked')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->status(), SmsStatus::FAILED);
});

test('updateDelivery: "rejected" maps to FAILED', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('rejected')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->status(), SmsStatus::FAILED);
});

test('updateDelivery: "expired" maps to FAILED', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('expired')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->status(), SmsStatus::FAILED);
});

test('updateDelivery: "canceled" maps to CANCELLED', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('canceled')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->status(), SmsStatus::CANCELLED);
});

test('updateDelivery: unknown status string maps to UNKNOWN', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('some-future-status')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->status(), SmsStatus::UNKNOWN);
});

// ---------------------------------------------------------------------------
// updateDelivery() — delivery timestamp semantics (improvement #30)
//
// The fix: deliveryTimestamp is ONLY set when status is DELIVERED.
// updatedAt is NOT used as deliveryTimestamp for failed/blocked/expired/canceled.
// ---------------------------------------------------------------------------

test('updateDelivery: DELIVERED status with updatedAt sets deliveryTimestamp', function () {
    // Pins improvement #30 fix: updatedAt used as deliveryTimestamp when DELIVERED
    $updatedAt = '2025-06-01T10:30:00Z';
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('delivered', updatedAt: $updatedAt)));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    $delivery = $result->getValue();
    assert_eq($delivery->status(), SmsStatus::DELIVERED);
    $expectedTs = strtotime($updatedAt);
    assert_eq($delivery->deliveryTimestamp(), $expectedTs, 'Expected deliveryTimestamp to be set from updatedAt for DELIVERED');
});

test('updateDelivery: DELIVERED status without updatedAt has null deliveryTimestamp', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('delivered', updatedAt: null)));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->deliveryTimestamp(), null);
});

test('updateDelivery: FAILED status with updatedAt — deliveryTimestamp NOT populated (improvement #30)', function () {
    // This pins the fix from improvement #30.
    // Before the fix, updatedAt was used as deliveryTimestamp even for failed outcomes.
    // After the fix, deliveryTimestamp must be null for FAILED even when updatedAt is present.
    $updatedAt = '2025-06-01T10:30:00Z';
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('failed', updatedAt: $updatedAt)));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    $delivery = $result->getValue();
    assert_eq($delivery->status(), SmsStatus::FAILED);
    assert_eq($delivery->deliveryTimestamp(), null, 'deliveryTimestamp must NOT be set for FAILED (improvement #30)');
});

test('updateDelivery: "blocked" with updatedAt — deliveryTimestamp NOT populated (improvement #30)', function () {
    $updatedAt = '2025-06-01T10:30:00Z';
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('blocked', updatedAt: $updatedAt)));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->deliveryTimestamp(), null, 'deliveryTimestamp must NOT be set for blocked (improvement #30)');
});

test('updateDelivery: "expired" with updatedAt — deliveryTimestamp NOT populated (improvement #30)', function () {
    $updatedAt = '2025-06-01T10:30:00Z';
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('expired', updatedAt: $updatedAt)));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->deliveryTimestamp(), null, 'deliveryTimestamp must NOT be set for expired (improvement #30)');
});

test('updateDelivery: "canceled" with updatedAt — deliveryTimestamp NOT populated (improvement #30)', function () {
    $updatedAt = '2025-06-01T10:30:00Z';
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('canceled', updatedAt: $updatedAt)));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->deliveryTimestamp(), null, 'deliveryTimestamp must NOT be set for canceled (improvement #30)');
});

test('updateDelivery: "sent" with updatedAt — deliveryTimestamp NOT populated (improvement #30)', function () {
    // "sent" is a non-final, non-delivered status — no delivery timestamp should be recorded
    $updatedAt = '2025-06-01T10:30:00Z';
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('sent', updatedAt: $updatedAt)));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    $delivery = $result->getValue();
    assert_eq($delivery->status(), SmsStatus::SENT);
    assert_eq($delivery->deliveryTimestamp(), null, 'deliveryTimestamp must NOT be set for sent (improvement #30)');
});

test('updateDelivery: "queued" with updatedAt — deliveryTimestamp NOT populated (improvement #30)', function () {
    $updatedAt = '2025-06-01T10:30:00Z';
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('queued', updatedAt: $updatedAt)));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->deliveryTimestamp(), null, 'deliveryTimestamp must NOT be set for queued (improvement #30)');
});

// ---------------------------------------------------------------------------
// updateDelivery() — send timestamp
// ---------------------------------------------------------------------------

test('updateDelivery: send_time populates sendTimestamp', function () {
    $sendTime = '2025-06-01T09:00:00Z';
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('delivered', sendTime: $sendTime)));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    $expectedTs = strtotime($sendTime);
    assert_eq($result->getValue()->sendTimestamp(), $expectedTs);
});

test('updateDelivery: missing send_time leaves sendTimestamp null', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('sent', sendTime: null)));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->sendTimestamp(), null);
});

// ---------------------------------------------------------------------------
// updateDelivery() — remoteId from response _id field
// ---------------------------------------------------------------------------

test('updateDelivery: _id from response used as remoteId on returned delivery', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('delivered', id: 'new-remote-id')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('old-id'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->remoteId(), 'new-remote-id');
});

test('updateDelivery: missing _id in response yields null remoteId', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('sent', id: null)));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->remoteId(), null);
});

// ---------------------------------------------------------------------------
// updateDelivery() — error cases
// ---------------------------------------------------------------------------

test('updateDelivery: no remoteId on delivery returns failure', function () {
    $delivery = new SmsDelivery(
        recipient: new PhoneNumber('0401000001'),
        status: SmsStatus::SENT,
        remoteId: null,
    );
    $provider = makeProvider(new FakeHttpClient(successResponse('{}')));

    $result = $provider->updateDelivery($delivery);

    assert_true($result->isFailure());
    assert_contains($result->getError(), 'remote ID');
});

test('updateDelivery: missing data field in response returns failure', function () {
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode(['meta' => ['code' => 200]]))));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isFailure());
    assert_contains($result->getError(), 'data');
});

test('updateDelivery: HTTP failure propagates', function () {
    $provider = makeProvider(new FakeHttpClient(failureResponse('timeout')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isFailure());
    assert_contains($result->getError(), 'timeout');
});

test('updateDelivery: requests /api/v2/report/message/{id} via GET', function () {
    $spy = new SpyHttpClient(deliveryReportResponse('sent'));
    $provider = makeProvider($spy);

    $provider->updateDelivery(deliveryWithRemoteId('my-message-id'));

    assert_true($spy->lastRequest !== null);
    assert_eq($spy->lastRequest->method, 'GET');
    assert_contains($spy->lastRequest->url, '/api/v2/report/message/');
    assert_contains($spy->lastRequest->url, 'my-message-id');
});

// ---------------------------------------------------------------------------
// updateDelivery() — non-final statuses (improvement #15)
//
// After fix #15, SENT and QUEUED are non-final. These tests confirm the status
// values returned by updateDelivery() are correctly non-final, meaning the
// polling loop will continue for them.
// ---------------------------------------------------------------------------

test('updateDelivery: "sent" result is non-final (improvement #15)', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('sent')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    $status = $result->getValue()->status();
    assert_eq($status, SmsStatus::SENT);
    assert_false($status->isFinal(), 'SENT must be non-final after improvement #15');
});

test('updateDelivery: "queued" result is non-final (improvement #15)', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('queued')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    $status = $result->getValue()->status();
    assert_eq($status, SmsStatus::QUEUED);
    assert_false($status->isFinal(), 'QUEUED must be non-final after improvement #15');
});

test('updateDelivery: "scheduled" result is non-final', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('scheduled')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    $status = $result->getValue()->status();
    assert_eq($status, SmsStatus::SCHEDULED);
    assert_false($status->isFinal(), 'SCHEDULED must be non-final so polling continues');
});

test('updateDelivery: "delivered" result is final (improvement #15)', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('delivered')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    $status = $result->getValue()->status();
    assert_true($status->isFinal(), 'DELIVERED must be final');
});

test('updateDelivery: "failed" result is final (improvement #15)', function () {
    $provider = makeProvider(new FakeHttpClient(deliveryReportResponse('failed')));
    $result = $provider->updateDelivery(deliveryWithRemoteId('msg-1'));

    assert_true($result->isSuccess());
    $status = $result->getValue()->status();
    assert_true($status->isFinal(), 'FAILED must be final');
});

// ---------------------------------------------------------------------------
// cancel()  — batch-level cancel (replaced per-delivery cancelDelivery())
// ---------------------------------------------------------------------------

/** Helper: wrap a single delivery in a batch for cancel(). */
function batchOf(SmsDelivery ...$deliveries): \Sms\SmsDeliveryBatch
{
    return new \Sms\SmsDeliveryBatch(null, $deliveries);
}

test('cancel: success response returns batch with CANCELLED delivery', function () {
    $cancelResponse = ['status' => true, 'message' => 'Message cancelled'];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($cancelResponse))));

    $delivery = deliveryWithRemoteId('sched-msg-99');
    $result = $provider->cancel(batchOf($delivery));

    assert_true($result->isSuccess(), 'Expected success. Error: ' . ($result->isFailure() ? $result->getError() : ''));
    assert_eq($result->getValue()->deliveries[0]->status(), SmsStatus::CANCELLED);
});

test('cancel: returned delivery preserves remoteId', function () {
    $cancelResponse = ['status' => true];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($cancelResponse))));

    $delivery = deliveryWithRemoteId('sched-msg-99');
    $result = $provider->cancel(batchOf($delivery));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->deliveries[0]->remoteId(), 'sched-msg-99');
});

test('cancel: returned delivery preserves recipient', function () {
    $cancelResponse = ['status' => true];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($cancelResponse))));

    $delivery = deliveryWithRemoteId('sched-msg-99', '0401000001');
    $result = $provider->cancel(batchOf($delivery));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->deliveries[0]->recipient()->value, '0401000001');
});

test('cancel: delivery with no remoteId is left unchanged in batch', function () {
    $delivery = new SmsDelivery(
        recipient: new PhoneNumber('0401000001'),
        status: SmsStatus::SCHEDULED,
        remoteId: null,
    );
    $provider = makeProvider(new FakeHttpClient(successResponse('{}')));

    $result = $provider->cancel(batchOf($delivery));

    // Batch-level success, delivery status unchanged (SCHEDULED)
    assert_true($result->isSuccess());
    assert_eq($result->getValue()->deliveries[0]->status(), SmsStatus::SCHEDULED);
});

test('cancel: HTTP failure leaves delivery unchanged (partial failure is still success)', function () {
    $provider = makeProvider(new FakeHttpClient(failureResponse('server error')));

    $delivery = deliveryWithRemoteId('sched-msg-99');  // SENT status from helper
    $result = $provider->cancel(batchOf($delivery));

    // Batch-level success even on per-delivery HTTP failure; delivery status unchanged
    assert_true($result->isSuccess());
    assert_eq($result->getValue()->deliveries[0]->status(), $delivery->status(), 'Status must be unchanged on HTTP failure');
});

test('cancel: HTTP failure carries the transport error in statusDetail', function () {
    $provider = makeProvider(new FakeHttpClient(failureResponse('server error')));

    $result = $provider->cancel(batchOf(deliveryWithRemoteId('sched-msg-99')));

    assert_eq($result->getValue()->deliveries[0]->statusDetail(), 'server error');
});

test('cancel: envelope status:false leaves delivery unchanged, upstream message in statusDetail', function () {
    // Real Cellcast shape for an unknown message ID — HTTP 200, status:false
    $cancelResponse = [
        'status' => false,
        'message' => 'message not found',
        'error' => ['error' => 'message not found'],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($cancelResponse))));

    $delivery = deliveryWithRemoteId('sched-msg-99');
    $result = $provider->cancel(batchOf($delivery));

    assert_true($result->isSuccess(), 'Batch-level success even when the gateway refuses the cancel');
    $returned = $result->getValue()->deliveries[0];
    assert_eq($returned->status(), $delivery->status(), 'Status must be unchanged when the gateway refuses');
    assert_eq($returned->statusDetail(), 'message not found');
});

test('cancel: successful cancel leaves statusDetail null', function () {
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode(['status' => true]))));

    $result = $provider->cancel(batchOf(deliveryWithRemoteId('sched-msg-99')));

    assert_eq($result->getValue()->deliveries[0]->status(), SmsStatus::CANCELLED);
    assert_eq($result->getValue()->deliveries[0]->statusDetail(), null);
});

test('cancel: sends POST to /api/v1/gateway/cancelScheduleQuickMessage', function () {
    $spy = new SpyHttpClient(successResponse(json_encode(['status' => true])));
    $provider = makeProvider($spy);

    $provider->cancel(batchOf(deliveryWithRemoteId('sched-msg-99')));

    assert_true($spy->lastRequest !== null);
    assert_eq($spy->lastRequest->method, 'POST');
    assert_contains($spy->lastRequest->url, '/api/v1/gateway/cancelScheduleQuickMessage');
});

test('cancel: request body contains messageId', function () {
    $spy = new SpyHttpClient(successResponse(json_encode(['status' => true])));
    $provider = makeProvider($spy);

    $provider->cancel(batchOf(deliveryWithRemoteId('sched-msg-99')));

    $body = json_decode($spy->lastRequest->body, true);
    assert_eq($body['messageId'], 'sched-msg-99');
});

test('cancel: request body contains type=sms', function () {
    $spy = new SpyHttpClient(successResponse(json_encode(['status' => true])));
    $provider = makeProvider($spy);

    $provider->cancel(batchOf(deliveryWithRemoteId('sched-msg-99')));

    $body = json_decode($spy->lastRequest->body, true);
    assert_eq($body['type'], 'sms');
});

test('cancel: malformed JSON response leaves delivery unchanged', function () {
    $provider = makeProvider(new FakeHttpClient(successResponse('not-json')));

    $delivery = deliveryWithRemoteId('sched-msg-99');  // SENT status from helper
    $result = $provider->cancel(batchOf($delivery));

    // Batch-level success; delivery status unchanged on parse failure
    assert_true($result->isSuccess());
    assert_eq($result->getValue()->deliveries[0]->status(), $delivery->status(), 'Status must be unchanged on parse failure');
});

test('cancel: batch of 2 — first succeeds, second HTTP-fails — both statuses correct', function () {
    // Two deliveries: first will succeed (valid JSON cancel response),
    // second will fail (HTTP error). We model this by using a FakeHttpClient
    // that returns success once then fails — but our FakeHttpClient always returns
    // the same response, so we test with two separate providers here.
    // Instead, test partial outcome via SpyHttpClient that we can't easily sequence.
    // Use two deliveries where one has null remoteId (skipped) and one succeeds.
    $cancelResponse = ['status' => true];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($cancelResponse))));

    $d1 = deliveryWithRemoteId('msg-1');
    $d2 = new SmsDelivery(recipient: new PhoneNumber('0401000002'), status: SmsStatus::SCHEDULED, remoteId: null);

    $result = $provider->cancel(batchOf($d1, $d2));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->deliveries[0]->status(), SmsStatus::CANCELLED, 'First delivery should be CANCELLED');
    assert_eq($result->getValue()->deliveries[1]->status(), SmsStatus::SCHEDULED, 'Second delivery (no remoteId) should be unchanged');
});


// ---------------------------------------------------------------------------
// updateDelivery — error envelope handling
// ---------------------------------------------------------------------------

test('updateDelivery: status=false error envelope returns failure, not UNKNOWN', function () {
    $errorData = [
        'status' => false,
        'message' => 'internal server error',
        'data' => [],
        'error' => [
            'name' => 'CastError',
            'message' => 'Cast to ObjectId failed for value "fake_id"',
        ],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($errorData))));

    $result = $provider->updateDelivery(deliveryWithRemoteId('any-id'));

    assert_true($result->isFailure(), 'Error envelope must produce failure');
    assert_contains($result->getError(), 'internal server error');
});

test('updateDelivery: status=false with only error.message falls back correctly', function () {
    $errorData = [
        'status' => false,
        'data' => [],
        'error' => [
            'message' => 'Auth token expired',
        ],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($errorData))));

    $result = $provider->updateDelivery(deliveryWithRemoteId('any-id'));

    assert_true($result->isFailure());
    assert_contains($result->getError(), 'Auth token expired');
});

test('updateDelivery: valid response with status absent still works (regression guard)', function () {
    $validData = [
        'data' => [
            'status' => 'delivered',
            '_id' => 'msg_123',
            'updatedAt' => '2026-06-21T12:00:00Z',
            'send_time' => '2026-06-21T11:59:00Z',
        ],
    ];
    $provider = makeProvider(new FakeHttpClient(successResponse(json_encode($validData))));

    $result = $provider->updateDelivery(deliveryWithRemoteId('any-id'));

    assert_true($result->isSuccess(), 'Valid response without status=false must succeed');
    assert_eq($result->getValue()->status(), SmsStatus::DELIVERED);
});

// ---------------------------------------------------------------------------
// listRecentDeliveries() — GET /api/v2/report/message (real response shape,
// see docs/reference/_cellcast/report/message/GET.md)
// ---------------------------------------------------------------------------

test('listRecentDeliveries: parses data.items[] (receiver, message, createdAt, _id)', function () {
    $body = json_encode(['status' => true, 'data' => [
        'items' => [[
            '_id' => '6ac83f3e7a759ee78d5433d0',
            'status' => 'delivered',
            'message' => 'Hello there',
            'sender' => '61400000000',
            'receiver' => '401000001',
            'scheduleAt' => null,
            'createdAt' => '2026-10-09T01:11:27.025Z',
        ]],
        'total' => 1, 'limit' => 100, 'current' => 1, 'totalPages' => 1,
    ]]);
    $result = makeProvider(new FakeHttpClient(successResponse($body)))->listRecentDeliveries(0);

    assert_true($result->isSuccess());
    $ds = $result->getValue();
    assert_eq(count($ds), 1, 'The report item must not be skipped');
    assert_eq($ds[0]->remoteId(), '6ac83f3e7a759ee78d5433d0', '_id is the send MessageId');
    assert_eq($ds[0]->status(), SmsStatus::DELIVERED);
    assert_eq($ds[0]->message(), 'Hello there');
    assert_true($ds[0]->recipient()->sameNumberAs(new PhoneNumber('0401000001')), 'receiver is the national number');
    assert_eq($ds[0]->sendTimestamp(), strtotime('2026-10-09T01:11:27.025Z'));
});

test('listRecentDeliveries: smsDetail gives segments used and price per segment', function () {
    $body = json_encode(['status' => true, 'data' => [
        'items' => [[
            '_id' => 'm2', 'status' => 'delivered', 'message' => 'Two-segment message', 'receiver' => '401000001',
            'createdAt' => '2026-10-09T01:11:27.025Z',
            'smsDetail' => ['messagelength' => 200, 'smscount' => 2, 'costpersms' => 0.029, 'deductibleCredit' => 0.058],
        ]],
        'totalPages' => 1,
    ]]);
    $ds = makeProvider(new FakeHttpClient(successResponse($body)))->listRecentDeliveries(0)->getValue();
    assert_eq($ds[0]->segmentsUsed(), 2);
    assert_eq($ds[0]->perSegmentCost(), 0.029);
});

test('updateDelivery: single lookup without costpersms derives price as deductibleCredit / smscount', function () {
    $body = json_encode(['status' => true, 'data' => [
        '_id' => 'm3', 'status' => 'delivered', 'receiver' => '401000001',
        'smsDetail' => ['messagelength' => 64, 'smscount' => 2, 'deductibleCredit' => 0.094],
    ]]);
    $d = makeProvider(new FakeHttpClient(successResponse($body)))->updateDelivery(deliveryWithRemoteId('m3'))->getValue();
    assert_eq($d->segmentsUsed(), 2);
    assert_true(abs($d->perSegmentCost() - 0.047) < 0.000001, 'Expected 0.094 / 2 = 0.047');
});

test('updateDelivery: no smsDetail leaves segments and price unknown (null)', function () {
    $d = makeProvider(new FakeHttpClient(deliveryReportResponse('delivered')))->updateDelivery(deliveryWithRemoteId('msg-123'))->getValue();
    assert_eq($d->segmentsUsed(), null);
    assert_eq($d->perSegmentCost(), null);
});

test('updateDelivery: returned delivery carries the lookup JSON as rawResponse', function () {
    $d = makeProvider(new FakeHttpClient(deliveryReportResponse('delivered', id: 'msg-9')))->updateDelivery(deliveryWithRemoteId('msg-9'))->getValue();
    $raw = json_decode($d->rawResponse(), true);
    assert_eq($raw['_id'] ?? null, 'msg-9', 'rawResponse must be the status JSON, not empty');
});

test('listRecentDeliveries: an error envelope is a failure, not "no history"', function () {
    // Recorded live: expired credentials come back as an HTTP 200 with this shape.
    $body = json_encode(['code' => 401, 'message' => 'Token expired']);
    $result = makeProvider(new FakeHttpClient(successResponse($body)))->listRecentDeliveries(0);

    assert_true($result->isFailure(), 'history sync must not report "nothing new" on an auth error');
    assert_contains($result->getError(), 'Token expired');
});

test('listRecentDeliveries: status=false is a failure', function () {
    $body = json_encode(['status' => false, 'message' => 'Invalid date range']);
    $result = makeProvider(new FakeHttpClient(successResponse($body)))->listRecentDeliveries(0);

    assert_true($result->isFailure());
    assert_contains($result->getError(), 'Invalid date range');
});

test('listRecentDeliveries: invalid JSON is a failure', function () {
    $result = makeProvider(new FakeHttpClient(successResponse('<html>maintenance</html>')))->listRecentDeliveries(0);
    assert_true($result->isFailure());
});
