<?php

/**
 * Unit tests for Sms\gsmSegmentCount().
 *
 * Spec ported from the former resources/js/jethro-sms-test.js (now deleted —
 * see Jethro's docs/docs/developer/sms/SMS_DATASTAR.md). Boundaries:
 * 1, 160, 161, 306, 307, 459, 460.
 *
 * Reference: jethro-sms/src/sms_statusline.php gsmSegmentCount().
 */

namespace Test\Sms\Statusline;

use function \Test\{test, assert_eq};
use function \Sms\gsmSegmentCount;

require_once __DIR__ . '/../../src/load.php';

test('1 char -> 1 segment', function () {
    assert_eq(gsmSegmentCount(1), 1);
});

test('160 chars -> 1 segment (max single)', function () {
    assert_eq(gsmSegmentCount(160), 1);
});

test('161 chars -> 2 segments (first multi)', function () {
    assert_eq(gsmSegmentCount(161), 2);
});

test('306 chars -> 2 segments (exactly fills 2)', function () {
    assert_eq(gsmSegmentCount(306), 2);
});

test('307 chars -> 3 segments (overflows 3rd)', function () {
    assert_eq(gsmSegmentCount(307), 3);
});

test('459 chars -> 3 segments (exactly fills 3)', function () {
    assert_eq(gsmSegmentCount(459), 3);
});

test('460 chars -> 4 segments (overflows 4th)', function () {
    assert_eq(gsmSegmentCount(460), 4);
});
