<?php

/**
 * Unit tests: sendSummary() keeps people who share a mobile distinct:
 * sendSummary() correctly attributes deliveries when two recipients share a
 * phone number, using positional pairing guarded by a pairwise phone sanity check.
 *
 * sendSummary() is pure — no DB needed. insertSms() / insertSmsDeliveries()
 * require $GLOBALS['db'] and are covered by the regression suite instead.
 *
 * Pins:
 *  - Two recipients sharing one number, both SENT → AllSent with BOTH distinct
 *    recipient objects (count 2, distinct instances)
 *  - Same setup, second delivery FAILED → PartialSuccess attributing the
 *    failure specifically to the second recipient
 *  - Mismatched counts (gateway dropped one) → falls back to phone-keyed map:
 *    both results resolve to the last recipient with that phone (person B),
 *    person A is never attributed — documented old behaviour on mismatch
 *  - remoteId aggregation unchanged ("id1, id2" style)
 */

namespace Test\Sms\Summary;

use function \Test\{test, assert_true, assert_eq};
use \Sms\{PhoneNumber, SmsDelivery, SmsStatus, AllSent, PartialSuccess, Failed};
use function \Sms\sendSummary;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * Minimal SmsRecipient fake that does NOT require jethro_sms.php (and thus
 * avoids pulling in DB globals).  Carries a person ID for identity checks.
 */
