<?php

/**
 * Unit tests for Sms\ucs2SegmentCount(). Boundaries: <=70 -> 1,
 * 71 -> 2, 134 -> 2, 135 -> 3, 201 -> 3, 202 -> 4.
 *
 * Spec ported from the former resources/js/jethro-sms-test.js (now deleted —
 * see Jethro's docs/docs/developer/sms/SMS_DATASTAR.md).
 *
 * Reference: jethro-sms/src/sms_statusline.php ucs2SegmentCount().
 */

namespace Test\Sms\Statusline;

use function \Test\{test, assert_eq};
use function \Sms\ucs2SegmentCount;

require_once __DIR__ . '/../../src/load.php';

test('1 UCS-2 char -> 1 segment', function () {
    assert_eq(ucs2SegmentCount(1), 1);
});

test('70 UCS-2 chars -> 1 segment (max single)', function () {
    assert_eq(ucs2SegmentCount(70), 1);
});

test('71 UCS-2 chars -> 2 segments (first multi)', function () {
    assert_eq(ucs2SegmentCount(71), 2);
});

test('134 UCS-2 chars -> 2 segments (exactly fills 2)', function () {
    assert_eq(ucs2SegmentCount(134), 2);
});

test('135 UCS-2 chars -> 3 segments (overflows 3rd)', function () {
    assert_eq(ucs2SegmentCount(135), 3);
});

test('201 UCS-2 chars -> 3 segments (exactly fills 3)', function () {
    assert_eq(ucs2SegmentCount(201), 3);
});

test('202 UCS-2 chars -> 4 segments (overflows 4th)', function () {
    assert_eq(ucs2SegmentCount(202), 4);
});
