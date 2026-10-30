<?php

/**
 * Unit tests for CellcastSmsProvider — Sender ID registration.
 *
 * Covers registerSenderId() Phase 1 (schema) and Phase 2 (submit).
 * Uses a spy HttpClient to inspect outgoing requests and a controllable
 * fake to simulate API responses without hitting the network.
 */

namespace Test\Sms\Cellcast;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_contains, assert_not_contains, assert_throws};
use \Test\AssertionFailed;
use \Sms\{
	SmsProvider, SmsRecipient, SmsSender, PhoneNumber, SenderID,
	HttpClient, HttpRequest, HttpResponse,
};
use \Sms\Providers\CellcastSmsProvider;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * Fake HttpClient that returns a fixed response.
 */
final readonly class FakeHttpClient implements HttpClient
{
	public function __construct(
		private \Result $response,
	) {}

	public function send(HttpRequest $request): \Result
	{
		return $this->response;
	}
}

/**
 * Spy HttpClient that captures the last request + returns a fixed response.
 */
final class SpyHttpClient implements HttpClient
{
	public ?HttpRequest $lastRequest = null;

	public function __construct(
		private \Result $response,
	) {}

	public function send(HttpRequest $request): \Result
	{
		$this->lastRequest = $request;
		return $this->response;
	}
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeProvider(HttpClient $http): SmsProvider
{
	return new CellcastSmsProvider(
		apiToken: 'test-token',
		url: 'https://api.cellcast.com',
		httpClient: $http,
	);
}

function successResponse(string $body): \Result
{
	return \Result::success(new HttpResponse($body));
}

function failureResponse(string $error): \Result
{
	return \Result::failure($error);
}

// ===========================================================================
// Phase 1 — Field schema
// ===========================================================================

test('Phase 1: both null returns bare schema', function () {
	$provider = makeProvider(new FakeHttpClient(successResponse('{}')));
	$result = $provider->registerSenderId(null, null);
	assert_true($result->isSuccess(), 'Expected success');

	$step = $result->getValue();
	assert_true($step->fields !== [], 'Expected non-empty fields');
});

test('Phase 1: schema includes businessname with sender-id value pre-populated', function () {
	$provider = makeProvider(new FakeHttpClient(successResponse('{}')));
	$result = $provider->registerSenderId(new SenderID('MYCHURCH'), null);
	assert_true($result->isSuccess());

	$step = $result->getValue();

	$bizField = null;
	foreach ($step->fields as $f) {
		if ($f->name === 'businessname') {
			$bizField = $f;
			break;
		}
	}
	assert_true($bizField !== null, 'Expected businessname field');
	assert_eq($bizField->value, 'MYCHURCH');
});

test('Phase 1: schema includes required fields', function () {
	$provider = makeProvider(new FakeHttpClient(successResponse('{}')));
	$result = $provider->registerSenderId(new SenderID('X'), null);
	$step = $result->getValue();

	$names = array_map(fn($f) => $f->name, $step->fields);
	$expected = [
		'businessname', 'descriptionInternal', 'purposeOfUse',
		'ownership', 'company_name', 'company_abn',
		'company_website', 'company_address',
		'requestor_firstName', 'requestor_lastName', 'requestor_position',
		'requestor_phoneNumber', 'requestor_email',
		'customerContact',
	];
	foreach ($expected as $name) {
		assert_true(in_array($name, $names, true), "Expected field '$name' in schema");
	}
});

test('Phase 1: purposeOfUse field has select options', function () {
	$provider = makeProvider(new FakeHttpClient(successResponse('{}')));
	$result = $provider->registerSenderId(new SenderID('X'), null);
	$step = $result->getValue();

	foreach ($step->fields as $f) {
		if ($f->name === 'purposeOfUse') {
			assert_eq($f->type, 'select');
			assert_true($f->options !== null);
			assert_true(count($f->options) > 0);
			return;
		}
	}
	throw new AssertionFailed('purposeOfUse field not found in schema');
});

// ===========================================================================
// Phase 2 — Submit
// ===========================================================================

test('Phase 2: sends POST to /business/add', function () {
	$spy = new SpyHttpClient(successResponse(json_encode(['status' => true, 'message' => 'OK'])));
	$provider = makeProvider($spy);

	$provider->registerSenderId(
		new SenderID('MYCHURCH'),
		[
			'businessname' => 'MYCHURCH',
			'descriptionInternal' => 'Church SMS',
			'purposeOfUse' => 'Transactional SMS',
			'ownership' => '1',
			'company_name' => 'My Church Inc',
			'company_abn' => '12345678901',
			'company_website' => 'https://example.com',
			'company_address' => '123 Main St',
			'requestor_firstName' => 'John',
			'requestor_lastName' => 'Doe',
			'requestor_position' => 'Pastor',
			'requestor_phoneNumber' => '0400000000',
			'requestor_email' => 'john@example.com',
			'customerContact' => '0400000001',
		],
	);

	assert_true($spy->lastRequest !== null, 'Expected HTTP request');
	assert_eq($spy->lastRequest->method, 'POST');
	assert_contains($spy->lastRequest->url, '/business/add');
});

test('Phase 2: request body contains businessname', function () {
	$spy = new SpyHttpClient(successResponse(json_encode(['status' => true])));
	$provider = makeProvider($spy);

	$provider->registerSenderId(
		new SenderID('MYCHURCH'),
		['businessname' => 'MYCHURCH', 'ownership' => '1'],
	);

	$body = json_decode($spy->lastRequest->body, true);
	assert_eq($body['businessname'], 'MYCHURCH');
});

test('Phase 2: request body includes companyInformation when provided', function () {
	$spy = new SpyHttpClient(successResponse(json_encode(['status' => true])));
	$provider = makeProvider($spy);

	$provider->registerSenderId(
		new SenderID('X'),
		[
			'businessname' => 'X',
			'ownership' => '1',
			'company_name' => 'Acme Corp',
			'company_abn' => '999',
		],
	);

	$body = json_decode($spy->lastRequest->body, true);
	assert_true(isset($body['companyInformation']), 'Expected companyInformation');
	assert_eq($body['companyInformation']['name'], 'Acme Corp');
	assert_eq($body['companyInformation']['abn'], '999');
});

test('Phase 2: request body includes requestorContact when provided', function () {
	$spy = new SpyHttpClient(successResponse(json_encode(['status' => true])));
	$provider = makeProvider($spy);

	$provider->registerSenderId(
		new SenderID('X'),
		[
			'businessname' => 'X',
			'ownership' => '1',
			'requestor_firstName' => 'Jane',
			'requestor_lastName' => 'Smith',
		],
	);

	$body = json_decode($spy->lastRequest->body, true);
	assert_true(isset($body['requestorContact']), 'Expected requestorContact');
	assert_eq($body['requestorContact']['firstName'], 'Jane');
	assert_eq($body['requestorContact']['lastName'], 'Smith');
});

test('Phase 2: ownership field is boolean', function () {
	$spy = new SpyHttpClient(successResponse(json_encode(['status' => true])));
	$provider = makeProvider($spy);

	$provider->registerSenderId(
		new SenderID('X'),
		['businessname' => 'X', 'ownership' => '1'],
	);

	$body = json_decode($spy->lastRequest->body, true);
	assert_eq($body['ownership'], true);
});

test('Phase 2: optional fields omitted from body when not provided', function () {
	$spy = new SpyHttpClient(successResponse(json_encode(['status' => true])));
	$provider = makeProvider($spy);

	$provider->registerSenderId(
		new SenderID('X'),
		['businessname' => 'X', 'ownership' => '1'],
	);

	$body = json_decode($spy->lastRequest->body, true);
	assert_false(isset($body['customerContact']), 'customerContact should be absent when empty');
	assert_false(isset($body['descriptionInternal']), 'descriptionInternal should be absent when empty');
});

test('Phase 2: empty businessname returns failure', function () {
	$provider = makeProvider(new FakeHttpClient(successResponse('{}')));
	$result = $provider->registerSenderId(
		new SenderID('X'),
		['businessname' => '', 'ownership' => '1'],
	);
	assert_true($result->isFailure());
	assert_contains($result->getError(), 'businessname');
});

test('Phase 2: network failure propagates', function () {
	$provider = makeProvider(new FakeHttpClient(failureResponse('Connection refused')));
	$result = $provider->registerSenderId(
		new SenderID('X'),
		['businessname' => 'X', 'ownership' => '1'],
	);
	assert_true($result->isFailure());
	assert_contains($result->getError(), 'Connection refused');
});

test('Phase 2: API success returns structured RegistrationStep', function () {
	$response = ['status' => true, 'message' => 'Business added', 'data' => ['id' => 42]];
	$provider = makeProvider(new FakeHttpClient(successResponse(json_encode($response))));
	$result = $provider->registerSenderId(
		new SenderID('X'),
		['businessname' => 'X', 'ownership' => '1'],
	);
	assert_true($result->isSuccess());
	$step = $result->getValue();
	assert_true($step instanceof \Sms\RegistrationStep, 'Expected RegistrationStep');
	assert_contains($step->message, 'Business added');
	assert_true($step->form !== [], 'Expected non-empty form');
});

test('Phase 2: malformed JSON body returns failure', function () {
	$provider = makeProvider(new FakeHttpClient(successResponse('not-json')));
	$result = $provider->registerSenderId(
		new SenderID('X'),
		['businessname' => 'X', 'ownership' => '1'],
	);
	assert_true($result->isFailure());
});

test('Phase 2: Auth header includes Bearer token', function () {
	$spy = new SpyHttpClient(successResponse(json_encode(['status' => true])));
	$provider = makeProvider($spy);

	$provider->registerSenderId(
		new SenderID('X'),
		['businessname' => 'X', 'ownership' => '1'],
	);

	assert_contains($spy->lastRequest->headers, 'Authorization: Bearer test-token');
});
