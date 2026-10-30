<?php

/**
 * Unit tests for OptOutSmsProvider: numbers on the provider's opt-out list
 * are never sent to, and every opted-out person still gets a delivery —
 * status OPTED_OUT, no cost, in original recipient order — so DbLogging's
 * positional pairing holds (DbLogging drops them when recording).
 *
 * Pins:
 *  - opted-out recipients never reach the inner chain (local and
 *    international forms of a number match);
 *  - placeholders sit in ORIGINAL order between the inner deliveries;
 *  - an entry left empty is dropped, and an all-opted-out send makes no
 *    inner call at all;
 *  - fail open: no LIST_OPT_OUTS, a failing lookup, or no match passes
 *    entries through byte-identical;
 *  - preview takes the same path;
 *  - wired outside DeduplicatingSmsProvider, a shared opted-out number drops
 *    every person on it.
 */

namespace Test\Sms\OptOut;

use function \Test\{test, assert_true, assert_false, assert_eq};
use \Sms\{
	PhoneNumber, SmsRecipient, SmsSender, SmsStatus, SmsCapability, OptOutEntry,
	SmsDelivery, SmsDeliveryBatch,
	DecoratingSmsProvider, OptOutSmsProvider, DeduplicatingSmsProvider,
	HttpClient, HttpRequest,
};
use Sms\Providers\FiveCentSmsV5Provider;

require_once __DIR__ . '/../../src/load.php';

final class UnreachableHttpClient implements HttpClient
{
	public function send(HttpRequest $request): \Result
	{
		throw new \LogicException('the raw provider must not be reached by these tests');
	}
}

/** Inner provider: scripted opt-out list; records entries; one SENT delivery per recipient. */
final class OptOutFakeProvider extends DecoratingSmsProvider
{
	/** @var list<array<string, mixed>>|null null = send() never called */
	public ?array $sentEntries = null;
	public bool $sentPreview = false;

	/** @param list<string> $optOuts @param bool $canList @param bool $listFails */
	public function __construct(
		private array $optOuts = [],
		private bool $canList = true,
		private bool $listFails = false,
	) {
		parent::__construct(new FiveCentSmsV5Provider(
			url: 'https://api.test.example/v5', keyId: 'k', keySecret: 's', httpClient: new UnreachableHttpClient(),
		));
	}

	public function hasCapability(SmsCapability $capability): bool
	{
		return $capability === SmsCapability::LIST_OPT_OUTS ? $this->canList : parent::hasCapability($capability);
	}

	public function listOptOuts(): \Result
	{
		return $this->listFails
			? \Result::failure('upstream down')
			: \Result::success(array_map(static fn(string $n) => new OptOutEntry(new PhoneNumber($n)), $this->optOuts));
	}

