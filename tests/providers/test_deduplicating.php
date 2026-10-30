<?php

/**
 * Unit tests for DeduplicatingSmsProvider: recipients sharing one mobile get
 * ONE gateway message (one remote_id, one charge on the primary's row); every
 * person still gets their own per-person delivery, in original recipient
 * order, so DbLoggingSmsProvider's positional insertSms() pairing and
 * sendSummary() keep working.
 *
 * Pins the fixes from the shared-mobile design review:
 *  - expansion writes each person's OWN number on their delivery (mixed
 *    local/international formats must not break positional pairing);
 *  - deliveries come back in ORIGINAL recipient order, even when the people
 *    sharing a number are not adjacent in the list;
 *  - non-bearer rows carry segments_used 0 and costBearer FALSE;
 *  - unique-number sends pass through byte-identical; preview takes the send
 *    path, so it shows the combined message the shared phone will receive;
 *  - a gateway that under-reports results gets its missing groups padded
 *    as FAILED instead of silently mis-mapping the zip.
 */

namespace Test\Sms\Providers;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_contains};
use \Sms\{
	PhoneNumber, SmsRecipient, SmsSender, SmsStatus,
	SmsDelivery, SmsDeliveryBatch,
	DecoratingSmsProvider, DeduplicatingSmsProvider, TokenExpandingSmsProvider, Templater,
	HttpClient, HttpRequest, HttpResponse,
};
use Sms\Providers\FiveCentSmsV5Provider;

require_once __DIR__ . '/../../src/load.php';

/** HTTP client that must never be reached (the fake inner provider never delegates). */
final class NeverHttpClient implements HttpClient
{
	public function send(HttpRequest $request): \Result
	{
		throw new \LogicException('inner raw provider must not be reached by these tests');
	}
}

/** Records the entries it was given and answers one scripted/synthetic delivery per recipient. */
final class RecordingFakeProvider extends DecoratingSmsProvider
{
	public array $sentEntries = [];
	public bool $sentPreview = false;

	/** Optional override for the deliveries returned, e.g. to simulate an under-reporting gateway. @var list<SmsDelivery>|null */
	public ?array $scripted = null;

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
		$this->sentPreview = $preview;
		if ($this->scripted !== null) {
			return \Result::success(new SmsDeliveryBatch(null, $this->scripted));
		}
		$deliveries = [];
		foreach ($entries as $entry) {
			foreach ($entry['recipients'] as $r) {
				$deliveries[] = new SmsDelivery(
					recipient: $r->getPhoneNumber(),
					status: SmsStatus::SENT,
					remoteId: 'r' . (count($deliveries) + 1),
					segmentsUsed: 1,
					message: $entry['message'],
				);
			}
		}
		return \Result::success(new SmsDeliveryBatch(null, $deliveries));
	}
}

/** A recipient with an identity beyond its number (two people can share one number). */
final class FakePerson implements SmsRecipient
{
	public function __construct(
		public int          $personId,
		public string       $name,
		private PhoneNumber $phone,
	) {}

	public function getPhoneNumber(): PhoneNumber
	{
		return $this->phone;
	}
}

function person(int $id, string $name, string $phone): FakePerson
{
	return new FakePerson($id, $name, new PhoneNumber($phone));
}

function numbers(array $recipients): array
{
	return array_map(static fn(SmsRecipient $r) => $r->getPhoneNumber()->value, $recipients);
}

function deliveredNumbers(array $deliveries): array
{
	return array_map(static fn(SmsDelivery $d) => $d->recipient()->value, $deliveries);
}

