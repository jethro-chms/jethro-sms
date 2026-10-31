<?php

/**
 * Unit tests: SMS_SEND_COOLOFF configured to a non-zero value.
 *
 * With the inner provider able to both defer and cancel, a user-initiated
 * immediate send is held for the configured number of seconds (the undo
 * window), rather than being sent at once.
 *
 * @isolated-process — this file defines SMS_SEND_COOLOFF, which is
 * process-global: the shared process must keep it undefined so
 * test_cooloff.php can pin the default (0, off).
 */

namespace Test\Sms\OverridingConfigured;

use function \Test\{test, assert_true, assert_eq};
use \Sms\{
	OverridingSmsProvider, DecoratingSmsProvider, SmsCapability, SmsSender,
	PhoneNumber, SenderID, SmsDelivery, SmsStatus};
use Sms\Providers\TemplateSmsProvider;

require_once __DIR__ . '/../../src/load.php';

define('SMS_SEND_COOLOFF', 30);

final class CooloffConfiguredSpy extends DecoratingSmsProvider
{
	public ?int $lastSendAt = null;

	public function __construct()
	{
		parent::__construct(new TemplateSmsProvider(url: 'https://unused.example', postTemplate: ''));
	}

	public function hasCapability(SmsCapability $cap): bool
	{
		return in_array($cap, [SmsCapability::DEFERRED_SEND, SmsCapability::DEFERRED_SEND_CANCEL], true);
	}

	public function send(
		array     $entries,
		SmsSender $sender,
		?int      $sendAt = null,
		bool      $preview = false,
	): \Result
	{
		$this->lastSendAt = $sendAt;
		$all = [];
		foreach ($entries as $e) {
			foreach ($e['recipients'] as $r) {
				$all[] = new SmsDelivery(recipient: $r->getPhoneNumber(), status: SmsStatus::SENT);
			}
		}
		return \Result::success(new \Sms\SmsDeliveryBatch(null, $all));
	}
}

test('cooloff: configured 30 s → user-initiated send is held ≈30 s', function () {
	$spy      = new CooloffConfiguredSpy();
	$provider = new OverridingSmsProvider($spy);

	$before = time();
	$result = $provider->send(
		[['message' => 'hello', 'recipients' => [new PhoneNumber('0491570154')]]],
		new SenderID('MyChurch'),
	);
	$after = time();

	assert_true($result->isSuccess(), 'send() must succeed: ' . var_export($result->getError(), true));
	assert_true(
		$spy->lastSendAt !== null && $spy->lastSendAt >= $before + 28 && $spy->lastSendAt <= $after + 32,
		'sendAt must be approximately time()+30, got ' . var_export($spy->lastSendAt, true),
	);
});

test('cooloff: configured 30 s → an explicit sendAt is still not overridden', function () {
	$spy      = new CooloffConfiguredSpy();
	$provider = new OverridingSmsProvider($spy);
	$explicit = time() + 3600;

	$provider->send(
		[['message' => 'hello', 'recipients' => [new PhoneNumber('0491570154')]]],
		new SenderID('MyChurch'),
		$explicit,
	);

	assert_eq($spy->lastSendAt, $explicit);
});
