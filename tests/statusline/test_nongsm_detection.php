<?php

/**
 * Unit tests for Sms\getNonGsmChars() and the unicode-policy decision
 * inputs (disabled / when_free>70 / enabled modes).
 *
 * CRITICAL ENCODING CASE: astral characters (e.g. emoji like 😊) are
 * surrogate pairs in UTF-16 and must count as TWO non-GSM "characters",
 * matching the JS spec exactly (former resources/js/jethro-sms-test.js:
 * `getNonGsmChars('😊').length === 2`). See the encoding note at the top of
 * jethro-sms/src/sms_statusline.php.
 *
 * Reference: jethro-sms/src/sms_statusline.php getNonGsmChars(), isGsm0338().
 */

namespace Test\Sms\Statusline;

use function \Test\{test, assert_eq, assert_true, assert_false};
use function \Sms\{getNonGsmChars, isGsm0338, utf16Length};

require_once __DIR__ . '/../../src/load.php';

test('"Hello" -> no non-GSM chars', function () {
    assert_eq(count(getNonGsmChars('Hello')), 0);
});

test('"😊" -> 2 non-GSM chars (surrogate pair)', function () {
    assert_eq(count(getNonGsmChars('😊')), 2);
});

test('"Hello😊" -> 2 non-GSM chars (surrogate pair)', function () {
    assert_eq(count(getNonGsmChars('Hello😊')), 2);
});

test('"€" -> 0 non-GSM (is extended GSM, not blocked)', function () {
    assert_eq(count(getNonGsmChars('€')), 0);
});

test('isGsm0338() accepts basic and extended chars', function () {
    assert_true(isGsm0338('a'));
    assert_true(isGsm0338('€'));
    assert_true(isGsm0338('{'));
});

test('isGsm0338() rejects a curly/smart quote (U+2019)', function () {
    assert_false(isGsm0338("\u{2019}"));
});

test('curly quote (U+2019) is detected as non-GSM', function () {
    $text = "It\u{2019}s here";
    assert_true(count(getNonGsmChars($text)) > 0);
});

test('unicode mode does not affect getNonGsmChars() — detection is mode-independent', function () {
    // getNonGsmChars() never inspects a "mode" flag; this just documents
    // that the same text always yields the same detection regardless of
    // how callers later interpret unicodeMode.
    assert_eq(count(getNonGsmChars('Hello world')), 0);
    assert_eq(count(getNonGsmChars('Hello😊')), 2);
});

test('"disabled" mode would block: any non-GSM char present', function () {
    $nonGsm = getNonGsmChars('Hello😊');
    assert_true($nonGsm !== []);
});

test('"when_free" mode: non-GSM chars in a long message (>70 chars) would block', function () {
    $text = 'Hello😊' . str_repeat('a', 65); // 72 UTF-16 units
    $nonGsm = getNonGsmChars($text);
    assert_true($nonGsm !== [] && utf16Length($text) > 70);
});

test('"when_free" mode: non-GSM chars in a short message (<=70 chars) would NOT block', function () {
    $text = 'Hi😊'; // 4 UTF-16 units
    $nonGsm = getNonGsmChars($text);
    assert_false($nonGsm !== [] && utf16Length($text) > 70);
});

test('all-GSM text has no non-GSM chars regardless of length', function () {
    assert_eq(count(getNonGsmChars(str_repeat('a', 200))), 0);
});

test('GSM table: currency sign and form feed are GSM; ä still is; emoji is not', function () {
    // The basic table's 0x24 is the currency sign (¤), not a second '$'
    // (0x02 holds '$'); form feed 0x0C was missing entirely.
    assert_true(\Sms\isGsm0338("\xC2\xA4"), '¤ (currency sign) is GSM');
    assert_true(\Sms\isGsm0338("\x0C"), 'form feed is GSM');
    assert_true(\Sms\isGsm0338('$'), '$ is GSM');
    assert_true(\Sms\isGsm0338("\xC3\xA4"), 'ä is GSM');
    assert_false(\Sms\isGsm0338("\xE2\x98\xBA"), 'emoji is not GSM');
});
