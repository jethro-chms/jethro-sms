<?php

/**
 * Unit tests: a partial token-expansion failure is still logged and reported:
 * TokenExpandingSmsProvider preserves successful deliveries when a mid-loop
 * send fails and returns PartialSuccess (not a bare failure) so the logging
 * decorator can record everything.
 */

namespace Test\Sms\Token;

use function \Test\{test, assert_true, assert_eq};
use \Sms\{
	SmsProvider, SmsRecipient, SmsSender, PhoneNumber,
	SmsDelivery, SmsStatus,
	AllSent, PartialSuccess, Failed,
	TokenExpandingSmsProvider, Templater,
};

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * Controllable inner provider: succeeds for N calls then fails on the N+1th.
 * Each successful call returns one SmsDelivery with status SENT.
 */
final class ControllableSmsProvider implements SmsProvider
{
	private int $callCount = 0;

	/**
	 * @param int   $succeedCount  Number of calls to succeed before failing
	 * @param string $failError    Error message for the failure
	 */
	public function __construct(
		private int    $succeedCount,
		private string $failError = 'Gateway rejected: bad number',
	) {}

	public function send(
        array     $entries,
		SmsSender $sender,
		?int      $sendAt = null,
		bool      $preview = false,
	): \Result
	{
		$all = [];
		$failed = false;
		foreach ($entries as $entry) {
			$message = $entry['message'];
			$recipients = $entry['recipients'];
			if ($preview) {
				foreach ($recipients as $r) {
					$all[] = new SmsDelivery(
						recipient: $r->getPhoneNumber(),
						status: SmsStatus::QUEUED,
						message: $message,
					);
				}
				continue;
			}
			foreach ($recipients as $r) {
				if ($failed) {
					$all[] = new SmsDelivery(
						recipient: $r->getPhoneNumber(),
						status: SmsStatus::FAILED,
						rawResponse: 'Skipped due to prior failure',
					);
					continue;
				}
				$this->callCount++;
				if ($this->callCount > $this->succeedCount) {
					$failed = true;
					$all[] = new SmsDelivery(
						recipient: $r->getPhoneNumber(),
						status: SmsStatus::FAILED,
						rawResponse: $this->failError,
					);
				} else {
					$all[] = new SmsDelivery(
						recipient: $r->getPhoneNumber(),
						status: SmsStatus::SENT,
						remoteId: 'msg_' . $this->callCount,
					);
				}
			}
		}
		return \Result::success(new \Sms\SmsDeliveryBatch(null, $all));
	}

	// Unused interface methods — return empty defaults
	public static function fromSettings(\Sms\SmsSettings $settings, ?string $baseUrl = null): static { throw new \RuntimeException('not used'); }
	public function getKey(): string { return 'test'; }
	public static function getConstants(): array { return []; }
	public static function usagePreference(): int { return 0; }
	public function withCache(\Sms\SmsCache $cache): static { return $this; }
	public function getBalance(): \Result { return \Result::failure('unsupported'); }
	public function getSenderIds(bool $getAll = false): \Result { return \Result::success([]); }
	public function updateDelivery(SmsDelivery $delivery): \Result { return \Result::failure('unsupported'); }
	public function cancel(\Sms\SmsDeliveryBatch $batch): \Result { return \Result::failure('unsupported'); }
	public function getSenderNumbers(): \Result { return \Result::success([]); }
	public function verifySenderNumber(PhoneNumber $number): \Result { return \Result::success(true); }
	public function registerSenderNumber(?\Sms\ContactPhoneNumber $contact = null, ?array $validationParams = null): \Result { return \Result::failure('unsupported'); }
	public function registerSenderId(?\Sms\SenderID $senderId = null, ?array $validationParams = null): \Result { return \Result::failure('unsupported'); }
	public function deleteSenderNumber(\Sms\PhoneNumber $number): \Result { return \Result::failure('unsupported'); }
	public function getSenderNumberRecords(): \Result { return \Result::failure('unsupported'); }
	public function getDescription(): string { return 'test'; }
	public function hasCapability(\Sms\SmsCapability $cap): bool { return false; }
	public function listOptOuts(): \Result { return \Result::failure('unsupported'); }
	public function removeOptOut(PhoneNumber $number): \Result { return \Result::failure('unsupported'); }
	public function getDeferredSendMaxDelay(): ?int { return null; }
	public function getSegmentCost(): int { return 5000; }
	public function isOperational(): \Result
	{
		return \Result::success(true);
	}

	public function listRecentDeliveries(?int $since = null): \Result { return \Result::success([]); }
}

// Token resolver: %greeting% → "Hello"
function greetingResolver(): \Closure
{
	return fn (SmsRecipient $r) => ['greeting' => 'Hello'];
}

