<?php

/**
 * Unit tests for OverridingSmsProvider::getSenderIds() — intersection logic.
 *
 * Pins improvements 22 and 41:
 *   - #22: the getSenderIds() error message path — "dead condition" that was
 *     intentionally kept as a privacy gate (no change needed, documented).
 *   - #41: the Available hint is now always appended when SMS_SENDER_OPTIONS
 *     contains IDs not registered upstream (fixed yesterday by removing the
 *     always-empty ternary; the error now unconditionally appends
 *     ' Available: <ids>.').
 *
 * CONSTANTS WARNING — process-global constants:
 *   All test files share ONE PHP process.  This file MUST define
 *   SMS_SENDER_OPTIONS with the exact same value as test_sender_allowlist.php
 *   (guarded by if(!defined)) so filtered and full runs both work.
 *   This file MUST NOT define SMS_SENDER, SMS_SEND_COOLOFF, or
 *   SMS_SEGMENT_COST — other tests depend on those being undefined.
 *
 * 
 * @see \Sms\OverridingSmsProvider::getSenderIds()
 */

namespace Test\Sms\Overriding;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_contains};
use \Sms\{
    OverridingSmsProvider, DecoratingSmsProvider,
    SmsCapability, SmsSender, PhoneNumber, SenderID,
};
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
 * Inner provider that does NOT advertise GET_SENDER_IDS.
 *
 * getSenderIds() falling through to this would normally return a failure;
 * the OverridingSmsProvider should short-circuit to SMS_SENDER_OPTIONS
 * before reaching the inner layer.
 */
final class NoSenderIdCapabilityProvider extends DecoratingSmsProvider
{
    public function __construct()
    {
        parent::__construct(new TemplateSmsProvider(url: 'https://unused.example', postTemplate: ''));
    }

    public function hasCapability(SmsCapability $cap): bool
    {
        return false;   // no capabilities at all
    }
}

/**
 * Inner provider WITH GET_SENDER_IDS capability, returning a configurable
 * list of SenderIDs from getSenderIds().
 *
 * @phpstan-type SenderIdRow array{value: string, acmaApproved: bool|null}
 */
final class StubSenderIdProvider extends DecoratingSmsProvider
{
    /** @var list<SenderID> */
    private array $ids;
    private bool  $fail;
    private string $failMsg;

    /**
     * @param list<SenderID> $ids      IDs to return from getSenderIds()
     * @param bool           $fail     If true, getSenderIds() returns a failure
     * @param string         $failMsg  Error message on failure
     */
    public function __construct(array $ids = [], bool $fail = false, string $failMsg = 'stub failure')
    {
        parent::__construct(new TemplateSmsProvider(url: 'https://unused.example', postTemplate: ''));
        $this->ids     = $ids;
        $this->fail    = $fail;
        $this->failMsg = $failMsg;
    }

    public function hasCapability(SmsCapability $cap): bool
    {
        return $cap === SmsCapability::GET_SENDER_IDS;
    }

