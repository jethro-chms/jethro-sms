<?php

/**
 * SMS_SENDER = '_USER_MOBILE_' is a symbolic token resolved against the
 * $userMobile injected into OverridingSmsProvider (the bridge passes
 * getCurrentUserMobileNumber()).  Only that exact number may be the sender;
 * with no $userMobile (CLI, logged-out 2FA) every send fails.
 *
 * @isolated-process — defines SMS_SENDER, which is process-global.
 */

namespace Test\Sms\OverridingUserMobileIsolated;

use function \Test\{test, assert_true, assert_eq, assert_contains};
use \Sms\{OverridingSmsProvider, DecoratingSmsProvider, SmsSender, PhoneNumber, SenderID, SmsDelivery, SmsStatus};
use Sms\Providers\TemplateSmsProvider;

require_once __DIR__ . '/../../src/load.php';

define('SMS_SENDER', '_USER_MOBILE_');

final class SpySmsProvider extends DecoratingSmsProvider
{
	public int $sendCalls = 0;

	public function __construct()
	{
		parent::__construct(new TemplateSmsProvider(url: 'https://unused.example', postTemplate: ''));
	}

	public function send(array $entries, SmsSender $sender, ?int $sendAt = null, bool $preview = false): \Result
	{
		$all = [];
		foreach ($entries as $e) {
			$this->sendCalls++;
			foreach ($e['recipients'] as $r) {
				$all[] = new SmsDelivery(recipient: $r->getPhoneNumber(), status: SmsStatus::SENT);
			}
		}
		return \Result::success($all);
	}
}

const USER_MOBILE = '0491570157';

/** @return array{OverridingSmsProvider, SpySmsProvider} */
function make(?string $userMobile): array
{
	$spy = new SpySmsProvider();
	return [new OverridingSmsProvider($spy, userMobile: $userMobile === null ? null : new PhoneNumber($userMobile)), $spy];
}

test('SMS_SENDER=_USER_MOBILE_ accepts the injected user mobile', function () {
	[$p, $spy] = make(USER_MOBILE);
	$result = $p->send(entries('Hello', [new PhoneNumber('0400111222')]), new PhoneNumber(USER_MOBILE));
	assert_true($result->isSuccess(), $result->isFailure() ? $result->getError() : '');
	assert_eq($spy->sendCalls, 1);
});

test('SMS_SENDER=_USER_MOBILE_ rejects a different phone number', function () {
	[$p, $spy] = make(USER_MOBILE);
	$result = $p->send(entries('Hello', [new PhoneNumber('0400111222')]), new PhoneNumber('0400999888'));
	assert_true($result->isFailure());
	assert_contains($result->getError(), '0400999888');
	assert_eq($spy->sendCalls, 0);
});

test('SMS_SENDER=_USER_MOBILE_ rejects an alphanumeric sender ID', function () {
	[$p, $spy] = make(USER_MOBILE);
	$result = $p->send(entries('Hello', [new PhoneNumber('0400111222')]), new SenderID('MyChurch'));
	assert_true($result->isFailure());
	assert_eq($spy->sendCalls, 0);
});

test('SMS_SENDER=_USER_MOBILE_ with no injected user mobile fails every send', function () {
	[$p, $spy] = make(null);
	$result = $p->send(entries('Hello', [new PhoneNumber('0400111222')]), new PhoneNumber(USER_MOBILE));
	assert_true($result->isFailure());
	assert_contains($result->getError(), 'no current user mobile');
	assert_eq($spy->sendCalls, 0);
});

test('system-initiated sends skip the SMS_SENDER=_USER_MOBILE_ check (2FA, reminders)', function () {
	$spy = new SpySmsProvider();
	$p = new OverridingSmsProvider($spy, userInitiated: false);
	$result = $p->send(entries('Code 1234', [new PhoneNumber('0400111222')]), new SenderID('MyChurch'));
	assert_true($result->isSuccess(), $result->isFailure() ? $result->getError() : '');
	assert_eq($spy->sendCalls, 1);
});
