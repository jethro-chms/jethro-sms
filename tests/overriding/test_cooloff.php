<?php

/**
 * Unit tests for OverridingSmsProvider — SMS_SEND_COOLOFF semantics.
 *
 * Pins improvement 20 (cooloff-ignores-capabilities-and-record):
 *   - Cooloff is applied ONLY when the inner provider has BOTH DEFERRED_SEND
 *     AND DEFERRED_SEND_CANCEL capabilities.
 *   - When neither capability is present, sendAt is passed through unchanged.
 *   - An explicit future sendAt from the caller is never overridden.
 *
 * CONSTANTS WARNING — process-global constants:
 *   All test files share ONE PHP process.  This file MUST define
 *   SMS_SENDER_OPTIONS with the exact same value as test_sender_allowlist.php
 *   (guarded by if(!defined)) so filtered and full runs both work.
 *   This file MUST NOT define SMS_SENDER, SMS_SEND_COOLOFF, or
 *   SMS_SEGMENT_COST — other tests depend on those being undefined (e.g. the
 *   cooloff default is 30 when SMS_SEND_COOLOFF is undefined).
 *
 * @see \Sms\OverridingSmsProvider — the cool-off is applied only when the inner provider can defer and cancel
 */

namespace Test\Sms\Overriding;

use function \Test\{test, assert_true, assert_eq};
use \Sms\{
    OverridingSmsProvider, DecoratingSmsProvider, SmsCapability, SmsRecipient, SmsSender, PhoneNumber, SenderID,
    SmsDelivery, SmsStatus};
use Sms\Providers\TemplateSmsProvider;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// Process-global allowlist — must match test_sender_allowlist.php exactly.
if (!defined('SMS_SENDER_OPTIONS')) {
    define('SMS_SENDER_OPTIONS', 'MyChurch,Youth,61400111222,_USER_MOBILE_');
}

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * Spy provider that records send() calls without any HTTP.
 *
 * Capabilities are injected at construction time so each test can configure
 * exactly which capabilities are advertised.
 *
 * @see SpySmsProvider in test_sender_allowlist.php (same pattern)
 */
final class CooloffSpyProvider extends DecoratingSmsProvider
{
    public int  $sendCalls    = 0;
    public ?int $lastSendAt   = null;

    /** @var list<SmsCapability> */
    private array $capabilities;

    /** @param list<SmsCapability> $capabilities */
    public function __construct(array $capabilities = [])
    {
        parent::__construct(new TemplateSmsProvider(url: 'https://unused.example', postTemplate: ''));
        $this->capabilities = $capabilities;
    }