test('Dedup: three persons / two numbers → one inner recipient per number, three per-person deliveries in original order', function () {
	$alice = person(1, 'Alice', '0400111222');
	$bob   = person(2, 'Bob',   '0400111222');
	$carol = person(3, 'Carol', '0433222444');
	$fake = new RecordingFakeProvider();
	$dedup = new DeduplicatingSmsProvider($fake);

	$result = $dedup->send(
		[['message' => 'Roster Sunday', 'recipients' => [$alice, $bob, $carol]]],
		new PhoneNumber('0400000000'),
	);

	assert_true($result->isSuccess());
	$innerEntries = $fake->sentEntries;
	assert_eq(count($innerEntries), 1);
	assert_eq(numbers($innerEntries[0]['recipients']), ['0400111222', '0433222444'], 'one recipient per number dialled');
	assert_eq(count($innerEntries[0]['logicalRecipients']), 2, 'groups parallel to recipients');
	assert_eq(numbers($innerEntries[0]['logicalRecipients'][0]), ['0400111222', '0400111222'], 'primary first in the group');
	assert_eq(numbers($innerEntries[0]['logicalRecipients'][1]), ['0433222444']);

	$d = $result->getValue()->deliveries;
	assert_eq(count($d), 3, 'one delivery per PERSON');
	assert_eq(deliveredNumbers($d), ['0400111222', '0400111222', '0433222444'], 'original recipient order preserved');
	assert_eq($d[0]->remoteId(), 'r1');
	assert_eq($d[1]->remoteId(), 'r1', 'shared remote_id: one gateway message');
	assert_true($d[0]->costBearer(), 'primary bears the cost');
	assert_false($d[1]->costBearer(), 'secondary does not bear the cost');
	assert_eq($d[1]->segmentsUsed(), 0, 'secondary records an explicit zero');
	assert_eq($d[1]->message(), 'Roster Sunday', 'secondary shares the sent body');
	assert_eq($d[2]->remoteId(), 'r2');
});

test('Dedup: mixed local/international formats of one number cluster together and each person keeps their own format', function () {
	$alice = person(1, 'Alice', '0400111222');
	$bob   = person(2, 'Bob',   '61400111222');
	$fake = new RecordingFakeProvider();
	$dedup = new DeduplicatingSmsProvider($fake);

	$result = $dedup->send([['message' => 'Hi', 'recipients' => [$alice, $bob]]], new PhoneNumber('0400000000'));

	$inner = $fake->sentEntries[0];
	assert_eq(count($inner['recipients']), 1, 'clustered into one dialled number');
	$d = $result->getValue()->deliveries;
	assert_eq($d[0]->recipient()->value, '0400111222', 'primary keeps the provider result untouched');
	assert_eq($d[1]->recipient()->value, '61400111222', 'secondary carries their OWN stored format — positional pairing depends on it');
	assert_false($d[1]->costBearer());
});

test('Dedup: sharers need not be adjacent — deliveries come back in original recipient order', function () {
	$alice = person(1, 'Alice', '0400111222');
	$carol = person(3, 'Carol', '0433222444');
	$bob   = person(2, 'Bob',   '61400111222');
	$fake = new RecordingFakeProvider();
	$dedup = new DeduplicatingSmsProvider($fake);

	$result = $dedup->send([['message' => 'Hi', 'recipients' => [$alice, $carol, $bob]]], new PhoneNumber('0400000000'));

	assert_eq(numbers($fake->sentEntries[0]['recipients']), ['0400111222', '0433222444'], 'Alice and Bob share one dialled number');
	$d = $result->getValue()->deliveries;
	assert_eq(deliveredNumbers($d), ['0400111222', '0433222444', '61400111222'], 'delivery i is recipient i — insertSms() pairs by position');
	assert_eq($d[1]->remoteId(), 'r2');
	assert_true($d[1]->costBearer(), 'Carol bears her own message');
	assert_eq($d[2]->remoteId(), 'r1', "Bob's row records Alice's message");
	assert_false($d[2]->costBearer(), 'Bob is not charged a second time');
});

test('Dedup: unique numbers pass through byte-identical, without the logicalRecipients key', function () {
	$alice = person(1, 'Alice', '0400111222');
	$carol = person(3, 'Carol', '0433222444');
	$fake = new RecordingFakeProvider();
	$dedup = new DeduplicatingSmsProvider($fake);

	$entries = [['message' => 'Hi', 'recipients' => [$alice, $carol]]];
	$result = $dedup->send($entries, new PhoneNumber('0400000000'));

	$inner = $fake->sentEntries[0];
	assert_true(!isset($inner['logicalRecipients']), 'inert sends carry no metadata');
	assert_eq($inner['recipients'], $entries[0]['recipients']);
	$d = $result->getValue()->deliveries;
	assert_eq(count($d), 2);
	assert_true($d[0]->costBearer() && $d[1]->costBearer());
});

test('Dedup: preview takes the send path — one message per number, its combined text on every person\'s delivery', function () {
	$alice = person(1, 'Alice', '0400111222');
	$bob   = person(2, 'Bob',   '0400111222');
	$fake = new RecordingFakeProvider();
	$names = [1 => 'Alice', 2 => 'Bob'];
	$resolver = fn(SmsRecipient $r) => ['firstname' => $names[$r->personId]];
	$chain = new DeduplicatingSmsProvider(new TokenExpandingSmsProvider($fake, $resolver, new Templater(), ['firstname']));

	$result = $chain->send([['message' => 'Hi %firstname%', 'recipients' => [$alice, $bob]]], new PhoneNumber('0400000000'), preview: true);

	assert_true($fake->sentPreview);
	assert_eq(count($fake->sentEntries), 1, 'one preview message for the shared number');
	$d = $result->getValue()->deliveries;
	assert_eq(count($d), 2, 'still one delivery per person, for positional pairing');
	assert_eq($d[0]->message(), 'Hi Alice & Bob');
	assert_eq($d[1]->message(), 'Hi Alice & Bob', 'the preview shows what the phone will receive');
});

