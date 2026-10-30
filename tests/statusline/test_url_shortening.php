<?php

/**
 * Unit tests for Sms\shortenUrlsInText() — estimates the message
 * after server-side URL shortening for statusline/preview character counts.
 *
 * The regex here MUST stay in sync with the auto-shorten regex in
 * include/jethro_sms.php sendSms() ('{(https?://[^\s"\')\][<>]+)}'), which
 * wraps long bare URLs in a %(shorten "...")% token. This function instead
 * substitutes a fixed 26-char GSM-safe placeholder for display/counting
 * purposes, mirroring the former JethroSMS.shortenUrlsInText in
 * resources/js/jethro-sms.js (now deleted — see
 * Jethro's docs/docs/developer/sms/SMS_DATASTAR.md).
 *
 * Reference: jethro-sms/src/sms_statusline.php shortenUrlsInText(),
 * include/jethro_sms.php sendSms() (~line 186).
 */

namespace Test\Sms\Statusline;

use function \Test\{test, assert_eq};
use function \Sms\shortenUrlsInText;

require_once __DIR__ . '/../../src/load.php';

test('long URL (> 26 chars) is replaced by a 26-char placeholder', function () {
    $in = 'Click https://www.example.com/very-long-path-here for details';
    $out = shortenUrlsInText($in);
    assert_eq($out, 'Click https://jethro.au/s/xxxxxx for details');
});

test('placeholder is exactly 26 characters', function () {
    $url = 'https://www.example.com/very-long-path-that-is-long';
    $out = shortenUrlsInText($url);
    assert_eq(strlen($out), 26);
});

test('URL exactly 26 chars is left unchanged', function () {
    $url = 'https://jethro.au/abcdefg'; // 25 chars; bump to exactly 26
    $url26 = 'https://jethro.au/abcdefgh'; // 26 chars
    assert_eq(strlen($url26), 26);
    assert_eq(shortenUrlsInText($url26), $url26);
});

test('URL shorter than 26 chars is left unchanged', function () {
    $url = 'http://x.co/a';
    assert_eq(shortenUrlsInText("See $url for it"), "See $url for it");
});

test('no URL in text -> text unchanged', function () {
    $text = 'Hello, no links here.';
    assert_eq(shortenUrlsInText($text), $text);
});

test('multiple long URLs are all shortened', function () {
    $in = 'First https://www.example.com/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa then https://www.example.com/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb end';
    $out = shortenUrlsInText($in);
    assert_eq($out, 'First https://jethro.au/s/xxxxxx then https://jethro.au/s/xxxxxx end');
});

test('matches send-path character-class exclusions: stops at quote/bracket/angle-bracket', function () {
    $in = '(https://www.example.com/very-long-path-here)';
    $out = shortenUrlsInText($in);
    // The trailing ")" is excluded from the URL match, so it survives outside the placeholder.
    assert_eq($out, '(https://jethro.au/s/xxxxxx)');
});
