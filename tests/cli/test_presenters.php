<?php

/**
 * Unit tests for the CLI presenters.
 *
 * Pins:
 *   - TextPresenter output byte-for-byte (the pre-refactor CLI text), so
 *     scripts and muscle memory keep working;
 *   - JsonPresenter envelope and per-action payload shapes (`--json`);
 *   - note handling (informational, exit 0) and failure envelopes;
 *   - credential masking in `info`.
 */

namespace Test\Sms\CliPresenters;

use function \Test\{test, assert_eq, assert_true, assert_contains};
use \Sms\{PhoneNumber, OptOutEntry, RegistrationStep, FormField, SmsStatus, SmsDelivery};
use \Sms\Cli\{CliOutcome, TextPresenter, JsonPresenter};

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function capture(callable $fn): string
{
	ob_start();
	try {
		$fn();
	} finally {
		$out = (string) ob_get_clean();
	}
	return $out;
}

function renderText(CliOutcome $outcome): string
{
	return capture(fn () => (new TextPresenter())->render($outcome));
}

/** @return array<string, mixed> */
function renderJson(CliOutcome $outcome): array
{
	$raw = capture(fn () => (new JsonPresenter())->render($outcome));
	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		throw new \RuntimeException('JsonPresenter emitted invalid JSON: ' . $raw);
	}
	return $decoded;
}

function delivery(string $status = 'SENT'): SmsDelivery
{
	return new SmsDelivery(
		recipient: new PhoneNumber('61400000001'),
		status: \constant('Sms\\SmsStatus::' . $status),
		remoteId: 'abc123',
		sendTimestamp: 1700000000,
		deliveryTimestamp: 1700000100,
		message: 'Hello',
	);
}

// ===========================================================================
// TextPresenter — output parity
// ===========================================================================

test('text: sender IDs, empty and populated', function () {
	assert_eq(
		renderText(CliOutcome::success('senderids', [])),
		"No registered sender IDs found.\n",
	);

	$out = renderText(CliOutcome::success('senderids', [
		['value' => 'MYCHURCH', 'acmaApproved' => true, 'registeredAt' => 1785788740],
		['value' => 'NEWID', 'acmaApproved' => false, 'registeredAt' => null],
		['value' => 'UNKNOWN', 'acmaApproved' => null, 'registeredAt' => 1744784026],
	]));
	assert_eq($out, "Sender IDs:\n"
		. "  MYCHURCH (approved)  (attempted 2026-08-03)\n"
		. "  NEWID (not approved — hidden from senders)\n"
		. "  UNKNOWN  (attempted 2025-04-16)\n");
});

test('text: sender numbers and balance', function () {
	assert_eq(renderText(CliOutcome::success('sendernums', [])), "No registered sender numbers found.\n");
	assert_eq(
		renderText(CliOutcome::success('sendernums', [new PhoneNumber('61400000001'), new PhoneNumber('61400000002')])),
		"Registered sender numbers:\n  61400000001\n  61400000002\n",
	);
	assert_eq(renderText(CliOutcome::success('balance', 1372)), "Account balance: 1372\n");
});

test('text: opt-outs', function () {
	assert_eq(renderText(CliOutcome::success('optouts', [])), "No opted-out numbers found.\n");

	$out = renderText(CliOutcome::success('optouts', [
		new OptOutEntry(new PhoneNumber('61400000001'), optedOutAt: 1700000000, name: 'Alex'),
		new OptOutEntry(new PhoneNumber('61400000002')),
	]));
	assert_contains($out, "Opted-out numbers (2):\n");
	assert_contains($out, "  61400000001  (Alex)  opted out " . date('Y-m-d H:i:s', 1700000000) . "\n");
	assert_contains($out, "  61400000002\n");
});

test('text: recent deliveries', function () {
	assert_eq(
		renderText(CliOutcome::success('list', ['deliveries' => [], 'since' => null])),
		"No deliveries found in the last 24 hours.\n",
	);
	assert_eq(
		renderText(CliOutcome::success('list', ['deliveries' => [], 'since' => 1700000000])),
		"No deliveries found since " . date('Y-m-d H:i:s', 1700000000) . ".\n",
	);

	$d = delivery();
	$out = renderText(CliOutcome::success('list', ['deliveries' => [$d], 'since' => null]));
	assert_eq($out, "Recent deliveries (last 24 hours) — 1 result(s):\n\n  " . \Sms\formatDeliveryLine($d) . "\n");
});

