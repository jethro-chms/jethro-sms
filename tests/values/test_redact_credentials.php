<?php

/**
 * Unit tests for redactCredentials() — verbose HTTP logs (SMS_VERBOSE) must
 * never contain provider credentials.
 *
 * Reference: jethro-sms/src/HttpClient.php redactCredentials(), LoggingHttpClient.
 */

namespace Test\Sms\Values;

use function \Test\{test, assert_eq};
use function \Sms\redactCredentials;

require_once __DIR__ . '/../../src/load.php';

test('5CentSMS JSON body: key-id and key-secret masked, other fields kept', function () {
    $body = json_encode(['key-id' => 'abc123', 'key-secret' => 'S3cr"et', 'to' => '0400123456']);
    assert_eq(
        redactCredentials($body),
        '{"key-id":"****","key-secret":"****","to":"0400123456"}'
    );
});

test('Cellcast bearer token in Authorization header masked', function () {
    assert_eq(
        redactCredentials("Authorization: Bearer tok.en-123\r\nContent-Type: application/json\r\n"),
        "Authorization: Bearer ****\r\nContent-Type: application/json\r\n"
    );
});

test('form/query parameters: password and apikey masked, username and message kept', function () {
    assert_eq(
        redactCredentials('https://api.example/send?username=bob&password=hunter2&to=0400&apikey=K1'),
        'https://api.example/send?username=bob&password=****&to=0400&apikey=****'
    );
    assert_eq(
        redactCredentials('password=hunter2&message=hi'),
        'password=****&message=hi'
    );
});

test('text without credentials is unchanged', function () {
    $s = '{"messages":[{"destination":"61400123456","message_text":"Your key is under the mat"}]}';
    assert_eq(redactCredentials($s), $s);
});

test('header-style credentials (SMS_HTTP_HEADER_TEMPLATE) masked, other headers kept', function () {
    assert_eq(
        redactCredentials("User: me@example.com\r\nApi-Key: MyAPIKey\r\nX-Auth-Token: tok en\r\nContent-Type: text/plain\r\n"),
        "User: me@example.com\r\nApi-Key: ****\r\nX-Auth-Token: ****\r\nContent-Type: text/plain\r\n"
    );
});
