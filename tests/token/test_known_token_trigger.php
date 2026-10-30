<?php

/**
 * Unit tests: token detection triggers only on real %tokens%, not on a bare '%':
 * TokenExpandingSmsProvider only triggers per-recipient splitting when the
 * message contains a *known* %token%, not a bare '%' like "20% off".
 *
 * Pins:
 *  - "Save 20% this week"   → single batched inner send (1 HTTP call, no fan-out)
 *  - "Hi %firstname%"       → per-recipient path (N inner sends, expanded text)
 *  - "%discount% applies"   → single batched send (unknown token, literal text)
 *  - messageHasTokens() direct edge-cases
 */

namespace Test\Sms\Token;

use function \Test\{test, assert_true, assert_false, assert_eq};
use \Sms\{
    SmsProvider, SmsRecipient, SmsSender, PhoneNumber,
    SmsDelivery, SmsStatus,
    TokenExpandingSmsProvider, Templater,
};
use function \Sms\messageHasTokens;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * Spy inner provider: records every send() call for inspection.
 */
final class SpySmsProvider implements SmsProvider
{
    /** @var array<array{message: string, recipients: SmsRecipient[]}> */
    public array $calls = [];

    public function send(
        array     $entries,
        SmsSender $sender,
        ?int      $sendAt = null,
        bool      $preview = false,
    ): \Result
    {
        $this->calls = [];
        $all = [];
        foreach ($entries as $e) {
            $this->calls[] = $e;
            foreach ($e['recipients'] as $r) {
                $all[] = new SmsDelivery(
                    recipient: $r->getPhoneNumber(),
                    status: SmsStatus::SENT,
                    message: $e['message'],
                );
            }
        }
        return \Result::success(new \Sms\SmsDeliveryBatch(null, $all));
    }

    // Unused interface methods
    public function isOperational(): \Result
    {
        return \Result::success(true);
    }
    public static function fromSettings(\Sms\SmsSettings $settings, ?string $baseUrl = null): static { throw new \RuntimeException('not used'); }
    public function getKey(): string { return 'spy'; }
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
    public function deleteSenderNumber(PhoneNumber $number): \Result { return \Result::failure('unsupported'); }
    public function getSenderNumberRecords(): \Result { return \Result::failure('unsupported'); }
    public function getDescription(): string { return 'spy'; }
    public function hasCapability(\Sms\SmsCapability $cap): bool { return false; }
    public function listOptOuts(): \Result { return \Result::failure('unsupported'); }
    public function removeOptOut(PhoneNumber $number): \Result { return \Result::failure('unsupported'); }
    public function getSegmentCost(): int { return 5000; }
    public function getDeferredSendMaxDelay(): ?int { return null; }
    public function listRecentDeliveries(?int $since = null): \Result { return \Result::success([]); }
}

/** Standard known tokens used in tests. */
$knownTokens = ['firstname', 'lastname', 'fullname'];

/** Resolver that maps tokens to placeholder values per recipient index. */
function indexedResolver(array $nameMap): \Closure
{
    return fn(SmsRecipient $r) => $nameMap[$r->getPhoneNumber()->value] ?? [];
}

// ---------------------------------------------------------------------------
// "20% off" — bare percent must NOT trigger per-recipient fan-out
// ---------------------------------------------------------------------------