// ---------------------------------------------------------------------------
// Token expansion — full success (regression guard)
// ---------------------------------------------------------------------------

test('token expansion with all recipients succeeding', function () {
	$inner = new ControllableSmsProvider(succeedCount: 3);
	$provider = new TokenExpandingSmsProvider($inner, greetingResolver(), new Templater(), ['greeting']);
	$recipients = [
		new PhoneNumber('0400000001'),
		new PhoneNumber('0400000002'),
		new PhoneNumber('0400000003'),
	];

	$result = $provider->send(entries('%greeting% there', $recipients), new PhoneNumber('0400000000'));

	assert_true($result->isSuccess());
	$deliveries = $result->getValue()->deliveries;
	assert_eq(count($deliveries), 3);
	assert_eq($deliveries[0]->status(), SmsStatus::SENT);
	assert_eq($deliveries[1]->status(), SmsStatus::SENT);
	assert_eq($deliveries[2]->status(), SmsStatus::SENT);
});

// ===========================================================================
// Token expansion — mid-loop failure (the fix)
// ===========================================================================

test('mid-loop failure: prior successes are preserved', function () {
	// Succeed for first 1 call, fail on the 2nd
	$inner = new ControllableSmsProvider(succeedCount: 1, failError: 'Gateway rejected: bad number');
	$provider = new TokenExpandingSmsProvider($inner, greetingResolver(), new Templater(), ['greeting']);
	$recipients = [
		new PhoneNumber('0400000001'),
		new PhoneNumber('0400000002'),
		new PhoneNumber('0400000003'),
	];

	$result = $provider->send(entries('%greeting% there', $recipients), new PhoneNumber('0400000000'));

	// Must return success (so the logging decorator can record everything)
	assert_true($result->isSuccess(), 'Expected success — partial progress must not be discarded');

	$deliveries = $result->getValue()->deliveries;

	// First recipient succeeded
	assert_eq($deliveries[0]->status(), SmsStatus::SENT);
	assert_eq($deliveries[0]->recipient()->value, '0400000001');

	// Second recipient (the failing one) must be marked FAILED with the error
	assert_eq($deliveries[1]->status(), SmsStatus::FAILED, 'Failing recipient must be FAILED');
	assert_eq($deliveries[1]->recipient()->value, '0400000002');
	assert_eq($deliveries[1]->rawResponse(), 'Gateway rejected: bad number');

	// Third recipient skipped — marked FAILED with a skip message
	assert_eq($deliveries[2]->status(), SmsStatus::FAILED, 'Remaining recipient must be FAILED');
	assert_eq($deliveries[2]->recipient()->value, '0400000003');
});

test('mid-loop failure produces PartialSuccess via sendSummary', function () {
	$inner = new ControllableSmsProvider(succeedCount: 1);
	$provider = new TokenExpandingSmsProvider($inner, greetingResolver(), new Templater(), ['greeting']);
	$recipients = [
		new PhoneNumber('0400000001'),
		new PhoneNumber('0400000002'),
	];

	$result = $provider->send(entries('%greeting%', $recipients), new PhoneNumber('0400000000'));
	$summary = \Sms\sendSummary($result->getValue()->deliveries, $recipients);

	assert_true($summary instanceof PartialSuccess, 'Expected PartialSuccess, got ' . get_class($summary));
	assert_eq(count($summary->successes), 1);
	assert_eq(count($summary->failures), 1);
});

// ===========================================================================
// Token expansion — first recipient fails
// ===========================================================================

test('first recipient fails: no inner sends, all marked FAILED', function () {
	$inner = new ControllableSmsProvider(succeedCount: 0, failError: 'Gateway down');
	$provider = new TokenExpandingSmsProvider($inner, greetingResolver(), new Templater(), ['greeting']);
	$recipients = [
		new PhoneNumber('0400000001'),
		new PhoneNumber('0400000002'),
	];

	$result = $provider->send(entries('%greeting%', $recipients), new PhoneNumber('0400000000'));

	assert_true($result->isSuccess(), 'Must return success even when everything fails');
	$deliveries = $result->getValue()->deliveries;
	assert_eq(count($deliveries), 2);
	assert_eq($deliveries[0]->status(), SmsStatus::FAILED);
	assert_eq($deliveries[1]->status(), SmsStatus::FAILED);
	assert_eq($deliveries[0]->rawResponse(), 'Gateway down');

	// sendSummary with all failures → Failed
	$summary = \Sms\sendSummary($deliveries, $recipients);
	assert_true($summary instanceof Failed, 'All-failed must produce Failed summary');
});