test('text: send summaries', function () {
	$recipients = [new PhoneNumber('61400000001'), new PhoneNumber('61400000002')];
	$allSent = new \Sms\AllSent($recipients, 'r1');
	assert_eq(
		renderText(CliOutcome::success('sms', ['summary' => $allSent, 'deliveries' => []])),
		"Sent successfully to 2 recipient(s).\n",
	);

	$partial = new \Sms\PartialSuccess(
		successes: [$recipients[0]],
		failures: [$recipients[1]],
		remoteId: 'r2',
	);
	assert_eq(
		renderText(CliOutcome::success('sms', ['summary' => $partial, 'deliveries' => []])),
		"Sent to 1 recipient(s), failed for 1.\n",
	);
});

test('text: delivery status block', function () {
	$out = renderText(CliOutcome::success('smsinfo', delivery()));
	assert_contains($out, "Status:     ");
	assert_contains($out, " (#" . SmsStatus::SENT->value . ")\n");
	assert_contains($out, "Sent:       " . date('Y-m-d H:i:s', 1700000000) . "\n");
	assert_contains($out, "Delivered:  " . date('Y-m-d H:i:s', 1700000100) . "\n");
});

test('text: registration step and field schema', function () {
	$step = new RegistrationStep(message: 'Verification link sent.', instructions: 'Click it.');
	assert_eq(
		renderText(CliOutcome::success('validate-sendernum', ['step' => $step])),
		"Verification link sent.\n\nClick it.\n",
	);

	$withFields = new RegistrationStep(fields: [
		new FormField('otp', 'One-time code', 'text', required: true),
		new FormField('hidden_thing', 'Hidden', 'hidden'),
	]);
	$out = renderText(CliOutcome::success('validate-sendernum', ['step' => $withFields]));
	assert_contains($out, "\nComplete verification with 'complete-sendernum-validation' providing these fields:\n");
	assert_contains($out, "  --otp=<value>  One-time code (required)\n");
	assert_true(!str_contains($out, 'hidden_thing'), 'hidden fields are not listed');

	$validate = renderText(CliOutcome::success('complete-sendernum-validation', ['step' => $withFields, 'mode' => 'schema']));
	assert_contains($validate, "\nRequired fields:\n  --otp=<value>  One-time code (required)\n");

	$validateEmpty = renderText(CliOutcome::success('complete-sendernum-validation', [
		'step' => new RegistrationStep(),
		'mode' => 'submitted',
	]));
	assert_eq($validateEmpty, "\nNo validation fields required.\n");

	$schema = renderText(CliOutcome::success('register-sender-id', ['step' => $withFields, 'mode' => 'schema']));
	assert_contains($schema, "Sender ID registration requires the following fields:\n\n");
	assert_contains($schema, "  --otp=One-time code (required)\n");
});

test('text: delete/remove confirmations and notes', function () {
	assert_eq(
		renderText(CliOutcome::success('delete-sendernum', ['number' => '61400000001'])),
		"Done. The number is no longer registered with the provider.\n",
	);
	assert_eq(renderText(CliOutcome::success('remove-optout', ['number' => '61400000001'])), "Done.\n");
	assert_eq(
		renderText(CliOutcome::success('validate-sendernum', null, 'This API provider lacks the ability to register Sender Numbers.')),
		"This API provider lacks the ability to register Sender Numbers.\n",
	);
});

test('text: info table', function () {
	$out = renderText(CliOutcome::success('info', [
		'provider' => 'Test (v1)',
		'extra'    => ['Configured' => 'Yes'],
		'constants' => [
			['key' => 'SMS_A', 'required' => 'required', 'defined' => true, 'value' => 'secret'],
			['key' => 'SMS_LONGER_NAME', 'required' => 'optional', 'defined' => false, 'value' => null],
		],
	]));
	assert_contains($out, "Provider: Test (v1)\nConfigured: Yes\n\n");
	assert_contains($out, sprintf("  %-15s  %-10s  %s\n", 'SMS_A', 'required', "'secret'"));
	assert_contains($out, sprintf("  %-15s  %-10s  %s\n", 'SMS_LONGER_NAME', 'optional', '<not defined>'));
});

