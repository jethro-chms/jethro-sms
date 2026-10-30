<?php

declare(strict_types=1);
/**
 * Standalone functions used across the SMS engine.
 *
 * @see SendSummary
 * @see SmsStatus
 * @see SmsDelivery
 */


namespace Sms;


/**
 * Escape plain text for HTML element content or a quoted attribute value.
 *
 * Every character that is special in HTML is escaped, including '&' that
 * begins an existing entity: callers pass plain text, never HTML.
 */
function escapeHtml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function statusFromV5Code(int $code, string $statusText): SmsStatus
{
    if ($code === 1527) {
        return SmsStatus::SCHEDULED;
    }
    if ($code === 1004 && $statusText === 'Test Message') {
        return SmsStatus::TEST_MESSAGE;
    }
    return match ($code) {
        1000 => SmsStatus::QUEUED,
        1001 => SmsStatus::SENT,
        1002 => SmsStatus::DELIVERED,
        1003 => SmsStatus::FAILED,
        1004 => SmsStatus::DELIVERY_IN_PROGRESS,
        1005 => SmsStatus::SCHEDULED,
        1007 => SmsStatus::CANCELLED,
        1011 => SmsStatus::SENDING,
        default => SmsStatus::FAILED,
    };
}

/**
 * Parse a configured/typed sender string (SMS_SENDER, 2FA_SENDER_ID, an ini
 * SMS_FROM, a CLI --from) into a PhoneNumber or an alphanumeric SenderID.
 *
 * Phone-number-like = after trimming and removing spaces and one leading '+',
 * all digits and at least 7 long ('0412 345 678', '+61412345678').
 * Anything else — 'StBarnabas', short codes like '12345' — is a SenderID,
 * kept verbatim (trimmed).  Empty/whitespace-only returns null so callers can
 * fall back to another sender source.
 *
 * Also used by FiveCentSmsV5Provider to split its sender list into numbers
 * and Sender IDs, so configured and provider-listed senders agree.
 *
 * Unit tests: jethro-sms/tests/values/test_sender_from_string.php
 * @see Jethro's docs/docs/developer/sms/smsarchitecture.md
 */
function senderFromString(string $raw): ?SmsSender
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $digits = str_replace(' ', '', $raw);
    if (str_starts_with($digits, '+')) {
        $digits = substr($digits, 1);
    }
    if (ctype_digit($digits) && \strlen($digits) >= 7) {
        return new PhoneNumber($digits);
    }
    return new SenderID($raw);
}

// ---------------------------------------------------------------------------
// finishStoppedSend — a multi-request send that stopped part-way
// ---------------------------------------------------------------------------

/**
 * Result of a provider send() whose entry $failedIndex failed, after the
 * gateway accepted entries before it (providers send one request per entry).
 *
 * Returning a plain failure would throw away the deliveries already accepted
 * — sent and charged, but then logged nowhere, so a retry sends them twice.
 * Instead, when anything was accepted, this returns success with:
 *  - the deliveries collected so far ($sent), unchanged;
 *  - for the failed entry's recipients: UNKNOWN if the request may have
 *    reached the gateway ($mayHaveBeenSent: a transport failure such as a
 *    timeout), else FAILED (the gateway rejected it);
 *  - for every later entry, never sent: FAILED.
 * The error is in each such delivery's rawResponse and statusDetail, so
 * sendSummary() reports a partial success and the send is logged in full.
 * When nothing was accepted yet ($sent === []), the failure is returned as is.
 *
 * Unit tests: jethro-sms/tests/providers/test_stopped_send.php
 *
 * @param array<int, array{message: string, recipients: SmsRecipient[]}> $entries All entries of the send
 * @param list<SmsDelivery> $sent Deliveries of the entries before $failedIndex
 * @return \Result<SmsDeliveryBatch, string>
 */
function finishStoppedSend(array $entries, int $failedIndex, array $sent, string $error, bool $mayHaveBeenSent): \Result
{
    if ($sent === []) {
        return \Result::failure($error);
    }
    $deliveries = $sent;
    foreach (array_values($entries) as $i => $entry) {
        if ($i === $failedIndex) {
            array_push($deliveries, ...failedEntryDeliveries($entry, $mayHaveBeenSent ? SmsStatus::UNKNOWN : SmsStatus::FAILED, $error));
        } elseif ($i > $failedIndex) {
            array_push($deliveries, ...failedEntryDeliveries($entry, SmsStatus::FAILED, 'Not sent: an earlier request failed (' . $error . ')'));
        }
    }
    return \Result::success(new SmsDeliveryBatch(null, $deliveries));
}

/**
 * One delivery per recipient of a send entry that wasn't (or may not have
 * been) accepted, carrying $detail as its rawResponse and statusDetail — so
 * every recipient appears in the result, in request order, and is logged.
 *
 * @param array{message: string, recipients: SmsRecipient[]} $entry
 * @return list<SmsDelivery>
 */