final readonly class FakeRecipient implements \Sms\SmsRecipient
{
    public function __construct(
        public int          $personId,
        private PhoneNumber $phone,
    ) {}

    public function getPhoneNumber(): PhoneNumber
    {
        return $this->phone;
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Build a SENT SmsDelivery for the given phone number and optional remoteId. */
function sentDelivery(string $phone, ?string $remoteId = null): SmsDelivery
{
    return new SmsDelivery(
        recipient: new PhoneNumber($phone),
        status:    SmsStatus::SENT,
        remoteId:  $remoteId,
    );
}

/** Build a FAILED SmsDelivery for the given phone number. */
function failedDelivery(string $phone): SmsDelivery
{
    return new SmsDelivery(
        recipient:   new PhoneNumber($phone),
        status:      SmsStatus::FAILED,
        rawResponse: 'Gateway error',
    );
}

// ===========================================================================
// Shared-mobile — both deliveries succeed → AllSent with both persons
// ===========================================================================

test('shared mobile, both SENT → AllSent with 2 distinct recipient objects', function () {
    $recipA = new FakeRecipient(personId: 10, phone: new PhoneNumber('0499111222'));
    $recipB = new FakeRecipient(personId: 20, phone: new PhoneNumber('0499111222'));

    $results = [
        sentDelivery('0499111222'),
        sentDelivery('0499111222'),
    ];

    $summary = sendSummary($results, [$recipA, $recipB]);

    assert_true($summary instanceof AllSent, 'Expected AllSent, got ' . get_class($summary));
    assert_eq(count($summary->recipients), 2, 'Both recipients must appear in AllSent');
    // Verify distinct objects — not the same person twice
    $personIds = array_map(fn(FakeRecipient $r) => $r->personId, $summary->recipients);
    assert_true(in_array(10, $personIds, true), 'Person 10 must be in recipients');
    assert_true(in_array(20, $personIds, true), 'Person 20 must be in recipients');
    // Confirm the objects are distinct instances (not duplicated)
    assert_true($summary->recipients[0] !== $summary->recipients[1], 'Recipient objects must be distinct');
});

// ===========================================================================
// Shared-mobile — first SENT, second FAILED → PartialSuccess attributes
// failure to person B (index 1), not person A
// ===========================================================================

test('shared mobile, first SENT + second FAILED → PartialSuccess with correct attribution', function () {
    $recipA = new FakeRecipient(personId: 10, phone: new PhoneNumber('0499111222'));
    $recipB = new FakeRecipient(personId: 20, phone: new PhoneNumber('0499111222'));

    $results = [
        sentDelivery('0499111222'),
        failedDelivery('0499111222'),
    ];

    $summary = sendSummary($results, [$recipA, $recipB]);

    assert_true($summary instanceof PartialSuccess, 'Expected PartialSuccess, got ' . get_class($summary));
    assert_eq(count($summary->successes), 1, 'Exactly one success');
    assert_eq(count($summary->failures),  1, 'Exactly one failure');

    // The success must be person A (index 0) and the failure must be person B (index 1)
    assert_eq($summary->successes[0]->personId, 10, 'Person 10 (first) must be the success');
    assert_eq($summary->failures[0]->personId,  20, 'Person 20 (second) must be the failure');
});

// ===========================================================================
// Mismatched counts — gateway dropped one delivery → falls back to phone-keyed
// map.  Old behaviour documented: both results resolve to the last recipient
// with that phone (person B, id=20).  Person A gets no row.
// ===========================================================================

test('count mismatch (gateway dropped one) → phone-keyed fallback, person B gets both rows', function () {
    $recipA = new FakeRecipient(personId: 10, phone: new PhoneNumber('0499111222'));
    $recipB = new FakeRecipient(personId: 20, phone: new PhoneNumber('0499111222'));

    // Gateway only returned 1 delivery for 2 recipients → count mismatch → fallback
    $results = [
        sentDelivery('0499111222'),
    ];

    $summary = sendSummary($results, [$recipA, $recipB]);

    // The phone map's last-write-wins means the phone key maps to $recipB.
    // One result → one success → AllSent with person B only.
    assert_true($summary instanceof AllSent, 'Expected AllSent (1 result matched phone-keyed)');
    assert_eq(count($summary->recipients), 1, 'Only 1 recipient in AllSent (phone-keyed fallback)');
    assert_eq($summary->recipients[0]->personId, 20, 'Phone-keyed fallback resolves to person B (last writer)');
});

// ===========================================================================
// remoteId aggregation — unchanged by positional pairing
// ===========================================================================

test('remoteIds are concatenated regardless of positional or fallback path', function () {
    $recipA = new FakeRecipient(personId: 10, phone: new PhoneNumber('0499111222'));
    $recipB = new FakeRecipient(personId: 20, phone: new PhoneNumber('0499111222'));

    $results = [
        sentDelivery('0499111222', 'id1'),
        sentDelivery('0499111222', 'id2'),
    ];

    $summary = sendSummary($results, [$recipA, $recipB]);

    assert_true($summary instanceof AllSent);
    assert_eq($summary->remoteId, 'id1, id2', 'remoteIds must be joined with ", "');
});

// ===========================================================================
// Sanity: distinct phones still work correctly (regression guard)
// ===========================================================================

test('distinct phones, both SENT → AllSent with both persons (regression guard)', function () {
    $recipA = new FakeRecipient(personId: 10, phone: new PhoneNumber('0400000001'));
    $recipB = new FakeRecipient(personId: 20, phone: new PhoneNumber('0400000002'));

    $results = [
        sentDelivery('0400000001'),
        sentDelivery('0400000002'),
    ];

    $summary = sendSummary($results, [$recipA, $recipB]);

    assert_true($summary instanceof AllSent, 'Expected AllSent');
    assert_eq(count($summary->recipients), 2);
    $personIds = array_map(fn(FakeRecipient $r) => $r->personId, $summary->recipients);
    assert_true(in_array(10, $personIds, true));
    assert_true(in_array(20, $personIds, true));
});

test('distinct phones, first SENT + second FAILED → PartialSuccess', function () {
    $recipA = new FakeRecipient(personId: 10, phone: new PhoneNumber('0400000001'));
    $recipB = new FakeRecipient(personId: 20, phone: new PhoneNumber('0400000002'));

    $results = [
        sentDelivery('0400000001'),
        failedDelivery('0400000002'),
    ];

    $summary = sendSummary($results, [$recipA, $recipB]);

    assert_true($summary instanceof PartialSuccess, 'Expected PartialSuccess');
    assert_eq($summary->successes[0]->personId, 10);
    assert_eq($summary->failures[0]->personId, 20);
});
