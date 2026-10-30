<?php

/**
 * Unit tests: a sender mismatch is a \Result failure, not an exception:
 * when SMS_SENDER is configured, OverridingSmsProvider::send() with a different
 * sender must return Result::failure naming both values — not throw — and must
 * never reach the inner provider.  A matching sender passes through.
 *
 * @isolated-process — this file defines SMS_SENDER, which is process-global and
 * would break tests/sms/overriding/test_sender_allowlist.php (and any other test
 * relying on SMS_SENDER being undefined) if loaded into the shared test process.
 * The runner executes it in its own child PHP process instead.
 */

namespace Test\Sms\OverridingIsolated;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_contains};
use \Sms\{
	OverridingSmsProvider, DecoratingSmsProvider, SmsRecipient, SmsSender, PhoneNumber, SenderID, SmsDelivery, SmsStatus};
use Sms\Providers\TemplateSmsProvider;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// Process-global — safe here because this file runs in its own child process.
define('SMS_SENDER', 'MyChurch');

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * Spy provider that records send() calls and succeeds without any HTTP.
 */
final class SpySmsProvider extends DecoratingSmsProvider
{
	public int $sendCalls = 0;

	public function __construct()
	{
		parent::__construct(new TemplateSmsProvider(url: 'https://unused.example', postTemplate: ''));
	}

	public function send(
        array     $entries,
		SmsSender $sender,
		?int      $sendAt = null,
		bool      $preview = false,
	): \Result
	{
		$all = [];
		foreach ($entries as $e) {
			$this->sendCalls++;
			foreach ($e['recipients'] as $r) {
				$all[] = new SmsDelivery(
					recipient: $r->getPhoneNumber(),
					status: SmsStatus::SENT,
				);
			}
		}
		return \Result::success($all);
	}
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('send() with a sender differing from SMS_SENDER returns failure, not an exception', function () {
	$spy = new SpySmsProvider();
	$provider = new OverridingSmsProvider($spy);

	$result = $provider->send(entries('Hello', [new PhoneNumber('0400111222')]), new SenderID('SomeoneElse'));

	assert_true($result->isFailure(), 'Mismatched sender must produce a failure Result');
	assert_eq($spy->sendCalls, 0, 'Inner provider must not be contacted on sender mismatch');
});

test('mismatch failure names both the configured and the passed sender', function () {
	$provider = new OverridingSmsProvider(new SpySmsProvider());

	$result = $provider->send(entries('Hello', [new PhoneNumber('0400111222')]), new SenderID('SomeoneElse'));

	assert_true($result->isFailure());
	assert_contains($result->getError(), 'MyChurch');
	assert_contains($result->getError(), 'SomeoneElse');
});

test('a phone-number sender differing from SMS_SENDER is also rejected', function () {
	$spy = new SpySmsProvider();
	$provider = new OverridingSmsProvider($spy);

	$result = $provider->send(entries('Hello', [new PhoneNumber('0400111222')]), new PhoneNumber('0400999888'));

	assert_true($result->isFailure());
	assert_eq($spy->sendCalls, 0);
});

test('send() with the exact SMS_SENDER value passes through to the inner provider', function () {
	$spy = new SpySmsProvider();
	$provider = new OverridingSmsProvider($spy);

	$result = $provider->send(entries('Hello', [new PhoneNumber('0400111222')]), new SenderID('MyChurch'));

	assert_true($result->isSuccess(), 'Matching sender must pass: ' . ($result->isFailure() ? $result->getError() : ''));
	assert_eq($spy->sendCalls, 1, 'Inner provider must receive exactly one send');
});

test('SMS_SENDER takes precedence: no allowlist consultation happens when it is set', function () {
	// SMS_SENDER_OPTIONS is deliberately NOT defined in this child process;
	// with SMS_SENDER set the allowlist branch is never reached, so a matching
	// sender succeeds even with no allowlist configured.
	assert_false(defined('SMS_SENDER_OPTIONS'), 'precondition: this test needs SMS_SENDER_OPTIONS undefined');
	$provider = new OverridingSmsProvider(new SpySmsProvider());
	$result = $provider->send(entries('Hello', [new PhoneNumber('0400111222')]), new SenderID('MyChurch'));
	assert_true($result->isSuccess());
});

test('system-initiated sends skip the SMS_SENDER check', function () {
	$spy = new SpySmsProvider();
	$provider = new OverridingSmsProvider($spy, userInitiated: false);
	$result = $provider->send(entries('Code 1234', [new PhoneNumber('0400111222')]), new SenderID('TwoFactor'));
	assert_true($result->isSuccess(), $result->isFailure() ? $result->getError() : '');
	assert_eq($spy->sendCalls, 1);
});