	public function send(array $entries, SmsSender $sender, ?int $sendAt = null, bool $preview = false): \Result
	{
		$this->sentEntries = $entries;
		$this->sentPreview = $preview;
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

final class Person implements SmsRecipient
{
	public function __construct(public int $personId, private PhoneNumber $phone) {}

	public function getPhoneNumber(): PhoneNumber
	{
		return $this->phone;
	}
}

function p(int $id, string $phone): Person
{
	return new Person($id, new PhoneNumber($phone));
}

/** @param SmsDelivery[] $deliveries @return list<string> "number:status" */
function shape(array $deliveries): array
{
	return array_map(static fn(SmsDelivery $d) => $d->recipient()->value . ':' . $d->status()->value, $deliveries);
}

function sender(): PhoneNumber
{
	return new PhoneNumber('0400000000');
}

test('OptOut: opted-out recipient is never sent to and gets an OPTED_OUT placeholder in its original position', function () {
	$fake = new OptOutFakeProvider(['61400222333']);
	$result = (new OptOutSmsProvider($fake))->send(
		[['message' => 'Hi', 'recipients' => [p(1, '0400111222'), p(2, '0400222333'), p(3, '0433444555')]]],
		sender(),
	);

	assert_true($result->isSuccess());
	assert_eq(count($fake->sentEntries[0]['recipients']), 2, 'local-form 0400222333 matches opt-out 61400222333');
	$d = $result->getValue()->deliveries;
	assert_eq(shape($d), ['0400111222:sent', '0400222333:opted-out', '0433444555:sent'], 'delivery i is recipient i');
	assert_eq($d[1]->segmentsUsed(), 0);
	assert_false($d[1]->costBearer(), 'nothing was sent, nothing charged');
	assert_eq($d[1]->message(), 'Hi');
	assert_eq($d[2]->remoteId(), 'r2');
});

test('OptOut: an entry left empty is dropped; other entries keep their order', function () {
	$fake = new OptOutFakeProvider(['0400222333']);
	$result = (new OptOutSmsProvider($fake))->send([
		['message' => 'Template', 'recipients' => [p(1, '0400111222')]],
		['message' => 'Override', 'recipients' => [p(2, '0400222333')]],
		['message' => 'Other', 'recipients' => [p(3, '0433444555')]],
	], sender());

	assert_eq(array_column($fake->sentEntries, 'message'), ['Template', 'Other']);
	assert_eq(shape($result->getValue()->deliveries), ['0400111222:sent', '0400222333:opted-out', '0433444555:sent']);
	assert_eq($result->getValue()->deliveries[1]->message(), 'Override');
});

test('OptOut: when everyone opted out the inner chain is not called at all', function () {
	$fake = new OptOutFakeProvider(['0400111222', '0400222333']);
	$result = (new OptOutSmsProvider($fake))->send(
		[['message' => 'Hi', 'recipients' => [p(1, '0400111222'), p(2, '0400222333')]]],
		sender(),
	);

	assert_true($result->isSuccess());
	assert_eq($fake->sentEntries, null, 'no send for an empty batch');
	assert_eq(shape($result->getValue()->deliveries), ['0400111222:opted-out', '0400222333:opted-out']);
});

test('OptOut: fails open — no capability, a failing lookup, or no match pass entries through untouched', function () {
	$entries = [['message' => 'Hi', 'recipients' => [p(1, '0400111222')]]];
	foreach ([
		'no capability' => new OptOutFakeProvider(['0400111222'], canList: false),
		'lookup fails' => new OptOutFakeProvider(['0400111222'], listFails: true),
		'no match' => new OptOutFakeProvider(['0499999999']),
	] as $case => $fake) {
		$result = (new OptOutSmsProvider($fake))->send($entries, sender());
		assert_eq($fake->sentEntries, $entries, $case);
		assert_eq(shape($result->getValue()->deliveries), ['0400111222:sent'], $case);
	}
});

test('OptOut: preview takes the same path, so the preview can show who is skipped', function () {
	$fake = new OptOutFakeProvider(['0400222333']);
	$result = (new OptOutSmsProvider($fake))->send(
		[['message' => 'Hi', 'recipients' => [p(1, '0400111222'), p(2, '0400222333')]]],
		sender(),
		preview: true,
	);

	assert_true($fake->sentPreview);
	assert_eq(shape($result->getValue()->deliveries), ['0400111222:sent', '0400222333:opted-out']);
});

test('OptOut outside Dedup: everyone on an opted-out shared number is skipped; the rest still dedupe, in original order', function () {
	$fake = new OptOutFakeProvider(['0400222333']);
	$chain = new OptOutSmsProvider(new DeduplicatingSmsProvider($fake));
	$result = $chain->send([['message' => 'Hi', 'recipients' => [
		p(1, '0400111222'), p(2, '0400222333'), p(3, '0400111222'), p(4, '0400222333'),
	]]], sender());

	assert_eq(count($fake->sentEntries[0]['recipients']), 1, 'one message for the remaining shared number');
	$d = $result->getValue()->deliveries;
	assert_eq(shape($d), ['0400111222:sent', '0400222333:opted-out', '0400111222:sent', '0400222333:opted-out']);
	assert_true($d[0]->costBearer());
	assert_false($d[2]->costBearer(), 'second person on the sent number is not charged again');
});