function failedEntryDeliveries(array $entry, SmsStatus $status, string $detail): array
{
    return array_values(array_map(
        static fn(SmsRecipient $recipient) => new SmsDelivery(
            recipient: $recipient->getPhoneNumber(),
            status: $status,
            rawResponse: $detail,
            message: $entry['message'],
            statusDetail: $detail,
        ),
        $entry['recipients'],
    ));
}


// ---------------------------------------------------------------------------
// sendSummary — aggregate per-recipient results for display/logging
// ---------------------------------------------------------------------------

/**
 * Aggregate per-recipient SmsDelivery results into a SendSummary.
 *
 * This bridges provider::send() (which returns SmsDelivery[]) and callers
 * that only need the high-level outcome (sendSms(), logToDatabase(),
 * formatSendSummary(), AJAX/CLI dispatch).
 *
 * Callers needing per-recipient detail should use the raw SmsDelivery[]
 * directly rather than calling this function.
 *
 * Example with FiveCentSMS v5 results:
 *
 *   $results = [
 *     new FiveCentSmsDelivery(new PhoneNumber("0491570157"), '{"destination":"0491570157","status":1002,"status_text":"Delivered","id":"abc123","credits":1}'),
 *     new FiveCentSmsDelivery(new PhoneNumber("0491570158"), '{"destination":"0491570158","status":1003,"status_text":"Failed"}'),
 *   ];
 *   $summary = sendSummary($results, $recipients);
 *   // → PartialSuccess(recipients: [0491570157], failures: [0491570158], remoteId: "abc123")
 *
 * Decision tree:
 *   - Empty results → Failed("No results returned from provider")
 *   - Positional fast path (shared-mobile support): when count($results) ===
 *     count($recipients) AND every $results[$i]->recipient()->value ===
 *     $recipients[$i]->getPhoneNumber()->value, pair result i with recipient i.
 *     This correctly attributes deliveries when multiple recipients share a
 *     phone number (e.g. spouses, parent/child) — the phone-keyed map below
 *     would collapse them to the last entry.
 *   - Phone-keyed fallback (count mismatch or phone sanity check failed):
 *       - Build recipient map, iterate results:
 *           - Recipient NOT in original list  → skip (gateway returned extras)
 *           - status()->isOk()             → successes[]
 *           - !status()->isOk()            → failures[]
 *   - failures empty, successes non-empty → AllSent
 *   - failures empty, successes empty     → Failed("No recipients matched in gateway response")
 *   - successes empty, failures non-empty → Failed with first result's raw response
 *   - otherwise                           → PartialSuccess
 *
 * @param SmsDelivery[] $results Per-recipient results from provider
 * @param SmsRecipient[] $recipients Original recipients passed to send()
 */
function sendSummary(array $results, array $recipients): SendSummary
{
    if ($results === []) {
        return new Failed(error: 'No results returned from provider');
    }

    // Re-index both arrays so positional checks are reliable regardless of
    // how the caller constructed them.
    $results    = array_values($results);
    $recipients = array_values($recipients);

    // Positional fast path: every provider parser returns deliveries in
    // request order (one per recipient).  When counts match AND every
    // result's phone equals the corresponding recipient's phone, pair by
    // position.  This is the only correct strategy when recipients share a
    // phone number — a phone-keyed map would collapse them.
    $usePositional = false;
    if (count($results) === count($recipients)) {
        $usePositional = true;
        foreach ($results as $i => $r) {
            if ($r->recipient()->value !== $recipients[$i]->getPhoneNumber()->value) {
                $usePositional = false;
                break;
            }
        }
    }

    // Build (result, recipient) pairs using whichever strategy was chosen.
    // The classification loop below works identically for both strategies.
    if ($usePositional) {
        $pairs = array_map(null, $results, $recipients);
    } else {
        // Phone-keyed map fallback.  The last recipient with a given number
        // wins — consistent with the pre-fix behaviour.
        $recipMap = [];
        foreach ($recipients as $r) {
            $recipMap[$r->getPhoneNumber()->value] = $r;
        }
        $pairs = [];
        foreach ($results as $r) {
            $recip = $recipMap[$r->recipient()->value] ?? null;
            if ($recip === null) {
                // Gateway returned a number not in our list — skip.
                continue;
            }
            $pairs[] = [$r, $recip];
        }
    }

    $successes = [];
    $failures  = [];
    $remoteIds = [];

    foreach ($pairs as [$smsResult, $recip]) {
        if ($smsResult->status()->isOk()) {
            $successes[] = $recip;
        } else {
            $failures[] = $recip;
        }
        $remoteId = $smsResult->remoteId();
        if ($remoteId !== null && $remoteId !== '') {
            $remoteIds[] = $remoteId;
        }
    }

    if ($failures === []) {
        if ($successes === []) {
            return new Failed(error: 'No recipients matched in gateway response');
        }
        return new AllSent(
            recipients: $successes,
            remoteId: $remoteIds !== [] ? implode(', ', $remoteIds) : null,
        );
    }

    if ($successes === []) {
        $firstRaw = $results[0]->rawResponse();
        $errorMsg = $firstRaw !== '' ? $firstRaw : $results[0]->status()->name;
        return new Failed(error: $errorMsg);
    }

    return new PartialSuccess(
        successes: $successes,
        failures: $failures,
        remoteId: $remoteIds !== [] ? implode(', ', $remoteIds) : null,
    );
}


