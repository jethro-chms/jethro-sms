<?php

/**
 * ============================================================================
 * SMS statusline / preview — pure PHP core + HTML renderers
 * ============================================================================
 *
 * Namespace: Sms
 *
 * Server-side port of the cost/segment/preview business logic that used to
 * live in resources/js/jethro-sms.js (renderStatusline, buildCostLine,
 * gsmLength/gsmSegmentCount/ucs2SegmentCount/getNonGsmChars/shortenUrlsInText,
 * renderPreviewPanel). See Jethro's docs/docs/developer/sms/SMS_DATASTAR.md
 * for the architecture this supports (Datastar SSE endpoint
 * calls/call_sms_statusline.class.php) and
 * Jethro's docs/docs/developer/sms/smsarchitecture.md for the wider SMS
 * subsystem.
 *
 * All functions in the "pure core" section are side-effect-free (no globals,
 * no echo) and are exhaustively unit-tested in jethro-sms/tests/statusline/ — those
 * tests are a direct port of the former resources/js/jethro-sms-test.js
 * assertions and are the spec for the maths. Do not change the maths without
 * updating both the tests and this docblock.
 *
 * ----------------------------------------------------------------------------
 * ENCODING NOTE — UTF-16 vs UTF-8 code points
 * ----------------------------------------------------------------------------
 * The JS spec counts UTF-16 code *units* (JavaScript's `String.length` and
 * `String.charAt()`), NOT Unicode code points. An astral character (e.g. an
 * emoji like 😊) is a surrogate pair in UTF-16 and therefore counts as TWO
 * units — see jethro-sms-test.js: `getNonGsmChars('😊').length === 2`.
 *
 * mb_strlen($s, 'UTF-8') counts code points (1 for 😊), which would NOT match.
 * Instead we convert to UTF-16BE and divide the byte length by 2 to get the
 * UTF-16 code-unit count (utf16Length()), and split into 2-byte code units
 * to iterate "characters" the way JS charAt() does (utf16Units()). Lone
 * surrogate halves (0xD800-0xDFFF) can never be GSM 03.38 characters (the
 * whole GSM alphabet lives in the Latin/Greek part of the Basic Multilingual
 * Plane), so they are always treated as non-GSM without needing to decode
 * them back to a UTF-8 character.
 *
 * This is pinned by jethro-sms/tests/statusline/test_nongsm_detection.php and
 * test_gsm_length.php — verify boundary cases there before changing.
 */

namespace Sms;

// =============================================================================
// Pure core (direct ports; spec = former jethro-sms-test.js, now
// jethro-sms/tests/statusline/test_*.php)
// =============================================================================

/**
 * Regex matching any GSM 03.38 character (basic or extended), applied to a
 * single UTF-8 character. Mirrors JethroSMS.GSM0338_RE in the former
 * jethro-sms.js.
 */
const GSM0338_RE = '/^[@£$¥èéùìòÇ\n\fØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\x1BÆæßÉ !"#\xA4%&\'()*+,\-.\/0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà€{}\[~\]|^\\\\]$/u';

/** Extended GSM 03.38 chars — each counts as 2 in effective length. */
const GSM0338_EXTENDED_RE = '/[€{}\[~\]|^\\\\]/u';

/**
 * Count of UTF-16 code units in $text — the PHP equivalent of JS
 * `text.length`. NOT the same as mb_strlen() (code points): astral
 * characters (surrogate pairs) count as 2, matching JS exactly.
 */
