<?php

/**
 * Unit tests for Sms\renderPreviewPanel() — the preview table, one row per message.
 *
 * Ports the HTML structure and override-highlight behaviour from the former
 * resources/js/jethro-sms.js renderPreviewPanel() (now on the server — see
 * Jethro's docs/docs/developer/sms/SMS_DATASTAR.md).
 *
 * Reference: jethro-sms/src/sms_statusline.php renderPreviewPanel().
 */

namespace Test\Sms\Statusline;

use function \Test\{test, assert_eq, assert_contains, assert_not_contains};
use function \Sms\renderPreviewPanel;

require_once __DIR__ . '/../../src/load.php';

test('empty deliveries -> empty string', function () {
    assert_eq(renderPreviewPanel([], []), '');
});

test('one row per delivery', function () {
    $deliveries = [
        ['key' => '1', 'name' => 'Alice', 'message' => 'Hi Alice', 'status' => 'queued'],
        ['key' => '2', 'name' => 'Bob', 'message' => 'Hi Bob', 'status' => 'queued'],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_eq(substr_count($html, 'class="sms-preview-msg"'), 2);
    assert_contains($html, 'Alice');
    assert_contains($html, 'Hi Bob');
});

test('names and messages are HTML-escaped', function () {
    $deliveries = [
        ['key' => '1', 'name' => '<b>Eve</b>', 'message' => 'a & b <script>', 'status' => 'queued'],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_contains($html, '&lt;b&gt;Eve&lt;/b&gt;');
    assert_contains($html, 'a &amp; b &lt;script&gt;');
    assert_not_contains($html, '<script>');
});

test('an entity typed into a message is shown literally, not decoded', function () {
    // The recipient receives the characters "&amp;", so the preview must show them.
    $deliveries = [
        ['key' => '1', 'name' => 'Dan', 'message' => 'Fish &amp; chips', 'status' => 'queued'],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_contains($html, 'Fish &amp;amp; chips');
});

test('overridden row is highlighted (#fff3cd) and shows the override text', function () {
    $deliveries = [
        ['key' => '7', 'name' => 'Carol', 'message' => 'original', 'status' => 'queued'],
    ];
    // PHP parses message_overrides[7] into int key 7; the row key is the string '7'.
    $html = renderPreviewPanel($deliveries, [7 => 'EDITED TEXT']);
    assert_contains($html, '#fff3cd');
    assert_contains($html, 'EDITED TEXT');
    assert_not_contains($html, 'original');
});

test('non-overridden row has no highlight', function () {
    $deliveries = [
        ['key' => '7', 'name' => 'Carol', 'message' => 'original', 'status' => 'queued'],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_not_contains($html, '#fff3cd');
    assert_contains($html, 'original');
});

test('a shared-mobile row (key 1_2) posts its override as message_overrides[1_2], highlights by that key, and lists one name per line', function () {
    $deliveries = [
        ['key' => '1_2', 'name' => "Alice\nBob", 'message' => 'Hi Alice & Bob', 'status' => 'queued'],
    ];
    $html = renderPreviewPanel($deliveries, ['1_2' => 'EDITED']);
    assert_contains($html, "'message_overrides[1_2]'", 'the send path expands this key to both people');
    assert_contains($html, '#fff3cd');
    assert_contains($html, 'EDITED');
    assert_contains($html, 'Alice</span><br><span style="white-space:nowrap">Bob');
});

test('row keys are reduced to word characters before landing in Datastar expressions', function () {
    $deliveries = [
        ['key' => "1');alert(1);('", 'name' => 'X', 'message' => 'm', 'status' => 'queued'],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_not_contains($html, 'alert(1)');
    assert_contains($html, "message_overrides[1alert1]");
});

test('empty message renders as (empty) placeholder', function () {
    $deliveries = [
        ['key' => '1', 'name' => 'Alice', 'message' => '', 'status' => 'queued'],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_contains($html, '(empty)');
});

test('missing name falls back to #key', function () {
    $deliveries = [
        ['key' => '42', 'name' => '', 'message' => 'hello', 'status' => 'queued'],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_contains($html, '#42');
});

test('an opted-out row is shown greyed, says it will not be sent, and has no edit control', function () {
    $deliveries = [
        ['key' => '9', 'name' => 'Jamison Williamson', 'message' => 'Hi Jamison', 'status' => 'opted-out'],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_contains($html, 'sms-preview-optedout');
    assert_contains($html, 'Opted out of SMS');
    assert_contains($html, 'Jamison Williamson');
    assert_not_contains($html, 'sms-preview-edit');
    assert_not_contains($html, 'Hi Jamison');
});