test('text: unregistered sender numbers with attempt dates', function () {
	assert_eq(
		renderText(CliOutcome::success('sendernums-unregistered', [])),
		"No unregistered sender numbers found.\n",
	);

	$rows = [
		['number' => '61491570313', 'status' => 'pending', 'registeredAt' => 1744784026],
		['number' => '61491570110', 'status' => 'unverified', 'registeredAt' => null],
		['number' => '61499999999', 'status' => null, 'registeredAt' => 1792108731],
	];
	assert_eq(
		renderText(CliOutcome::success('sendernums-unregistered', $rows)),
		"Unregistered sender numbers (3):\n"
		. "  61491570313  (pending, attempted 2025-04-16)\n"
		. "  61491570110  (unverified)\n"
		. "  61499999999  (attempted 2026-10-15)\n",
	);
});

test('json: unregistered sender numbers', function () {
	$data = renderJson(CliOutcome::success('sendernums-unregistered', [
		['number' => '61491570313', 'status' => 'pending', 'registeredAt' => 1744784026],
	]))['data'];
	assert_eq($data, [['number' => '61491570313', 'status' => 'pending', 'registeredAt' => 1744784026]]);
});

test('text: is-sendernum-registered, estimate and status', function () {
	assert_eq(
		renderText(CliOutcome::success('is-sendernum-registered', ['number' => '61491570157', 'approved' => true])),
		"61491570157 is an approved sender number.\n",
	);
	assert_eq(
		renderText(CliOutcome::success('is-sendernum-registered', ['number' => '61400000000', 'approved' => false])),
		"61400000000 is not an approved sender number.\n",
	);

	$gsm = \Sms\Cli\estimateMessage('Hello world', 5000);
	assert_eq(
		renderText(CliOutcome::success('estimate', $gsm)),
		"Encoding:  GSM\nSegments:  1\nCost:      5.00c per recipient\n",
	);

	$ucs2 = \Sms\Cli\estimateMessage(str_repeat('a', 70) . '😊', 5000);
	assert_contains(renderText(CliOutcome::success('estimate', $ucs2)), "Encoding:  UCS-2\n");
	assert_contains(renderText(CliOutcome::success('estimate', $ucs2)), 'Non-GSM:   😊 (forces UCS-2 encoding)' . "\n");

	assert_eq(
		renderText(CliOutcome::success('status', ['key' => '5centsmsv5', 'description' => '5CentSMS (v5)', 'operational' => true])),
		"Provider:     5CentSMS (v5)\nKey:          5centsmsv5\nOperational:  Yes\n",
	);
});

test('text: info shows key, capabilities, cost and deferred window', function () {
	$out = renderText(CliOutcome::success('info', [
		'provider' => 'Test (v1)',
		'key' => 'testv1',
		'extra' => [],
		'capabilities' => ['GET_BALANCE', 'DEFERRED_SEND'],
		'segmentCostMillicents' => 4300,
		'deferredSendMaxDelaySeconds' => 86400,
		'constants' => [],
	]));
	assert_contains($out, "Provider: Test (v1)\nKey: testv1\n");
	assert_contains($out, "Capabilities: GET_BALANCE, DEFERRED_SEND\n");
	assert_contains($out, "Segment cost: 4.30c per segment\n");
	assert_contains($out, "Max deferred send: 1 day\n");

	$unlimited = renderText(CliOutcome::success('info', [
		'provider' => 'Test (v1)', 'key' => 'k', 'extra' => [], 'capabilities' => [],
		'segmentCostMillicents' => 1000, 'deferredSendMaxDelaySeconds' => null, 'constants' => [],
	]));
	assert_contains($unlimited, "Capabilities: none\n");
	assert_contains($unlimited, "Max deferred send: unlimited\n");
});

