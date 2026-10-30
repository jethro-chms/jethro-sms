<?php
/**
 * A multi-request send (one HTTP request per entry, e.g. per recipient for a
 * personalised message) that fails part-way must not lose the deliveries the
 * gateway already accepted: they were sent and charged, and dropping them
 * leaves them unlogged, so a retry double-sends (SMS review Q2).
 *
 * Drives the real 5CentSMS v5 and Cellcast providers through a scripted
 * HttpClient. See \Sms\finishStoppedSend(), \Sms\failedEntryDeliveries().
 */
namespace Test\Sms\StoppedSend;

use function \Test\{test, assert_true, assert_eq, assert_contains};
use \Sms\{PhoneNumber, SmsStatus, SmsProvider, HttpClient, HttpRequest, HttpResponse, PartialSuccess, Failed};
use Sms\Providers\{FiveCentSmsV5Provider, CellcastSmsProvider};
use function \Sms\sendSummary;

require_once __DIR__ . '/../../src/load.php';

/** Returns the scripted results in order, one per request. */
final class ScriptedHttpClient implements HttpClient
{
	public int $requests = 0;

	/** @param list<\Result> $script */
	public function __construct(private array $script) {}

	public function send(HttpRequest $request): \Result
	{
		return $this->script[$this->requests++] ?? \Result::failure('unexpected extra request');
	}
}

/** One entry per recipient, as TokenExpandingSmsProvider produces for personalised messages. */
function perRecipientEntries(string ...$numbers): array
{
	return array_map(static fn(string $n) => ['message' => "Hi $n", 'recipients' => [new PhoneNumber($n)]], $numbers);
}

function ok(string $body): \Result
{
	return \Result::success(new HttpResponse($body));
}

function v5Accepted(string $number, string $id): \Result
{
	return ok(json_encode(['messages' => [['destination' => $number, 'status' => 1000, 'status_text' => 'Queued', 'id' => $id]]]));
}

function cellcastAccepted(string $number, string $id): \Result
{
	return ok(json_encode(['data' => ['queueResponse' => [['Number' => '61' . substr($number, 1), 'MessageId' => $id]]]]));
}

/** @return array<string, array{SmsProvider, ScriptedHttpClient}> */
function providers(\Result $second): array
{
	$v5Http = new ScriptedHttpClient([v5Accepted('0401000001', 'a1'), $second, v5Accepted('0401000003', 'a3')]);
	$ccHttp = new ScriptedHttpClient([cellcastAccepted('0401000001', 'a1'), $second, cellcastAccepted('0401000003', 'a3')]);
	return [
		'5CentSMS v5' => [new FiveCentSmsV5Provider(url: 'https://api.test.example/v5', keyId: 'k', keySecret: 's', httpClient: $v5Http), $v5Http],
		'Cellcast' => [new CellcastSmsProvider(apiToken: 't', url: 'https://api.test.example', httpClient: $ccHttp), $ccHttp],
	];
}

foreach (['5CentSMS v5', 'Cellcast'] as $name) {
	test("$name: transport failure on the 2nd of 3 requests keeps the 1st delivery, marks the 2nd UNKNOWN and the 3rd FAILED (not sent)", function () use ($name) {
		[$provider, $http] = providers(\Result::failure('Unable to connect to SMS Server: timed out'))[$name];
		$entries = perRecipientEntries('0401000001', '0401000002', '0401000003');

		$result = $provider->send($entries, new PhoneNumber('0400000000'));

		assert_true($result->isSuccess(), 'accepted deliveries must be returned, not a bare failure');
		assert_eq($http->requests, 2, 'stops after the failed request');
		$d = $result->getValue()->deliveries;
		assert_eq(array_map(static fn($x) => $x->recipient()->value, $d), ['0401000001', '0401000002', '0401000003'], 'one delivery per recipient, in order');
		assert_true($d[0]->status()->isOk(), '1st was accepted');
		assert_eq($d[0]->remoteId(), 'a1');
		assert_eq($d[1]->status(), SmsStatus::UNKNOWN, 'timed-out request may have been sent');
		assert_contains($d[1]->rawResponse(), 'timed out');
		assert_eq($d[2]->status(), SmsStatus::FAILED, 'never sent');
		assert_contains($d[2]->rawResponse(), 'Not sent');

		$recipients = array_merge(...array_column($entries, 'recipients'));
		assert_true(sendSummary($d, $recipients) instanceof PartialSuccess, 'reported as partial success');
	});

	test("$name: transport failure on the 1st request is still a plain failure (nothing was accepted)", function () use ($name) {
		$http = new ScriptedHttpClient([\Result::failure('Unable to connect to SMS Server: refused')]);
		$provider = $name === 'Cellcast'
			? new CellcastSmsProvider(apiToken: 't', url: 'https://api.test.example', httpClient: $http)
			: new FiveCentSmsV5Provider(url: 'https://api.test.example/v5', keyId: 'k', keySecret: 's', httpClient: $http);

		$result = $provider->send(perRecipientEntries('0401000001', '0401000002'), new PhoneNumber('0400000000'));

		assert_true($result->isFailure());
		assert_contains($result->getError(), 'refused');
	});
}

test('5CentSMS v5: a rejected request fails its recipients with the API error and the send goes on', function () {
	[$provider, $http] = providers(ok(json_encode(['error' => 'Invalid destination'])))['5CentSMS v5'];

	$result = $provider->send(perRecipientEntries('0401000001', '0401000002', '0401000003'), new PhoneNumber('0400000000'));

	assert_true($result->isSuccess());
	assert_eq($http->requests, 3, 'later entries are still sent');
	$d = $result->getValue()->deliveries;
	assert_eq(array_map(static fn($x) => $x->status(), $d), [SmsStatus::QUEUED, SmsStatus::FAILED, SmsStatus::QUEUED]);
	assert_eq($d[1]->rawResponse(), 'Invalid destination');
});

test('Cellcast: a rejected request (status false) fails its recipients with the API error and the send goes on', function () {
	[$provider, $http] = providers(ok(json_encode(['status' => false, 'message' => 'Invalid contact'])))['Cellcast'];

	$result = $provider->send(perRecipientEntries('0401000001', '0401000002', '0401000003'), new PhoneNumber('0400000000'));

	assert_true($result->isSuccess());
	assert_eq($http->requests, 3, 'later entries are still sent');
	$d = $result->getValue()->deliveries;
	assert_true($d[0]->status()->isOk() && $d[2]->status()->isOk());
	assert_eq($d[1]->status(), SmsStatus::FAILED);
	assert_contains($d[1]->rawResponse(), 'Invalid contact');
});

test('a single rejected request is reported as Failed with the API error', function () {
	$http = new ScriptedHttpClient([ok(json_encode(['error' => 'Invalid credentials']))]);
	$provider = new FiveCentSmsV5Provider(url: 'https://api.test.example/v5', keyId: 'k', keySecret: 's', httpClient: $http);
	$entries = perRecipientEntries('0401000001');

	$d = $provider->send($entries, new PhoneNumber('0400000000'))->getValue()->deliveries;
	$summary = sendSummary($d, $entries[0]['recipients']);

	assert_true($summary instanceof Failed);
	assert_contains($summary->error, 'Invalid credentials');
});
