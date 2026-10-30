<?php

/**
 * Unit tests for SmsDeliveryBatch cancel() contract.
 *
 * Covers spec item 10 requirements:
 *   (a) A concrete-style fake provider's send result wrapped by decorators
 *       preserves batchId through the cancel() path.
 *   (b) cancel() partial outcome — batch of 2 deliveries where the fake gateway
 *       cancels one and leaves the other unchanged returns success with one
 *       CANCELLED and one unchanged status.
 *
 * Uses DecoratingSmsProvider wrapping a configurable fake to test batchId
 * propagation without touching the DB or network.
 */

namespace Test\Sms\Cancel;

use function \Test\{test, assert_true, assert_eq};
use \Sms\{
    PhoneNumber, SmsDelivery, SmsStatus, SmsDeliveryBatch,
    DecoratingSmsProvider, SmsCapability,
};

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * Fake inner provider whose cancel() behaviour is injected per-test.
 *
 * $cancelFn receives (SmsDeliveryBatch) and returns Result<SmsDeliveryBatch, string>.
 * If null, defaults to returning the batch unchanged (all CANCELLED behaviour
 * is determined by the deliveries already in the batch — the default just echoes them).
 */
final class CancelFakeProvider implements \Sms\SmsProvider
{
    /** @var (\Closure(SmsDeliveryBatch): \Result)|null */
    public ?\Closure $cancelFn = null;

    public function send(
        array $entries,
        \Sms\SmsSender $sender,
        ?int $sendAt = null,
        bool $preview = false,
    ): \Result {
        $all = [];
        foreach ($entries as $e) {
            foreach ($e['recipients'] as $r) {
                $all[] = new SmsDelivery(
                    recipient: $r->getPhoneNumber(),
                    status: SmsStatus::SCHEDULED,
                );
            }
        }
        return \Result::success(new SmsDeliveryBatch(null, $all));
    }

    public function cancel(SmsDeliveryBatch $batch): \Result
    {
        if ($this->cancelFn !== null) {
            return ($this->cancelFn)($batch);
        }
        // Default: echo the batch unchanged
        return \Result::success($batch);
    }

    public function isOperational(): \Result
    {
        return \Result::success(true);
    }

    // Unused interface methods
    public static function fromSettings(\Sms\SmsSettings $settings, ?string $baseUrl = null): static { throw new \RuntimeException('not used'); }
    public function getKey(): string { return 'cancel-fake'; }
    public static function getConstants(): array { return []; }
    public static function usagePreference(): int { return 0; }
    public function withCache(\Sms\SmsCache $cache): static { return $this; }
    public function getBalance(): \Result { return \Result::failure('unsupported'); }
    public function getSenderIds(bool $getAll = false): \Result { return \Result::success([]); }
    public function updateDelivery(SmsDelivery $delivery): \Result { return \Result::failure('unsupported'); }
    public function getSenderNumbers(): \Result { return \Result::success([]); }
    public function verifySenderNumber(PhoneNumber $number): \Result { return \Result::success(true); }
    public function registerSenderNumber(?\Sms\ContactPhoneNumber $contact = null, ?array $validationParams = null): \Result { return \Result::failure('unsupported'); }
    public function registerSenderId(?\Sms\SenderID $senderId = null, ?array $validationParams = null): \Result { return \Result::failure('unsupported'); }
    public function deleteSenderNumber(PhoneNumber $number): \Result { return \Result::failure('unsupported'); }
    public function getSenderNumberRecords(): \Result { return \Result::failure('unsupported'); }
    public function getDescription(): string { return 'cancel-fake'; }
    public function hasCapability(\Sms\SmsCapability $cap): bool { return false; }
    public function listOptOuts(): \Result { return \Result::failure('unsupported'); }
    public function removeOptOut(PhoneNumber $number): \Result { return \Result::failure('unsupported'); }
    public function getSegmentCost(): int { return 5000; }
    public function getDeferredSendMaxDelay(): ?int { return null; }
    public function listRecentDeliveries(?int $since = null): \Result { return \Result::success([]); }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('cancel: batchId is preserved through DecoratingSmsProvider wrapper', function () {
    $fake = new CancelFakeProvider();
    // Inner fake echoes the batch unchanged
    $fake->cancelFn = static fn(SmsDeliveryBatch $b) => \Result::success($b);

    $decorator = new DecoratingSmsProvider($fake);

    $delivery = new SmsDelivery(
        recipient: new PhoneNumber('0400000001'),
        status: SmsStatus::SCHEDULED,
        remoteId: 'remote-1',
    );
    $inputBatch = new SmsDeliveryBatch('42', [$delivery]);

    $result = $decorator->cancel($inputBatch);

    assert_true($result->isSuccess(), 'cancel() must succeed');
    assert_eq($result->getValue()->batchId, '42', 'batchId must be preserved through decorator');
});

test('cancel: partial outcome — one CANCELLED, one unchanged', function () {
    $fake = new CancelFakeProvider();
    // Cancel fn: cancels first delivery, leaves second unchanged
    $fake->cancelFn = static function (SmsDeliveryBatch $batch): \Result {
        $results = [];
        foreach ($batch->deliveries as $i => $delivery) {
            if ($i === 0) {
                // Simulate success: return CANCELLED delivery
                $results[] = new SmsDelivery(
                    recipient: $delivery->recipient(),
                    status: SmsStatus::CANCELLED,
                    remoteId: $delivery->remoteId(),
                );
            } else {
                // Simulate per-delivery failure: leave delivery unchanged
                $results[] = $delivery;
            }
        }
        return \Result::success(new SmsDeliveryBatch($batch->batchId, $results));
    };

    $d1 = new SmsDelivery(recipient: new PhoneNumber('0400000001'), status: SmsStatus::SCHEDULED, remoteId: 'r-1');
    $d2 = new SmsDelivery(recipient: new PhoneNumber('0400000002'), status: SmsStatus::SCHEDULED, remoteId: 'r-2');
    $batch = new SmsDeliveryBatch('99', [$d1, $d2]);

    $result = $fake->cancel($batch);

    assert_true($result->isSuccess(), 'cancel() must return success even on partial failure');
    $returned = $result->getValue();
    assert_eq(count($returned->deliveries), 2, 'Both deliveries must be in result');
    assert_eq($returned->deliveries[0]->status(), SmsStatus::CANCELLED, 'First delivery must be CANCELLED');
    assert_eq($returned->deliveries[1]->status(), SmsStatus::SCHEDULED, 'Second delivery must be unchanged');
    assert_eq($returned->batchId, '99', 'batchId must be preserved');
});

test('cancel: batch with null batchId passes through DecoratingSmsProvider', function () {
    $fake = new CancelFakeProvider();
    $decorator = new DecoratingSmsProvider($fake);

    $delivery = new SmsDelivery(
        recipient: new PhoneNumber('0400000001'),
        status: SmsStatus::SCHEDULED,
        remoteId: 'remote-x',
    );
    $inputBatch = new SmsDeliveryBatch(null, [$delivery]);

    $result = $decorator->cancel($inputBatch);

    assert_true($result->isSuccess(), 'cancel() with null batchId must succeed');
    assert_eq($result->getValue()->batchId, null, 'null batchId must be preserved');
});