test('estimateMessage(): segment boundaries match the statusline maths', function () {
	assert_eq(\Sms\Cli\estimateMessage(str_repeat('a', 160), 5000)['segments'], 1);
	assert_eq(\Sms\Cli\estimateMessage(str_repeat('a', 161), 5000)['segments'], 2);
	assert_eq(\Sms\Cli\estimateMessage(str_repeat('a', 306), 5000)['segments'], 2);
	assert_eq(\Sms\Cli\estimateMessage(str_repeat('a', 307), 5000)['segments'], 3);

	// UCS-2: 70 chars per segment, 67 when concatenated
	assert_eq(\Sms\Cli\estimateMessage(str_repeat('a', 70) . '😊', 5000)['segments'], 2);
	assert_eq(\Sms\Cli\estimateMessage(str_repeat('😊', 35), 5000)['segments'], 1);
	assert_eq(\Sms\Cli\estimateMessage(str_repeat('😊', 36), 5000)['segments'], 2);
	assert_eq(\Sms\Cli\estimateMessage(str_repeat('😊', 36), 5000)['encoding'], 'UCS-2');

	// GSM extended characters count double but stay GSM
	$euro = \Sms\Cli\estimateMessage(str_repeat('€', 80), 5000);
	assert_eq($euro['encoding'], 'GSM');
	assert_eq($euro['segments'], 1, '80 × 2 = 160 effective chars');
	assert_eq(\Sms\Cli\estimateMessage(str_repeat('€', 81), 5000)['segments'], 2);

	// Cost = segments × per-segment price, in millicents
	$cost = \Sms\Cli\estimateMessage(str_repeat('a', 161), 4300);
	assert_eq($cost['costPerRecipientMillicents'], 8600);

	// Non-GSM characters are reported for display
	assert_eq($cost['nonGsmChars'], []);
	assert_eq(\Sms\Cli\estimateMessage('Hi 😊', 5000)['nonGsmChars'], ['😊']);
});

// ===========================================================================
// JsonPresenter
// ===========================================================================

test('json: is-sendernum-registered, estimate and status payloads', function () {
	assert_eq(
		renderJson(CliOutcome::success('is-sendernum-registered', ['number' => '61491570157', 'approved' => true]))['data'],
		['number' => '61491570157', 'approved' => true],
	);

	$estimate = renderJson(CliOutcome::success('estimate', \Sms\Cli\estimateMessage('Hello', 5000)))['data'];
	assert_eq($estimate['encoding'], 'GSM');
	assert_eq($estimate['segments'], 1);
	assert_eq($estimate['costPerRecipientMillicents'], 5000);
	assert_eq($estimate['nonGsmChars'], []);

	$status = renderJson(CliOutcome::success('status', ['key' => 'k', 'description' => 'd', 'operational' => true]));
	assert_eq($status['data'], ['key' => 'k', 'description' => 'd', 'operational' => true]);
});

test('json: envelope for success, failure and note', function () {
	assert_eq(
		renderJson(CliOutcome::success('balance', 1372)),
		['ok' => true, 'action' => 'balance', 'data' => ['balance' => 1372]],
	);

	$failure = renderJson(CliOutcome::failure('balance', 'Unable to connect'));
	assert_eq($failure, ['ok' => false, 'action' => 'balance', 'error' => 'Unable to connect']);

	$note = renderJson(CliOutcome::success('optouts', null, 'This API provider does not support listing opt-outs.'));
	assert_eq($note['ok'], true);
	assert_eq($note['note'], 'This API provider does not support listing opt-outs.');
	assert_eq($note['data'], null);
});

test('json: sender IDs and numbers', function () {
	assert_eq(
		renderJson(CliOutcome::success('senderids', [
			['value' => 'MYCHURCH', 'acmaApproved' => true, 'registeredAt' => 1785788740],
			['value' => 'X', 'acmaApproved' => null, 'registeredAt' => null],
		]))['data'],
		[
			['value' => 'MYCHURCH', 'acmaApproved' => true, 'registeredAt' => 1785788740],
			['value' => 'X', 'acmaApproved' => null, 'registeredAt' => null],
		],
	);
	assert_eq(
		renderJson(CliOutcome::success('sendernums', [new PhoneNumber('61400000001')]))['data'],
		[['number' => '61400000001']],
	);
});

