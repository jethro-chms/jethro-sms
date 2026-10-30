<?php

/**
 * Unit tests for \Sms\senderFromString(): configured sender string →
 * PhoneNumber | SenderID | null.
 *
 * Reference: jethro-sms/src/functions.php senderFromString()
 */

namespace Test\Sms\Values;

use function \Test\{test, assert_true, assert_eq};
use \Sms\PhoneNumber;
use \Sms\SenderID;
use function \Sms\senderFromString;

require_once __DIR__ . '/../../src/load.php';

test('alphanumeric sender is a SenderID, kept verbatim', function () {
    $s = senderFromString('StBarnabas');
    assert_true($s instanceof SenderID);
    assert_eq((string) $s, 'StBarnabas');
});

test('spaced local mobile is a PhoneNumber', function () {
    $s = senderFromString('0412 345 678');
    assert_true($s instanceof PhoneNumber);
    assert_eq((string) $s, '0412345678');
});

test('+international mobile is a PhoneNumber', function () {
    $s = senderFromString('+61412345678');
    assert_true($s instanceof PhoneNumber);
    assert_eq((string) $s, '61412345678');
});

test('short all-digit code (<7) is a SenderID', function () {
    assert_true(senderFromString('12345') instanceof SenderID);
});

test('7 digits is the PhoneNumber boundary', function () {
    assert_true(senderFromString('1234567') instanceof PhoneNumber);
    assert_true(senderFromString('123456') instanceof SenderID);
});

test('numeric-looking non-digits (1e5, 12.5) are not phone numbers', function () {
    assert_true(senderFromString('1e5') instanceof SenderID);
    assert_true(senderFromString('1234567.5') instanceof SenderID);
});

test('empty and whitespace-only are null', function () {
    assert_eq(senderFromString(''), null);
    assert_eq(senderFromString("  \t"), null);
});

test('surrounding whitespace is trimmed from SenderIDs', function () {
    assert_eq((string) senderFromString('  StBarnabas '), 'StBarnabas');
});