    public function hasCapability(SmsCapability $cap): bool
    {
        return in_array($cap, $this->capabilities, true);
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
            $this->lastSendAt = $sendAt;
            foreach ($e['recipients'] as $r) {
                $all[] = new SmsDelivery(
                    recipient: $r->getPhoneNumber(),
                    status: SmsStatus::SENT,
                );
            }
        }
        return \Result::success(new \Sms\SmsDeliveryBatch(null, $all));
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const COOLOFF_RECIPIENT = '0491570154';
const COOLOFF_SENDER    = 'MyChurch';   // in SMS_SENDER_OPTIONS allowlist

/**
 * Build an OverridingSmsProvider wrapping a CooloffSpyProvider with the given
 * capabilities.
 *
 * @param  list<SmsCapability> $capabilities
 * @return array{OverridingSmsProvider, CooloffSpyProvider}
 */
function makeCooloffProvider(array $capabilities): array
{
    $spy      = new CooloffSpyProvider($capabilities);
    $provider = new OverridingSmsProvider($spy);
    return [$provider, $spy];
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('cooloff: inner WITH both DEFERRED_SEND and DEFERRED_SEND_CANCEL → sendAt ≈ time()+30', function () {
    [$provider, $spy] = makeCooloffProvider([
        SmsCapability::DEFERRED_SEND,
        SmsCapability::DEFERRED_SEND_CANCEL,
    ]);

    $before = time();
    $result = $provider->send(
        [['message' => 'hello', 'recipients' => [new PhoneNumber(COOLOFF_RECIPIENT)]]],
        new SenderID(COOLOFF_SENDER),
    );
    $after = time();

    assert_true($result->isSuccess(), 'send() must succeed: ' . var_export($result->getError(), true));
    assert_true(
        $spy->lastSendAt !== null,
        'sendAt must be set when inner has both DEFERRED_SEND and DEFERRED_SEND_CANCEL',
    );
    assert_true(
        $spy->lastSendAt >= $before + 28 && $spy->lastSendAt <= $after + 32,
        'sendAt must be approximately time()+30, got ' . $spy->lastSendAt . ' (expected ' . ($before + 30) . '±2)',
    );
});

test('cooloff: inner WITHOUT DEFERRED_SEND_CANCEL → sendAt stays null', function () {
    // Has DEFERRED_SEND but not DEFERRED_SEND_CANCEL — cooloff must not apply.
    [$provider, $spy] = makeCooloffProvider([
        SmsCapability::DEFERRED_SEND,
        // DEFERRED_SEND_CANCEL intentionally absent
    ]);

    $result = $provider->send(
        [['message' => 'hello', 'recipients' => [new PhoneNumber(COOLOFF_RECIPIENT)]]],
        new SenderID(COOLOFF_SENDER),
    );

    assert_true($result->isSuccess(), 'send() must succeed: ' . var_export($result->getError(), true));
    assert_eq($spy->lastSendAt, null, 'sendAt must remain null when DEFERRED_SEND_CANCEL is absent');
});

test('cooloff: inner WITHOUT either DEFERRED capability → sendAt stays null', function () {
    [$provider, $spy] = makeCooloffProvider([]);   // no deferred capabilities

    $result = $provider->send(
        [['message' => 'hello', 'recipients' => [new PhoneNumber(COOLOFF_RECIPIENT)]]],
        new SenderID(COOLOFF_SENDER),
    );

    assert_true($result->isSuccess(), 'send() must succeed: ' . var_export($result->getError(), true));
    assert_eq($spy->lastSendAt, null, 'sendAt must remain null when no deferred capabilities are present');
});

test('cooloff: explicit future sendAt is passed through unchanged', function () {
    // Even with full capabilities the caller-supplied sendAt must not be overridden.
    [$provider, $spy] = makeCooloffProvider([
        SmsCapability::DEFERRED_SEND,
        SmsCapability::DEFERRED_SEND_CANCEL,
    ]);

    $explicit = time() + 3600;  // 1 hour from now
    $result = $provider->send(
        [['message' => 'hello', 'recipients' => [new PhoneNumber(COOLOFF_RECIPIENT)]]],
        new SenderID(COOLOFF_SENDER),
        $explicit,
    );

    assert_true($result->isSuccess(), 'send() must succeed: ' . var_export($result->getError(), true));
    assert_eq($spy->lastSendAt, $explicit, 'Explicit sendAt must be passed through unchanged');
});

test('cooloff: system-initiated send (userInitiated=false) skips the delay', function () {
    [$provider, $spy] = makeCooloffProvider([
        SmsCapability::DEFERRED_SEND,
        SmsCapability::DEFERRED_SEND_CANCEL,
    ]);
    // Re-wrap with userInitiated=false to simulate a system-initiated 2FA/reminder send
    $provider = new OverridingSmsProvider($spy, userInitiated: false);

    $result = $provider->send(
        [['message' => '2FA code', 'recipients' => [new PhoneNumber(COOLOFF_RECIPIENT)]]],
        new SenderID(COOLOFF_SENDER),
    );

    assert_true($result->isSuccess(), 'send() must succeed: ' . var_export($result->getError(), true));
    assert_eq($spy->lastSendAt, null, 'system-initiated send must skip cooloff delay');
});