test('json: opt-outs and deliveries', function () {
	$optouts = renderJson(CliOutcome::success('optouts', [
		new OptOutEntry(new PhoneNumber('61400000001'), optedOutAt: 1700000000, name: 'Alex'),
	]))['data'];
	assert_eq($optouts, [['number' => '61400000001', 'name' => 'Alex', 'optedOutAt' => 1700000000]]);

	$list = renderJson(CliOutcome::success('list', ['deliveries' => [delivery()], 'since' => 1700000000]))['data'];
	assert_eq($list['since'], 1700000000);
	assert_eq(count($list['deliveries']), 1);
	assert_eq(
		array_keys($list['deliveries'][0]),
		['recipient', 'status', 'statusText', 'remoteId', 'sendTimestamp', 'deliveryTimestamp', 'message', 'statusDetail'],
	);
	assert_eq($list['deliveries'][0]['recipient'], '61400000001');
	assert_eq($list['deliveries'][0]['remoteId'], 'abc123');
});

test('json: send summary and registration step', function () {
	$recipients = [new PhoneNumber('61400000001')];
	$data = renderJson(CliOutcome::success('sms', [
		'summary' => new \Sms\AllSent($recipients, 'r1'),
		'deliveries' => [delivery()],
	]))['data'];
	assert_eq($data['summary'], ['type' => 'all_sent', 'recipients' => 1, 'remoteId' => 'r1']);
	assert_eq(count($data['deliveries']), 1);

	$partial = renderJson(CliOutcome::success('sms', [
		'summary' => new \Sms\PartialSuccess(successes: $recipients, failures: $recipients, remoteId: null),
		'deliveries' => [],
	]))['data'];
	assert_eq($partial['summary']['type'], 'partial');
	assert_eq($partial['summary']['sent'], 1);
	assert_eq($partial['summary']['failed'], 1);

	$step = renderJson(CliOutcome::success('validate-sendernum', [
		'step' => new RegistrationStep(
			message: 'Verification link sent.',
			number: '61400000001',
			fields: [new FormField('otp', 'Code', 'text', required: true)],
		),
	]))['data'];
	assert_eq($step['message'], 'Verification link sent.');
	assert_eq($step['number'], '61400000001');
	assert_eq($step['complete'], false);
	assert_eq($step['fields'][0]['name'], 'otp');
	assert_eq($step['fields'][0]['required'], true);
});

test('json: info masks credential constants only', function () {
	$data = renderJson(CliOutcome::success('info', [
		'provider' => 'Test (v1)',
		'extra'    => ['Configured' => 'Yes'],
		'constants' => [
			['key' => 'SMS_5CENTSMS_APIKEY', 'required' => 'required', 'defined' => true, 'value' => 'super-secret'],
			['key' => 'SMS_5CENTSMS_URL', 'required' => 'optional', 'defined' => true, 'value' => 'https://api.example/v5'],
			['key' => 'SMS_VERBOSE', 'required' => 'optional', 'defined' => false, 'value' => null],
		],
	]))['data'];

	assert_eq($data['provider'], 'Test (v1)');
	assert_eq($data['constants'][0]['value'], '****');
	assert_eq($data['constants'][1]['value'], 'https://api.example/v5');
	assert_eq($data['constants'][2]['value'], null);
});

test('json: delete and remove confirmations', function () {
	assert_eq(
		renderJson(CliOutcome::success('delete-sendernum', ['number' => '61400000001']))['data'],
		['number' => '61400000001', 'deleted' => true],
	);
	assert_eq(
		renderJson(CliOutcome::success('remove-optout', ['number' => '61400000001']))['data'],
		['number' => '61400000001', 'removed' => true],
	);
});

test('maskConstantValue(): credentials and HTTP templates are masked; other values are not', function () {
    assert_eq(\Sms\Cli\maskConstantValue('SMS_CELLCAST_APIKEY', 'secret'), '****');
    assert_eq(\Sms\Cli\maskConstantValue('SMS_HTTP_POST_TEMPLATE', 'user=me&pass=p'), '****');
    assert_eq(\Sms\Cli\maskConstantValue('SMS_SENDER', 'StBarnabas'), 'StBarnabas');
    // An unset credential stays empty rather than becoming a misleading mask.
    assert_eq(\Sms\Cli\maskConstantValue('SMS_CELLCAST_APIKEY', ''), '');
});

test('text: complete-sendernum-validation with no parameters says nothing was sent', function () {
    $out = renderText(\Sms\Cli\CliOutcome::success('complete-sendernum-validation', null,
        'Supply the validation parameters from the provider, e.g. --otp=<code>. Nothing was sent.'));
    assert_contains($out, 'Nothing was sent');
});
