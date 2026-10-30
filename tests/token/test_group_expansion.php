<?php

/**
 * Unit tests for TokenExpandingSmsProvider's per-GROUP expansion: when an
 * entry carries `logicalRecipients` (attached by DeduplicatingSmsProvider),
 * person variables expand once per group with the members' token values
 * joined " & " — one message per shared number ("Hi Alice & Bob, …").
 *
 * Also pins that the function-only-token branch (which rebuilds the entry
 * array) carries logicalRecipients through, and that entries WITHOUT the key
 * keep the classic per-person expansion.
 */

namespace Test\Sms\Token;

use function \Test\{test, assert_true, assert_false, assert_eq};
use \Sms\{
	SmsRecipient, SmsSender, PhoneNumber, SmsStatus, SmsDelivery,
	SmsDeliveryBatch, DecoratingSmsProvider, TokenExpandingSmsProvider, Templater,
	HttpClient, HttpRequest, HttpResponse,
};
use Sms\Providers\FiveCentSmsV5Provider;

require_once __DIR__ . '/../../src/load.php';

final class NeverHttpClient implements HttpClient
{
	public function send(HttpRequest $request): \Result
	{
		throw new \LogicException('raw provider must not be reached by these tests');
	}
}

/** Records expanded entries, then answers one SENT delivery per entry-recipient. */
final class RecordingInner extends DecoratingSmsProvider
{
	public array $sentEntries = [];

	public function __construct()
	{
		parent::__construct(new FiveCentSmsV5Provider(
			url: 'https://api.test.example/v5',
			keyId: 'k',
			keySecret: 's',
			httpClient: new NeverHttpClient(),
		));
	}

	public function send(
		array     $entries,
		SmsSender $sender,
		?int      $sendAt = null,
		bool      $preview = false,
	): \Result
	{
		$this->sentEntries = $entries;
		$deliveries = [];
		foreach ($entries as $entry) {
			foreach ($entry['recipients'] as $r) {
				$deliveries[] = new SmsDelivery(
					recipient: $r->getPhoneNumber(),
					status: SmsStatus::SENT,
					remoteId: 'r' . (count($deliveries) + 1),
					message: $entry['message'],
				);
			}
		}
		return \Result::success(new SmsDeliveryBatch(null, $deliveries));
	}
}

final class FakePerson implements SmsRecipient
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

/** @return array{0: TokenExpandingSmsProvider, 1: RecordingInner} */
function makeChain(array $tokensByPerson): array
{
	$inner = new RecordingInner();
	$resolver = fn(SmsRecipient $r) => $tokensByPerson[$r->personId] ?? [];
	return [new TokenExpandingSmsProvider($inner, $resolver, new Templater(), ['firstname', 'lastname']), $inner];
}

test('Group expansion: one message per group with member values joined " & "', function () {
	$alice = new FakePerson(1, new PhoneNumber('0400111222'));
	$bob = new FakePerson(2, new PhoneNumber('0400111222'));
	[$chain, $inner] = makeChain([1 => ['firstname' => 'Alice'], 2 => ['firstname' => 'Bob']]);

	$chain->send(
		[[
			'message' => 'Hi %firstname%, roster Sunday',
			'recipients' => [$alice],
			'logicalRecipients' => [[$alice, $bob]],
		]],
		new PhoneNumber('0400000000'),
	);

	assert_eq(count($inner->sentEntries), 1, 'one entry for the whole group');
	$e = $inner->sentEntries[0];
	assert_eq($e['message'], 'Hi Alice & Bob, roster Sunday');
	assert_eq(count($e['recipients']), 1, 'one dialled recipient');
	assert_eq(count($e['logicalRecipients']), 1, 'logicalRecipients stays parallel to recipients');
});

test('Group expansion: members missing a token value contribute nothing to the join', function () {
	$alice = new FakePerson(1, new PhoneNumber('0400111222'));
	$bob = new FakePerson(2, new PhoneNumber('0400111222'));
	[$chain, $inner] = makeChain([1 => ['firstname' => 'Alice'], 2 => ['firstname' => '']]);

	$chain->send(
		[[
			'message' => 'Hi %firstname%',
			'recipients' => [$alice],
			'logicalRecipients' => [[$alice, $bob]],
		]],
		new PhoneNumber('0400000000'),
	);

	assert_eq($inner->sentEntries[0]['message'], 'Hi Alice');
});

test('No logicalRecipients: classic per-person expansion unchanged', function () {
	$alice = new FakePerson(1, new PhoneNumber('0400111222'));
	$bob = new FakePerson(2, new PhoneNumber('0400111222'));
	[$chain, $inner] = makeChain([1 => ['firstname' => 'Alice'], 2 => ['firstname' => 'Bob']]);

	$chain->send(
		[['message' => 'Hi %firstname%', 'recipients' => [$alice, $bob]]],
		new PhoneNumber('0400000000'),
	);

	assert_eq(count($inner->sentEntries), 2, 'split per person as before');
	assert_eq($inner->sentEntries[0]['message'], 'Hi Alice');
	assert_eq($inner->sentEntries[1]['message'], 'Hi Bob');
	assert_true(!isset($inner->sentEntries[0]['logicalRecipients']));
});

test('Function-only tokens: the rebuilt entry carries logicalRecipients through', function () {
	$alice = new FakePerson(1, new PhoneNumber('0400111222'));
	$bob = new FakePerson(2, new PhoneNumber('0400111222'));
	[$chain, $inner] = makeChain([1 => ['firstname' => 'Alice'], 2 => ['firstname' => 'Bob']]);

	$chain->send(
		[[
			'message' => 'See %(shorten "https://example.com/a/very/long/url/here")% now',
			'recipients' => [$alice],
			'logicalRecipients' => [[$alice, $bob]],
		]],
		new PhoneNumber('0400000000'),
	);

	assert_eq(count($inner->sentEntries), 1, 'no per-person split without person variables');
	$e = $inner->sentEntries[0];
	assert_eq(count($e['recipients']), 1);
	assert_eq(count($e['logicalRecipients'][0]), 2, 'metadata must not be stripped by the entry rebuild');
	assert_true(isset($e['template']));
});

test('Group of one with logicalRecipients behaves like a single recipient', function () {
	$carol = new FakePerson(3, new PhoneNumber('0433222444'));
	[$chain, $inner] = makeChain([3 => ['firstname' => 'Carol']]);

	$chain->send(
		[[
			'message' => 'Hi %firstname%',
			'recipients' => [$carol],
			'logicalRecipients' => [[$carol]],
		]],
		new PhoneNumber('0400000000'),
	);

	assert_eq($inner->sentEntries[0]['message'], 'Hi Carol');
});