test('"Save 20% this week" to 2 recipients → 1 batched inner send', function () use ($knownTokens) {
    $spy = new SpySmsProvider();
    $t = new Templater();
    $provider = new TokenExpandingSmsProvider(
        $spy,
        fn(SmsRecipient $r) => [],
        $t,
        $knownTokens,
    );

    $recipients = [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')];
    $result = $provider->send(entries('Save 20% this week', $recipients), new PhoneNumber('0411000000'));

    assert_true($result->isSuccess(), 'Expected success');
    assert_eq(count($spy->calls), 1, 'Expected exactly 1 inner send (batch), got ' . count($spy->calls));
    assert_eq(count($spy->calls[0]['recipients']), 2, 'Inner send should carry both recipients');
    assert_eq($spy->calls[0]['message'], 'Save 20% this week', 'Message must be passed through unchanged');
});

// ---------------------------------------------------------------------------
// "%firstname%" — known token triggers per-recipient path
// ---------------------------------------------------------------------------

test('"Hi %firstname%" to 2 recipients → 2 inner sends with expanded text', function () use ($knownTokens) {
    $spy = new SpySmsProvider();
    $nameMap = [
        '0400000001' => ['firstname' => 'Alice', 'lastname' => 'Smith', 'fullname' => 'Alice Smith'],
        '0400000002' => ['firstname' => 'Bob',   'lastname' => 'Jones', 'fullname' => 'Bob Jones'],
    ];
    $t = new Templater();
    $provider = new TokenExpandingSmsProvider(
        $spy,
        indexedResolver($nameMap),
        $t,
        $knownTokens,
    );

    $recipients = [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')];
    $result = $provider->send(entries('Hi %firstname%', $recipients), new PhoneNumber('0411000000'));

    assert_true($result->isSuccess(), 'Expected success');
    assert_eq(count($spy->calls), 2, 'Expected 2 inner sends (one per recipient), got ' . count($spy->calls));
    assert_eq($spy->calls[0]['message'], 'Hi Alice', 'First recipient message should be expanded');
    assert_eq($spy->calls[1]['message'], 'Hi Bob', 'Second recipient message should be expanded');
});

// ---------------------------------------------------------------------------
// "%discount% applies" — unknown token must NOT trigger per-recipient path
// ---------------------------------------------------------------------------

test('"%discount% applies" (unknown token) to 2 recipients → 1 batched send, literal text', function () use ($knownTokens) {
    $spy = new SpySmsProvider();
    $t = new Templater();
    $provider = new TokenExpandingSmsProvider(
        $spy,
        fn(SmsRecipient $r) => [],
        $t,
        $knownTokens,
    );

    $recipients = [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')];
    $result = $provider->send(entries('%discount% applies', $recipients), new PhoneNumber('0411000000'));

    assert_true($result->isSuccess(), 'Expected success');
    assert_eq(count($spy->calls), 1, 'Unknown token must not cause fan-out, expected 1 inner send, got ' . count($spy->calls));
    assert_eq($spy->calls[0]['message'], '%discount% applies', 'Literal text must be preserved');
    assert_eq(count($spy->calls[0]['recipients']), 2, 'Both recipients must be in the single inner send');
});

// ---------------------------------------------------------------------------
// messageHasTokens() — direct unit tests
// ---------------------------------------------------------------------------

test('messageHasTokens: empty token list → false', function () {
    assert_false(messageHasTokens('%firstname%', []), 'Empty token list must always return false');
    assert_false(messageHasTokens('Hello', []), 'Empty token list must always return false');
});

test('messageHasTokens: no "%" in message → false', function () {
    assert_false(messageHasTokens('Hello world', ['firstname', 'lastname']), 'No percent sign → false');
});

test('messageHasTokens: "20% off" → false (bare percent, no known token)', function () {
    assert_false(messageHasTokens('20% off', ['firstname', 'lastname', 'fullname']), '"20% off" must not match');
});

test('messageHasTokens: "%firstname%" → true', function () {
    assert_true(messageHasTokens('Hi %firstname%', ['firstname', 'lastname', 'fullname']), '"%firstname%" must match');
});

test('messageHasTokens: unknown %discount% with known list → false', function () {
    assert_false(messageHasTokens('%discount% applies', ['firstname', 'lastname', 'fullname']), 'Unknown token must not match');
});

test('messageHasTokens: token name with regex metachar is safe (no warning/error)', function () {
    // A token name containing a regex metacharacter — preg_quote must neutralise it
    $tokens = ['first.name', 'lastname'];
    // Should not throw or raise a warning; "first.name" won't match "firstname"
    $result = messageHasTokens('Hi %firstname%', $tokens);
    // The token is 'first.name' (with literal dot), message has 'firstname' (no dot) → false
    assert_false($result, 'Token with metachar should not match a differently-named placeholder');
    // But if the message literally contains the token name with a dot it should match
    assert_true(messageHasTokens('Hi %first.name%', $tokens), 'Exact dotted token name should match');
});

test('messageHasTokens: "%(expr)%" → true (s-expression always counts as token)', function () {
    assert_true(messageHasTokens('%(hello)%', []), '%(hello)% must match even with empty tokenNames');
    assert_true(messageHasTokens('%(upper "x")%', []), 'S-expression with function call must match');
    assert_true(messageHasTokens('%(concat a b)%', ['firstname']), 'S-expression with unknown vars still matches');
});

test('messageHasTokens: "%(expr)%" no known tokens → still true', function () {
    // %(...)% implies the sender opted into the grammar — always detected
    assert_true(messageHasTokens('Click %(shorten "url")% here', ['firstname']), 'S-expr match is independent of tokenNames');
});

// ---------------------------------------------------------------------------
// Prose containing "%(" is sent literally; a malformed real token still fails
// ---------------------------------------------------------------------------

test('"Up 10%(approx)" is sent literally rather than failing the send', function () use ($knownTokens) {
    $spy = new SpySmsProvider();
    $provider = new TokenExpandingSmsProvider($spy, fn(SmsRecipient $r) => [], new Templater(), $knownTokens);

    $recipients = [new PhoneNumber('0400000001')];
    $result = $provider->send(entries('Up 10%(approx) on last year', $recipients), new PhoneNumber('0411000000'));

    assert_true($result->isSuccess(), 'ordinary prose must not fail the send');
    assert_eq(count($spy->calls), 1);
    assert_eq($spy->calls[0]['message'], 'Up 10%(approx) on last year');
});

test('a malformed real token still fails (so it is not sent unexpanded)', function () use ($knownTokens) {
    $spy = new SpySmsProvider();
    $provider = new TokenExpandingSmsProvider($spy, fn(SmsRecipient $r) => [], new Templater(), $knownTokens);

    $result = $provider->send(entries('Hi %(firstname% there', [new PhoneNumber('0400000001')]), new PhoneNumber('0411000000'));

    assert_true($result->isFailure(), 'a broken %(firstname% must not go out literally to everyone');
    assert_eq(count($spy->calls), 0);
});

test('Templater::hasRecognisableToken() distinguishes prose from tokens', function () {
    $t = new Templater();
    assert_false($t->hasRecognisableToken('Up 10%(approx)'));
    assert_true($t->hasRecognisableToken('Hi %firstname%'));
    assert_true($t->hasRecognisableToken('Click %(shorten "https://example.org")%'));
});

test('Templater::unparseableSnippet() returns the offending text', function () {
    $t = new Templater();
    assert_eq($t->unparseableSnippet('Hi %firstname%'), null);
    assert_eq($t->unparseableSnippet('Up 10%(approx)'), null, 'prose parses as no tokens, so nothing to warn about');
    $snippet = $t->unparseableSnippet('Hello %(firstname% there');
    assert_true($snippet !== null && str_contains($snippet, 'firstname'), 'snippet names the problem token');
});