/**
 * Whether the message contains any of the known personalisation %tokens%.
 *
 * A bare '%' (e.g. "20% off") is NOT a token — only %name% forms whose
 * name appears in $tokenNames count, or %(...)% s-expression forms.
 * Token detection must not trigger on a bare '%' or an unknown %word%
 *
 * @param string[] $tokenNames Token names without delimiters, e.g. ['firstname','lastname','fullname']
 */
function messageHasTokens(string $message, array $tokenNames): bool
{
    if (!str_contains($message, '%')) {
        return false;
    }
    // %(...)% always implies token expansion
    if (str_contains($message, '%(')) {
        return true;
    }
    if ($tokenNames === []) {
        return false;
    }
    $alt = implode('|', array_map('preg_quote', $tokenNames));
    return (bool) preg_match('/%(' . $alt . ')%/', $message);
}


/**
 * Parse a comma-separated Sender ID string into SenderID objects.
 *
 * @return SenderID[]
 */
function parseSenderIdsFromCsv(string $raw): array
{
    $ids = [];
    $skip = ['_USER_MOBILE_', '_SENDER_IDS_'];
    foreach (explode(',', $raw) as $id) {
        $id = trim($id);
        if ($id !== '' && !\in_array($id, $skip, true)) {
            $ids[] = new SenderID($id);
        }
    }

    return $ids;
}

/**
 * The effective SMS_SENDER_OPTIONS token list, in order: trimmed, empty
 * segments (duplicate commas) dropped, and the default
 * `_USER_MOBILE_,_SENDER_IDS_` applied when $raw is blank.
 *
 * Callers that treat a blank setting as "no allowlist" (e.g.
 * OverridingSmsProvider::senderAllowed()) must check for blank first.
 *
 * Unit tests: jethro-sms/tests/overriding/test_sender_option_tokens.php
 *
 * @return list<string>
 */
function senderOptionTokens(string $raw): array
{
    if (trim($raw) === '') {
        return ['_USER_MOBILE_', '_SENDER_IDS_'];
    }
    return array_values(array_filter(
        array_map('trim', explode(',', $raw)),
        static fn(string $t) => $t !== '',
    ));
}

/**
 * Drop sender IDs the provider reports as not ACMA-approved (e.g. 5centsms
 * `pending`, `rejected`).  IDs with unknown status (`acmaApproved === null` —
 * providers that don't report approval, or SMS_SENDER_OPTIONS literals) are
 * kept, since they may be perfectly usable.
 *
 * @see docs/reference/_5centsmsv5/senderid/GET.md
 * @param SenderID[] $ids
 * @return list<SenderID>
 */
function excludeUnapprovedSenderIds(array $ids): array
{
    return array_values(array_filter($ids, static fn(SenderID $s) => $s->acmaApproved !== false));
}



/**
 * Format a single SmsDelivery as a one-line text representation.
 *
 * Format: <phone>  <StatusText> (#<code>)  [id=<remoteId>]  [sent=<ts>]  [delivered=<ts>]  ["<message>"]
 */
function formatDeliveryLine(SmsDelivery $d): string
{
	$line = $d->recipient()->value
		. '  ' . $d->statusText() . ' (#' . $d->status()->value . ')';
	if ($d->remoteId() !== null) {
		$line .= '  id=' . $d->remoteId();
	}
	if ($d->sendTimestamp() !== null) {
		$line .= '  sent=' . date('Y-m-d H:i:s', $d->sendTimestamp());
	}
	if ($d->deliveryTimestamp() !== null) {
		$line .= '  delivered=' . date('Y-m-d H:i:s', $d->deliveryTimestamp());
	}
	if ($d->message() !== null && $d->message() !== '') {
		$line .= '  "' . $d->message() . '"';
	}
	return $line;
}

/**
 * Format an array of SmsDelivery objects as sorted text lines.
 * @param SmsDelivery[] $deliveries
 */
function formatDeliveryLines(array $deliveries): string
{
	$lines = array_map('\Sms\formatDeliveryLine', $deliveries);
	sort($lines, SORT_STRING);
	return implode("\n", $lines) . (count($lines) > 0 ? "\n" : '');
}

