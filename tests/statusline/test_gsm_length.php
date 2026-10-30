<?php

/**
 * Unit tests for Sms\gsmLength() — extended GSM 03.38 characters
 * (€, {, }, [, ], ~, |, ^, \) count as 2 toward effective length.
 *
 * Spec ported from the former resources/js/jethro-sms-test.js (now deleted —
 * see Jethro's docs/docs/developer/sms/SMS_DATASTAR.md).
 *
 * Reference: jethro-sms/src/sms_statusline.php gsmLength().
 */

namespace Test\Sms\Statusline;

use function \Test\{test, assert_eq};
use function \Sms\{gsmLength, gsmSegmentCount};

require_once __DIR__ . '/../../src/load.php';

test('"a" -> effective length 1', function () {
    assert_eq(gsmLength('a'), 1);
});

test('"€" -> effective length 2 (extended)', function () {
    assert_eq(gsmLength('€'), 2);
});

test('"a€" -> effective length 3', function () {
    assert_eq(gsmLength('a€'), 3);
});

test('"€€" -> effective length 4', function () {
    assert_eq(gsmLength('€€'), 4);
});

test('all extended chars count as 2: { } [ ~ ] | ^ \\', function () {
    foreach (['{', '}', '[', '~', ']', '|', '^', '\\'] as $c) {
        assert_eq(gsmLength($c), 2, "expected effective length 2 for '$c'");
    }
});

test('80 x € (effective length 160) -> 1 segment', function () {
    assert_eq(gsmSegmentCount(gsmLength(str_repeat('€', 80))), 1);
});

test('81 x € (effective length 162) -> 2 segments', function () {
    assert_eq(gsmSegmentCount(gsmLength(str_repeat('€', 81))), 2);
});

test('153 x € (effective length 306) -> 2 segments (exactly fills 2)', function () {
    assert_eq(gsmSegmentCount(gsmLength(str_repeat('€', 153))), 2);
});

test('154 x € (effective length 308) -> 3 segments', function () {
    assert_eq(gsmSegmentCount(gsmLength(str_repeat('€', 154))), 3);
});