function utf16Length(string $text): int
{
    if ($text === '') {
        return 0;
    }
    return (int) (strlen(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')) / 2);
}

/**
 * Split $text into an array of "characters" the way JS `charAt()` iteration
 * does: by UTF-16 code unit. A surrogate pair (astral character) yields two
 * entries, each the lone surrogate's UTF-8 re-encoding is NOT meaningful on
 * its own — callers must treat a surrogate-half entry as automatically
 * non-GSM (see isGsm0338()) rather than trying to render it.
 *
 * @return string[] One entry per UTF-16 code unit, in original order.
 */
function utf16Units(string $text): array
{
    if ($text === '') {
        return [];
    }
    $u16 = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
    $units = [];
    $len = strlen($u16);
    for ($i = 0; $i < $len; $i += 2) {
        $pair = substr($u16, $i, 2);
        $code = unpack('n', $pair)[1];
        if ($code >= 0xD800 && $code <= 0xDFFF) {
            // Lone surrogate half — cannot be decoded to a standalone UTF-8
            // character. Encode a sentinel; isGsm0338() treats any string
            // produced from a surrogate-range code unit as non-GSM without
            // inspecting it further (see isSurrogateUnit()).
            $units[] = "\xEF\xBF\xBD"; // U+FFFD replacement char placeholder
        } else {
            $units[] = mb_convert_encoding($pair, 'UTF-8', 'UTF-16BE');
        }
    }
    return $units;
}

/**
 * Returns true if the UTF-16BE 2-byte code unit at $pair is a surrogate half
 * (0xD800-0xDFFF) — i.e. one half of an astral character. Internal helper
 * used by getNonGsmChars()/gsmLength() to classify code units without
 * relying on the lossy placeholder produced by utf16Units().
 */
function isSurrogateCodeUnit(string $pair): bool
{
    $code = unpack('n', $pair)[1];
    return $code >= 0xD800 && $code <= 0xDFFF;
}

/**
 * Check if a single character (one UTF-8 character, NOT a surrogate-half
 * placeholder) is valid GSM 03.38 (basic or extended).
 */
function isGsm0338(string $char): bool
{
    return preg_match(GSM0338_RE, $char) === 1;
}

/**
 * Check if a message contains any non-GSM 03.38 characters (e.g. emojis).
 * Returns an array of the problematic characters (unique), iterating by
 * UTF-16 code unit to match the JS spec exactly: an astral character
 * (surrogate pair) contributes TWO entries to the result, one per surrogate
 * half — see jethro-sms-test.js: getNonGsmChars('😊').length === 2.
 *
 * @return string[] Unique non-GSM "characters" — surrogate halves are
 *                   represented as the UTF-16BE 2-byte sequence so that the
 *                   high and low surrogate of the same astral character are
 *                   still distinct entries (matching JS String.charAt()
 *                   producing two distinct lone-surrogate strings).
 */
function getNonGsmChars(string $text): array
{
    if ($text === '') {
        return [];
    }
    $u16 = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
    $len = strlen($u16);
    $seen = [];
    for ($i = 0; $i < $len; $i += 2) {
        $pair = substr($u16, $i, 2);
        if (isSurrogateCodeUnit($pair)) {
            // Surrogate halves are never GSM 03.38 — record using the raw
            // 2-byte pair as the map key so the two halves of one astral
            // character (high + low surrogate) are distinct entries.
            $seen[$pair] = $pair;
            continue;
        }
        $char = mb_convert_encoding($pair, 'UTF-8', 'UTF-16BE');
        if (!isGsm0338($char)) {
            $seen[$char] = $char;
        }
    }
    return array_values($seen);
}

/**
 * Human-display variant of getNonGsmChars(): returns unique non-GSM
 * characters as real, renderable UTF-8 code-point strings (so an emoji
 * appears as itself, not as two replacement-character placeholders).
 *
 * Used only for composing user-facing messages ("Remove special characters
 * (...) ..."). The UTF-16-code-unit-counting getNonGsmChars() above remains
 * the one used for blocking-policy decisions (length thresholds), since
 * that must match the JS `String.length` semantics exactly.
 *
 * @return string[] Unique non-GSM characters, in original order, each a
 *                   complete UTF-8 character (code point) suitable for
 *                   direct display.
 */
function getNonGsmDisplayChars(string $text): array
{
    if ($text === '') {
        return [];
    }
    $seen = [];
    // mb_str_split iterates by code point (NOT UTF-16 code unit), so an
    // astral character like an emoji yields exactly one renderable entry.
    foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
        if (!isGsm0338($char)) {
            $seen[$char] = $char;
        }
    }
    return array_values($seen);
}

/**
 * Calculate the effective length of a message in GSM 03.38 encoding.
 * Extended characters count as 2. Counts UTF-16 code units (see encoding
 * note at the top of this file) to match JS `text.length` semantics.
 */
function gsmLength(string $text): int
{
    $extCount = preg_match_all(GSM0338_EXTENDED_RE, $text);
    if ($extCount === false) {
        $extCount = 0;
    }
    return utf16Length($text) + $extCount;
}

/**
 * Calculate the number of GSM 03.38 segments a message will use.
 * Single segment: 160 chars. Concatenated (multi-segment): 153 chars per
 * segment (7 bytes lost to the UDH header).
 */
function gsmSegmentCount(int $effectiveLength): int
{
    if ($effectiveLength <= 160) {
        return 1;
    }
    return (int) ceil(($effectiveLength - 153) / 153) + 1;
}

/**
 * Calculate the number of UCS-2 segments a message will use.
 * Single segment: 70 chars. Concatenated (multi-segment): 67 chars per
 * segment (6 bytes lost to the UDH header).
 */
function ucs2SegmentCount(int $charLength): int
{
    if ($charLength <= 70) {
        return 1;
    }
    return (int) ceil(($charLength - 67) / 67) + 1;
}

/**
 * Estimate the message text after server-side URL shortening.
 *
 * Mirrors the auto-shorten behaviour in sendSms() (include/jethro_sms.php,
 * URL regex ~line 186): long bare URLs get wrapped in a %(shorten "...")%
 * token which the templater later expands to a 26-character shortened URL.
 * For client-display purposes (and now for the statusline/preview maths)
 * we just replace any URL longer than 26 chars with a 26-char GSM-safe
 * placeholder, all-basic-GSM-alphabet so it never flips the UCS-2 flag.
 *
 * The URL regex below MUST stay in sync with the PHP regex in
 * include/jethro_sms.php sendSms() — '{(https?://[^\s"\')\][<>]+)}'.
 */
function shortenUrlsInText(string $text): string
{
    $placeholder = 'https://jethro.au/s/xxxxxx'; // exactly 26 chars, all GSM-basic
    return preg_replace_callback(
        '{(https?://[^\s"\')\][<>]+)}',
        function (array $m) use ($placeholder): string {
            return strlen($m[0]) > 26 ? $placeholder : $m[0];
        },
        $text,
    );
}


// =============================================================================
// Cost line + statusline renderer
// =============================================================================

/**
 * Wrap text in a config-help span if the user is a sysadmin, otherwise
 * return plain text. Port of JethroSMS.addHelpText.
 */
function addHelpText(string $anchor, SmsStatuslineConfig $cfg, string $text): string
{
    if ($cfg->isSysadmin) {
        $labels = [
            'SMS_MAX_LENGTH' => 'Maximum message length can be changed in config — SMS_MAX_LENGTH setting',
            'SMS_UNICODE_PERMITTED' => 'Unicode handling can be changed in config — SMS_UNICODE_PERMITTED setting',
        ];
        $title = $labels[$anchor] ?? '';
        if ($title !== '') {
            return '<p class="config-help" title="' . escapeHtml($title) . '">' . $text . '</p>';
        }
    }
    return '<p>' . $text . '</p>';
}

/**
 * Build the cost counter line (pure, no side effects). Port of
 * JethroSMS.buildCostLine.
 *
 * Returns '' when any required parameter is zero/empty — callers handle
 * the "N chars remaining" or "N chars = X segments" cases inline.
 */
function buildCostLine(int $segs, string $segmentType, int $recipientCount, float $segmentCost): string
{
    if ($segs <= 0 || $segmentCost <= 0 || $recipientCount <= 0) {
        return '';
    }
    $cost = $segs * $recipientCount * $segmentCost;
    $plural = $segs > 1 ? 's' : '';
    // The raw character count is shown by the live client-side counter beside
    // the statusline (see printTextbox), so it is deliberately omitted here to
    // avoid showing the count twice.
    return $segs . ' ' . $segmentType . $plural . ' → ' . $recipientCount
        . ' recipients = $' . number_format($cost, 2) . '.';
}

/**
 * Render the status-line HTML (inner content of #sms-statusline-bulk /
 * #sms-statusline). Port of JethroSMS.renderStatusline — see that function's
 * jsdoc (preserved in git history) for the full design rationale; the
 * composition order and special-cases below mirror it exactly.
 *
 * $deliveries: preview rows ['key'=>string,'name'=>string,'message'=>string,'status'=>string (SmsStatus value)],
 *              one per message (Jethro\Sms\previewRows()), so the cost basis
 *              counts messages; OPTED_OUT rows are never sent and don't count
 *              — empty array = "no preview yet" fallback.
 *
 * @param array<int, array{key?: string, name?: string, message?: string, status?: string}> $deliveries
 * @return array{html: string, blocked: bool, blockReason: string}
 */
function renderStatusline(string $message, array $deliveries, SmsStatuslineConfig $cfg, ?int $explicitRecipientCount = null): array
{
    $rawtext = $message;

    // ---------------------------------------------------------------
    // EMPTY MESSAGE: nothing to count, no warnings to show.
    // ---------------------------------------------------------------
    if (trim($rawtext) === '') {
        return ['html' => '', 'blocked' => false, 'blockReason' => ''];
    }

    // ---------------------------------------------------------------
    // RAW-TEXT ANALYSIS
    // ---------------------------------------------------------------
    $nonGsmChars = getNonGsmChars($rawtext);
    $hasNonGsm = $nonGsmChars !== [];
    $rawGsmLen = gsmLength($rawtext);
    $rawUtf16Len = utf16Length($rawtext);
    $rawUcs2Segs = $hasNonGsm ? ucs2SegmentCount($rawUtf16Len) : 0;

    // ---------------------------------------------------------------
    // URL-SHORTENING ESTIMATE
    // ---------------------------------------------------------------
    if ($cfg->shortenUrls) {
        $escText = shortenUrlsInText($rawtext);
        $escLen = utf16Length($escText);
        $escNonGsmChars = getNonGsmChars($escText);
        $escHasNonGsm = $escNonGsmChars !== [];
        $escGsmLen = gsmLength($escText);
        $escUcs2Segs = $escHasNonGsm ? ucs2SegmentCount($escLen) : 0;
        $escEffectiveLength = $escHasNonGsm ? $escLen : $escGsmLen;
    } else {
        $escText = $rawtext;
        $escLen = $rawUtf16Len;
        $escHasNonGsm = $hasNonGsm;
        $escNonGsmChars = $nonGsmChars;
        $escGsmLen = $rawGsmLen;
        $escUcs2Segs = $rawUcs2Segs;
        $escEffectiveLength = $hasNonGsm ? $rawUtf16Len : $rawGsmLen;
    }

    // ---------------------------------------------------------------
    // UNICODE POLICY
    // ---------------------------------------------------------------
    $unicodeBlocked = false;
    $unicodeBlockMsg = '';
    // Check every row that will actually be sent, not just the composed text:
    // token expansion and preview edits can introduce characters the template
    // does not contain.
    $policyText = $escText;
    $policyHasNonGsm = $escHasNonGsm;
    $policyLen = $escLen;
    foreach ($deliveries as $d) {
        if (($d['status'] ?? '') === SmsStatus::OPTED_OUT->value) continue;
        $m = (string) ($d['message'] ?? '');
        if (getNonGsmChars($m) !== []) {
            $policyText = $m;
            $policyHasNonGsm = true;
            $policyLen = utf16Length($m);
            break;
        }
    }
    if ($policyHasNonGsm && $cfg->unicodeMode === 'disabled') {
        $unicodeBlocked = true;
        $unicodeBlockMsg = addHelpText('SMS_UNICODE_PERMITTED', $cfg,
            'Unicode characters are not allowed: ' . escapeHtml(implode('', getNonGsmDisplayChars($policyText))));
    } elseif ($policyHasNonGsm && $cfg->unicodeMode === 'when_free' && $policyLen > $cfg->ucs2SegmentLength) {
        $unicodeBlocked = true;
        $dispB = _truncatedCharList(getNonGsmDisplayChars($policyText));
        $unicodeBlockMsg = addHelpText('SMS_UNICODE_PERMITTED', $cfg,
            '⚠ Remove special characters (' . $dispB . ') to reduce SMS cost.');
    }

    if ($unicodeBlocked) {
        return ['html' => $unicodeBlockMsg, 'blocked' => true, 'blockReason' => 'Message contains characters not permitted by the unicode policy.'];
    }

    // ---------------------------------------------------------------
    // SEGMENT / COST BASIS — prefer preview, fall back to raw-text estimate.
    // ---------------------------------------------------------------
    if ($deliveries !== []) {
        $sendable = array_filter($deliveries, static fn(array $d): bool => ($d['status'] ?? '') !== SmsStatus::OPTED_OUT->value);
        // Each row is its own message: %tokens% expand per person, and rows can
        // be edited individually, so the encoding and segment count differ.
        // Cost is the sum; the encoding label is UCS-2 if any row is.
        $totalSegs = 0;
        $maxRowSegs = 0;
        $maxRowChars = 0;
        $minRowSegs = PHP_INT_MAX;
        $segIsUcs2 = false;
        foreach ($sendable as $d) {
            $m = (string) ($d['message'] ?? '');
            $mNonGsm = getNonGsmChars($m) !== [];
            $mLen = utf16Length($m);
            $mGsmLen = gsmLength($m);
            $mSegs = $mNonGsm ? ucs2SegmentCount($mLen) : gsmSegmentCount($mGsmLen);
            $mChars = $mNonGsm ? $mLen : $mGsmLen;
            $segIsUcs2 = $segIsUcs2 || $mNonGsm;
            $totalSegs += $mSegs;
            $minRowSegs = min($minRowSegs, $mSegs);
            if ($mSegs > $maxRowSegs) {
                $maxRowSegs = $mSegs;
                $maxRowChars = $mChars;
            }
        }
        $segs = $maxRowSegs;
        $uniform = $minRowSegs === $maxRowSegs;
        $segChars = $maxRowChars;
        $recipientCount = count($sendable);
        $totalSegments = $totalSegs;
    } else {
        $uniform = true;
        $totalSegments = null;
        $segIsUcs2 = $escHasNonGsm;
        $segs = $escHasNonGsm ? $escUcs2Segs : gsmSegmentCount($escGsmLen);
        $segChars = $escEffectiveLength;
        $recipientCount = $explicitRecipientCount ?? 1;
    }
    $segLabel = $segIsUcs2 ? 'UCS-2 segment' : 'segment';

    // ---------------------------------------------------------------
    // BALANCE CHECK — only runs when we have preview data.
    // Over balance blocks Send, unless the balance is advisory
    // ($cfg->balanceBlocksSend false, SmsCapability::OVERDRAFT): then it
    // only warns, alongside the normal cost line.
    // ---------------------------------------------------------------
    $balanceBlocked = false;
    $balanceMsg = '';
    $balanceWarning = '';
    if ($deliveries !== [] && $cfg->balance !== null && $segs > 0) {
        $totalSegments = $totalSegments ?? $segs * $recipientCount;
        $plural = $totalSegments > 1 ? 's' : '';
        if ($totalSegments > $cfg->balance) {
            if ($cfg->balanceBlocksSend) {
                $balanceBlocked = true;
                $balanceMsg = '⚠ Insufficient balance: ' . $totalSegments . ' segment'
                    . $plural . ' needed, ' . $cfg->balance . ' available.';
            } else {
                $balanceWarning = '⚠ Not enough SMS credit: ' . $totalSegments . ' segment'
                    . $plural . ' needed, ' . $cfg->balance . ' remaining.';
            }
        }
    }

    // ---------------------------------------------------------------
    // COMPOSE
    // ---------------------------------------------------------------
    $parts = [];

    if ($escEffectiveLength >= $cfg->maxLength) {
        $parts[] = addHelpText('SMS_MAX_LENGTH', $cfg, 'Max length (' . $cfg->maxLength . ') reached.');
    }

    if ($balanceBlocked) {
        $parts[] = '<p>' . escapeHtml($balanceMsg) . '</p>';
    } elseif ($segs > 0 && $recipientCount > 0) {
        if (!$uniform && $totalSegments !== null) {
            // Rows differ (e.g. %firstname% expanded to a unicode name in one
            // row), so a single "N segments" figure would be wrong. Show the
            // charged total instead.
            $cost = $totalSegments * $cfg->segmentCost;
            if ($cfg->segmentCost > 0) {
                $parts[] = '<p>' . escapeHtml($totalSegments . ' segments for ' . $recipientCount
                    . ' recipients = $' . number_format($cost, 2) . '.') . '</p>';
            }
        } else {
            $costLine = buildCostLine($segs, $segLabel, $recipientCount, $cfg->segmentCost);
            if ($costLine !== '') {
                $parts[] = '<p>' . escapeHtml($costLine) . '</p>';
            } elseif ($segs <= 1) {
                $segLen = $segIsUcs2 ? $cfg->ucs2SegmentLength : $cfg->segmentLength;
                $parts[] = '<p>' . escapeHtml(($segLen - $segChars) . ' chars remaining in this ' . $segLabel) . '</p>';
            } else {
                $parts[] = '<p>' . escapeHtml($segChars . ' chars = ' . $segs . ' ' . $segLabel . 's') . '</p>';
            }
        }
    }

    if ($balanceWarning !== '') {
        $parts[] = '<p>' . escapeHtml($balanceWarning) . '</p>';
    }

    // UNICODE COST-DOUBLING WARNING
    if ($hasNonGsm) {
        $gsmOnly = '';
        foreach (utf16Units($rawtext) as $unit) {
            if (isGsm0338($unit)) {
                $gsmOnly .= $unit;
            }
        }
        $gsmOnlySegs = gsmSegmentCount(gsmLength($gsmOnly));
        if ($gsmOnly !== '' && $gsmOnlySegs < $rawUcs2Segs) {
            $dispU = _truncatedCharList(getNonGsmDisplayChars($rawtext));
            $parts[] = addHelpText('SMS_UNICODE_PERMITTED', $cfg,
                '⚠ Use of special characters (' . $dispU . ') — doubles the cost.');
        }
    }

    // UNRECOGNISED TOKEN WARNING
    // Prose like "Up 10%(approx)" is sent literally, but a typo'd real token
    // (e.g. "%(firstname%") would also go out unexpanded — say so before Send.
    $snippet = (new Templater())->unparseableSnippet($message);
    if ($snippet !== null) {
        $parts[] = '⚠ Unrecognised token: ' . escapeHtml($snippet);
    }

    // TEST-MODE NOTICE
    if ($cfg->testMode) {
        $parts[] = addHelpText('SMS_TESTMODE', $cfg, 'Test mode enabled — no SMSes will be sent.');
    }

    return [
        'html' => implode(' ', $parts),
        'blocked' => $balanceBlocked,
        'blockReason' => $balanceBlocked ? $balanceMsg : '',
    ];
}

/**
 * Why $text is not permitted by the unicode policy, as a plain-text message,
 * or null when it is permitted.
 *
 * The same rule the statusline applies per preview row; Call_SMS uses it to
 * enforce the policy on the text actually sent, including token-expanded and
 * overridden rows, so a request that bypasses the composer cannot send text the
 * administrator has forbidden.
 *
 * @see docs/reference/character-counting.mdx
 */
function unicodePolicyViolation(string $text, SmsStatuslineConfig $cfg): ?string
{
    $nonGsm = getNonGsmChars($text);
    if ($nonGsm === []) {
        return null;
    }
    if ($cfg->unicodeMode === 'disabled') {
        return 'Unicode characters are not allowed: ' . implode('', getNonGsmDisplayChars($text));
    }
    if ($cfg->unicodeMode === 'when_free' && utf16Length($text) > $cfg->ucs2SegmentLength) {
        return 'Remove special characters (' . _truncatedCharList(getNonGsmDisplayChars($text))
            . ') to reduce SMS cost.';
    }
    return null;
}

/**
 * @internal Truncate a non-GSM display-char list to 3 + ellipsis, matching
 * JS `nonGsmChars.slice(0, 3).join('') + (nonGsmChars.length > 3 ? '…' : '')`.
 * Takes an array already produced by getNonGsmDisplayChars().
 */
function _truncatedCharList(array $displayChars): string
{
    $shown = array_slice($displayChars, 0, 3);
    return implode('', $shown) . (count($displayChars) > 3 ? '…' : '');
}

// =============================================================================
// Preview panel renderer
// =============================================================================

/**
 * Render the preview-panel table (inner content of #sms-preview-panel).
 * Port of JethroSMS.renderPreviewPanel.
 *
 * One row per message.  Each row's `key` is its identity — in Jethro the
 * person IDs the message goes to, joined '_' (several when people share a
 * mobile; see Jethro\Sms\previewRows()).  The key names the row's Datastar
 * signals and its override field message_overrides[KEY], so only
 * [A-Za-z0-9_] are kept.  Such a row's `name` lists its people one per
 * line ("\n"-separated); each renders on its own line.
 *
 * $overrides: [rowKey => editedMessage]. Edited rows highlighted (#fff3cd).
 * Returns '' when $deliveries is empty.  An OPTED_OUT row (status) is shown
 * greyed with "Opted out", and has no edit control: nothing will be sent.
 *
 * @param array<int, array{key?: string, name?: string, message?: string, status?: string}> $deliveries
 * @param array<int|string, string> $overrides
 */
function renderPreviewPanel(array $deliveries, array $overrides): string
{
    if ($deliveries === []) {
        return '';
    }

    $h = '<div style="max-height:250px;overflow-y:auto;margin-top:6px">';
    $h .= '<table class="table table-condensed" style="margin-bottom:0">';
    $h .= '<thead><tr><th>Recipient</th><th>Message</th><th style="width:1px"></th></tr></thead>';
    $h .= '<tbody>';
    foreach ($deliveries as $d) {
        $key = (string) preg_replace('/\W/', '', (string) ($d['key'] ?? ''));
        $edited = array_key_exists($key, $overrides);
        if ($edited) {
            $m = $overrides[$key];
        } else {
            $m = ($d['message'] ?? '') !== '' ? $d['message'] : '(empty)';
        }
        $name = ($d['name'] ?? '') !== '' ? $d['name'] : ('#' . $key);
        // People sharing a mobile share a row: one name per line.
        $nameHtml = implode('<br>', array_map(
            static fn(string $n): string => '<span style="white-space:nowrap">' . escapeHtml($n) . '</span>',
            explode("\n", $name),
        ));
        if (($d['status'] ?? '') === SmsStatus::OPTED_OUT->value) {
            $h .= '<tr class="sms-preview-optedout" style="color:#999">'
                . '<td style="vertical-align:top">' . $nameHtml . '</td>'
                . '<td><em>Opted out of SMS — will not be sent</em></td><td></td></tr>';
            continue;
        }
        $style = $edited ? ' style="background:#fff3cd"' : '';
        // Datastar-driven inline editing: the pencil sets $editingRow to this
        // row's key; the message <span> hides and the edit <textarea>
        // (bound to its own override signal + posted on blur) shows. The
        // textarea posts message_overrides[KEY] only once the user edits it
        // (see the name gate below); the send path then picks it up. No
        // client-side segment/cost maths runs — the override text posts back
        // and the server recomputes. See Jethro's docs/docs/developer/sms/SMS_DATASTAR.md.
        $jsKey = "'" . $key . "'";
        $sigName = 'smsoverride_' . $key;
        $editedSig = 'smsedited_' . $key;
        $editText = $edited ? (string) $m : (string) (($d['message'] ?? ''));
        $post = "@post('?call=sms_statusline', {contentType: 'form'})";
        $h .= '<tr>';
        $h .= '<td style="vertical-align:top;color:#999">' . $nameHtml . '</td>';
        $h .= '<td style="width:100%">'
            . '<span class="sms-preview-msg" data-row="' . escapeHtml($key) . '"' . $style
            . ' data-show="$editingRow != ' . escapeHtml($jsKey) . '">' . escapeHtml($m) . '</span>'
            // The override <textarea> only becomes a submitted field (name set)
            // once the user actually types in it ($smsedited_<key> flips true on
            // input). Otherwise its pre-filled expansion would be posted by every
            // compose-box keystroke (the form posts via contentType:form) and the
            // server would mistake that echoed expansion for a manual edit,
            // wrongly highlighting the row.
            . '<textarea class="sms-preview-edit"'
            . ' data-attr:name="$' . $editedSig . ' ? \'message_overrides[' . $key . ']\' : \'\'"'
            . ' rows="1" style="width:100%;font-size:0.9em;padding:2px"'
            . ' data-bind:' . escapeHtml($sigName)
            . ' data-show="$editingRow == ' . escapeHtml($jsKey) . '"'
            . ' data-on:input="$' . $editedSig . ' = true"'
            . ' data-on:blur="' . escapeHtml($post) . '">' . escapeHtml($editText) . '</textarea>'
            . '</td>';
        $h .= '<td><button type="button" class="editbutton sms-preview-edit-toggle" title="Edit message"'
            . ' data-on:click="$editingRow = ' . escapeHtml($jsKey) . '">&#9998;</button></td>';
        $h .= '</tr>';
    }
    $h .= '</tbody></table>';
    $h .= '</div>';

    return $h;
}
