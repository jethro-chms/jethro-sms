<?php

/**
 * Unit tests for OverridingSmsProvider — SMS_SENDER_OPTIONS enforcement on send().
 *
 * Pins the security property that the sender allow-list is enforced server-side on every send:
 * when SMS_SENDER_OPTIONS is configured, send() must reject senders that are not
 * in the allowlist, and must do so BEFORE the inner provider (i.e. the gateway)
 * is contacted.  The allowlist is currently only applied to the getSenderIds()
 * dropdown, so the rejection tests fail until the fix lands.
 *
 * NOTE: SMS_SENDER_OPTIONS is read as a PHP constant, which is process-global and
 * cannot be redefined between tests (see improvement 25).  All tests here share
 * the single allowlist defined below; a "no allowlist configured" case cannot be
 * tested in the same process.  If the fix lands as constructor injection instead
 * of a constant read, update makeProvider() below to pass the allowlist in.
 */

namespace Test\Sms\Overriding;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_contains};
use \Sms\{
	OverridingSmsProvider, DecoratingSmsProvider, SmsRecipient, SmsSender, PhoneNumber, SenderID, SmsDelivery, SmsStatus};
use Sms\Providers\TemplateSmsProvider;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// Process-global allowlist shared by every test in this file.
if (!defined('SMS_SENDER_OPTIONS')) {
	define('SMS_SENDER_OPTIONS', 'MyChurch,Youth,61400111222,_USER_MOBILE_');
}

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * Spy provider that records send() calls and succeeds without any HTTP.
 *
 * Extends DecoratingSmsProvider so only send() needs overriding; the inner
 * TemplateSmsProvider is never reached by these tests.
 */
final class SpySmsProvider extends DecoratingSmsProvider
{
	public int $sendCalls = 0;
	public ?SmsSender $lastSender = null;

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
			$this->lastSender = $sender;
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
// Helpers
// ---------------------------------------------------------------------------

/**
 * @return array{OverridingSmsProvider, SpySmsProvider}
 */
function makeProvider(?string $userMobile = null): array
{
	$spy = new SpySmsProvider();
	// Cooloff is gated on DEFERRED_SEND + DEFERRED_SEND_CANCEL capabilities, which SpySmsProvider does not declare
	$provider = new OverridingSmsProvider($spy, userMobile: $userMobile === null ? null : new PhoneNumber($userMobile));
	return [$provider, $spy];
}

const RECIPIENT = '0491570854';

// ===========================================================================
// Rejection — these FAIL until the allowlist is enforced in send()
// ===========================================================================

test('send() rejects an alphanumeric sender ID not in SMS_SENDER_OPTIONS', function () {
	[$provider, $spy] = makeProvider();

	$result = $provider->send(entries('hello', [new PhoneNumber(RECIPIENT)]), new SenderID('EvilCorp'));

	assert_true($result->isFailure(), 'Expected failure Result for disallowed sender "EvilCorp"');
	assert_eq($spy->sendCalls, 0, 'Inner provider must not be contacted for a disallowed sender');
});

test('send() rejection error names the offending sender', function () {
	[$provider, ] = makeProvider();

	$result = $provider->send(entries('hello', [new PhoneNumber(RECIPIENT)]), new SenderID('EvilCorp'));

	assert_true($result->isFailure(), 'Expected failure Result for disallowed sender "EvilCorp"');
	assert_contains((string) $result->getError(), 'EvilCorp');
});

test('_USER_MOBILE_ in SMS_SENDER_OPTIONS rejects a phone number that is not the user mobile', function () {
	[$provider, $spy] = makeProvider('0491570157');

	$result = $provider->send(entries('hello', [new PhoneNumber(RECIPIENT)]), new PhoneNumber('61499999999'));

	assert_true($result->isFailure());
	assert_eq($spy->sendCalls, 0, 'Inner provider must not be contacted for a rejected sender');
});

test('_USER_MOBILE_ in SMS_SENDER_OPTIONS rejects every unlisted number when no user mobile is injected', function () {
	[$provider, $spy] = makeProvider();

	$result = $provider->send(entries('hello', [new PhoneNumber(RECIPIENT)]), new PhoneNumber('0491570157'));

	assert_true($result->isFailure());
	assert_eq($spy->sendCalls, 0);
});

test('_USER_MOBILE_ in SMS_SENDER_OPTIONS permits the injected user mobile', function () {
	[$provider, $spy] = makeProvider('0491570157');

	$result = $provider->send(entries('hello', [new PhoneNumber(RECIPIENT)]), new PhoneNumber('0491570157'));

	assert_true($result->isSuccess(), (string) $result->getError());
	assert_eq($spy->sendCalls, 1);
});

// ===========================================================================
// Pass-through — green now, must STAY green after the fix
// ===========================================================================

test('send() allows a sender ID present in SMS_SENDER_OPTIONS', function () {
	[$provider, $spy] = makeProvider();

	$result = $provider->send(entries('hello', [new PhoneNumber(RECIPIENT)]), new SenderID('MyChurch'));

	assert_true($result->isSuccess(), 'Allowed sender must pass through: ' . var_export($result->getError(), true));
	assert_eq($spy->sendCalls, 1);
	assert_eq((string) $spy->lastSender, 'MyChurch');
});

test('send() allows a phone number listed literally in SMS_SENDER_OPTIONS', function () {
	[$provider, $spy] = makeProvider();

	$result = $provider->send(entries('hello', [new PhoneNumber(RECIPIENT)]), new PhoneNumber('61400111222'));

	assert_true($result->isSuccess(), 'Listed phone number must pass through: ' . var_export($result->getError(), true));
	assert_eq($spy->sendCalls, 1);
});

test('system-initiated sends (2FA, reminders) are not limited by SMS_SENDER_OPTIONS', function () {
	// 2FA sends as 2FA_SENDER_ID before anyone is logged in; on upgraded
	// installs that is typically not in the allowlist. Rejecting it locks
	// 2FA-required users out.
	$spy = new SpySmsProvider();
	$provider = new OverridingSmsProvider($spy, userInitiated: false);

	$result = $provider->send(entries('Your code is 123456', [new PhoneNumber(RECIPIENT)]), new SenderID('Jethro'));

	assert_true($result->isSuccess(), (string) $result->getError());
	assert_eq((string) $spy->lastSender, 'Jethro');
});