test('Dedup: separate entries sharing a number stay separate (per-person overrides must not be merged)', function () {
	$alice = person(1, 'Alice', '0400111222');
	$bob   = person(2, 'Bob',   '0400111222');
	$fake = new RecordingFakeProvider();
	$dedup = new DeduplicatingSmsProvider($fake);

	$result = $dedup->send([
		['message' => 'Standard notice', 'recipients' => [$alice]],
		['message' => 'Bob only', 'recipients' => [$bob]],
	], new PhoneNumber('0400000000'));

	assert_eq(count($fake->sentEntries), 2, 'entries are never merged across messages');
	assert_eq(count($result->getValue()->deliveries), 2);
	assert_eq($result->getValue()->deliveries[1]->message(), 'Bob only');
});

test('Dedup: gateway under-reporting pads the missing group FAILED instead of mis-mapping the zip', function () {
	$alice = person(1, 'Alice', '0400111222');
	$bob   = person(2, 'Bob',   '0400111222');
	$carol = person(3, 'Carol', '0433222444');
	$fake = new RecordingFakeProvider();
	$fake->scripted = [new SmsDelivery(recipient: new PhoneNumber('0400111222'), status: SmsStatus::SENT, remoteId: 'r1', segmentsUsed: 1)];
	$dedup = new DeduplicatingSmsProvider($fake);

	$result = $dedup->send([['message' => 'Hi', 'recipients' => [$alice, $bob, $carol]]], new PhoneNumber('0400000000'));

	$d = $result->getValue()->deliveries;
	assert_eq(count($d), 3, 'every person still gets a delivery');
	assert_eq($d[0]->status(), SmsStatus::SENT);
	assert_eq($d[1]->status(), SmsStatus::SENT);
	assert_eq($d[2]->status(), SmsStatus::FAILED, 'group with no gateway result is padded, not shifted');
	assert_true($d[2]->costBearer(), 'padded primary is its group\'s bearer');
	assert_contains($d[2]->rawResponse(), 'No result');
});

test('Dedup + TokenExpanding: a shared pair gets one combined message and one gateway request', function () {
	$alice = person(1, 'Alice', '0400111222');
	$bob   = person(2, 'Bob',   '0400111222');
	$carol = person(3, 'Carol', '0433222444');
	$fake = new RecordingFakeProvider();
	$templater = new Templater();
	$resolver = fn(SmsRecipient $r) => [$r->personId => 'x'][$r->personId] ?? [];
	$names = [1 => 'Alice', 2 => 'Bob', 3 => 'Carol'];
	$resolver = function (SmsRecipient $r) use ($names) {
		assert_true($r instanceof FakePerson);
		return ['firstname' => $names[$r->personId]];
	};
	$chain = new DeduplicatingSmsProvider(new TokenExpandingSmsProvider($fake, $resolver, $templater, ['firstname']));

	$result = $chain->send(
		[['message' => 'Hi %firstname%, roster Sunday', 'recipients' => [$alice, $bob, $carol]]],
		new PhoneNumber('0400000000'),
	);

	assert_true($result->isSuccess());
	$inner = $fake->sentEntries;
	assert_eq(count($inner), 2, 'one inner entry per GROUP');
	assert_eq($inner[0]['message'], 'Hi Alice & Bob, roster Sunday');
	assert_eq($inner[1]['message'], 'Hi Carol, roster Sunday');
	assert_eq(numbers($inner[0]['recipients']), ['0400111222']);
	assert_eq(numbers($inner[1]['recipients']), ['0433222444']);

	$d = $result->getValue()->deliveries;
	assert_eq(count($d), 3);
	assert_eq($d[0]->message(), 'Hi Alice & Bob, roster Sunday');
	assert_eq($d[1]->message(), 'Hi Alice & Bob, roster Sunday', 'both members carry the combined body actually sent');
	assert_eq($d[2]->message(), 'Hi Carol, roster Sunday');
	assert_false($d[1]->costBearer());
});