    public function getSenderIds(bool $getAll = false): \Result
    {
        if ($this->fail) {
            return \Result::failure($this->failMsg);
        }
        // Always return all IDs (getAll filtering is OverridingSmsProvider's job).
        return \Result::success($this->ids);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Build an OverridingSmsProvider wrapping the given inner provider. */
function makeSenderIdsProvider(\Sms\SmsProvider $inner): OverridingSmsProvider
{
    return new OverridingSmsProvider($inner);
}

// ---------------------------------------------------------------------------
// Tests — inner WITHOUT GET_SENDER_IDS capability
// ---------------------------------------------------------------------------

test('getSenderIds: no GET_SENDER_IDS capability → getAll=true returns all 4 SMS_SENDER_OPTIONS entries', function () {
    $provider = makeSenderIdsProvider(new NoSenderIdCapabilityProvider());

    $result = $provider->getSenderIds(getAll: true);

    assert_true($result->isSuccess(), 'Expected success: ' . var_export($result->getError(), true));

    $ids = $result->getValue();
    assert_eq(count($ids), 3, 'SMS_SENDER_OPTIONS has 3 real entries (_USER_MOBILE_ excluded)');

    $values = array_map(fn(SenderID $s) => $s->value, $ids);
    assert_true(in_array('MyChurch',      $values, true), 'MyChurch must be present');
    assert_true(in_array('Youth',         $values, true), 'Youth must be present');
    assert_true(in_array('61400111222',   $values, true), '61400111222 must be present');
    assert_false(in_array('_USER_MOBILE_', $values, true), '_USER_MOBILE_ must NOT be present');
});

test('getSenderIds: no GET_SENDER_IDS capability → getAll=false keeps unknown-status (null) CSV entries', function () {
    // parseSenderIdsFromCsv() sets acmaApproved=null (status unknown); only
    // explicitly unapproved IDs (false) are filtered, so all 3 remain.
    $provider = makeSenderIdsProvider(new NoSenderIdCapabilityProvider());

    $result = $provider->getSenderIds(getAll: false);

    assert_true($result->isSuccess(), 'Expected success: ' . var_export($result->getError(), true));
    assert_eq(count($result->getValue()), 3, 'Unknown-status entries must survive the default filter');
});

test('getSenderIds: intersection with pending upstream ID → getAll=false hides it, getAll=true shows it', function () {
    $provider = makeSenderIdsProvider(new StubSenderIdProvider([
        new SenderID('MyChurch', acmaApproved: true),
        new SenderID('Youth',    acmaApproved: false),
    ]));
    $values = fn(bool $all) => array_map(fn(SenderID $s) => $s->value, $provider->getSenderIds(getAll: $all)->getValue());
    assert_eq($values(false), ['MyChurch'], 'Pending ID must be hidden by default');
    assert_eq($values(true), ['MyChurch', 'Youth'], 'getAll must include pending ID');
});

// ---------------------------------------------------------------------------
// Tests — inner WITH GET_SENDER_IDS, returns overlapping IDs
// ---------------------------------------------------------------------------

test('getSenderIds: inner returns [MyChurch(approved), SomethingElse(approved)] → intersection [MyChurch] with acmaApproved preserved', function () {
    $inner = new StubSenderIdProvider([
        new SenderID('MyChurch',     acmaApproved: true),
        new SenderID('SomethingElse', acmaApproved: true),
    ]);
    $provider = makeSenderIdsProvider($inner);

    $result = $provider->getSenderIds(getAll: true);

    assert_true($result->isSuccess(), 'Expected success: ' . var_export($result->getError(), true));

    $ids = $result->getValue();
    assert_eq(count($ids), 1, 'Only MyChurch overlaps with SMS_SENDER_OPTIONS');
    assert_eq($ids[0]->value, 'MyChurch');
    assert_eq($ids[0]->acmaApproved, true, 'acmaApproved flag must be preserved from inner provider');
});

test('getSenderIds: intersection [MyChurch(approved)] → getAll=false also returns [MyChurch] (acmaApproved=true)', function () {
    $inner = new StubSenderIdProvider([
        new SenderID('MyChurch',      acmaApproved: true),
        new SenderID('SomethingElse', acmaApproved: true),
    ]);
    $provider = makeSenderIdsProvider($inner);

    $result = $provider->getSenderIds(getAll: false);

    assert_true($result->isSuccess(), 'Expected success: ' . var_export($result->getError(), true));

    $ids = $result->getValue();
    assert_eq(count($ids), 1, 'MyChurch is acmaApproved=true, so it must be returned by getAll=false too');
    assert_eq($ids[0]->value, 'MyChurch');
});

// ---------------------------------------------------------------------------
// Tests — inner returns empty list (no IDs registered upstream at all)
// ---------------------------------------------------------------------------

test('getSenderIds: inner returns [] → failure mentioning "No sender IDs registered upstream"', function () {
    $inner    = new StubSenderIdProvider([]);  // empty — no IDs at upstream provider
    $provider = makeSenderIdsProvider($inner);

    $result = $provider->getSenderIds(getAll: true);

    assert_false($result->isSuccess(), 'Expected failure when upstream has no IDs');
    assert_contains((string) $result->getError(), 'No sender IDs registered upstream');
});

// ---------------------------------------------------------------------------
// Tests — inner returns IDs with no overlap (pins improvement #41)
// ---------------------------------------------------------------------------

test('getSenderIds: inner returns only [SomethingElse] (no overlap) → failure mentions wanted IDs AND "Available: SomethingElse."', function () {
    // This pins the fix from improvement #41:
    // Before the fix the "Available: …" hint was dead code (the ternary was
    // always empty because ifdef('SMS_SENDER_OPTIONS') is always truthy inside
    // the enclosing if-block).  After the fix the hint is unconditional.
    $inner = new StubSenderIdProvider([
        new SenderID('SomethingElse', acmaApproved: true),
    ]);
    $provider = makeSenderIdsProvider($inner);

    $result = $provider->getSenderIds(getAll: true);

    assert_false($result->isSuccess(), 'Expected failure when SMS_SENDER_OPTIONS has no overlap with upstream IDs');

    $error = (string) $result->getError();

    // Error must mention one of the wanted IDs (from SMS_SENDER_OPTIONS)
    assert_contains($error, 'MyChurch', 'Error must mention the wanted sender IDs');

    // Error must append the "Available: …" hint — this is what improvement 41 fixed
    assert_contains($error, 'Available: SomethingElse.', 'Error must include the "Available:" hint (improvement 41 fix)');
});

// ---------------------------------------------------------------------------
// Tests — inner getSenderIds() itself fails
// ---------------------------------------------------------------------------

test('getSenderIds: inner getSenderIds() failure → propagated unchanged', function () {
    $inner = new StubSenderIdProvider(
        ids: [],
        fail: true,
        failMsg: 'upstream provider unreachable',
    );
    $provider = makeSenderIdsProvider($inner);

    $result = $provider->getSenderIds(getAll: true);

    assert_false($result->isSuccess(), 'Expected failure to propagate from inner provider');
    assert_contains((string) $result->getError(), 'upstream provider unreachable');
});

// ---------------------------------------------------------------------------
// Notes on skipped cases
// ---------------------------------------------------------------------------
// "PhoneNumber sender with _USER_MOBILE_ in the list" is already covered by
// test_sender_allowlist.php → test "send() rejects a phone-number sender not
// in SMS_SENDER_OPTIONS even with _USER_MOBILE_ unresolved" and by the
// pass-through test for 61400111222.  No duplication needed here.
