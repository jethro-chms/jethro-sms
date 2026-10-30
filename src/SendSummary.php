<?php

declare(strict_types=1);

namespace Sms;

/**
 * Send result types — a tagged union for SMS send outcomes.
 *
 * Use match() with instanceof to branch on the result type.
 *
 * @see SmsProvider::send()
 */

/**
 * Tagged union summarising the outcome of an SMS send for display and logging.
 *
 * Produced by sendSummary() (functions.php) — the batch holds the full per-recipient
 * detail (SmsDelivery[]), and the summary aggregates it into success/failure groups
 * for callers that only need the high-level outcome.
 *
 * Callers needing per-recipient status codes, credits, or remote IDs should access
 * $batch->deliveries directly rather than calling sendSummary().
 *
 * Use match(true) with instanceof to handle each case:
 *
 *   $summary = sendSummary($batch->deliveries, $recipients);
 *   match (true) {
 *       $summary instanceof AllSent        => ...,
 *       $summary instanceof PartialSuccess => ...,
 *       $summary instanceof Failed         => ...,
 *   };
 * @see AllSent
 * @see PartialSuccess
 * @see Failed
 */

interface SendSummary
{
}

/**
 * All recipients received the message successfully.
 *
 * $recipients contains SmsRecipient objects (not raw phone numbers),
 * preserving the original objects passed to send().
 * @see SendSummary
 * @see SmsRecipient
 */

final readonly class AllSent implements SendSummary
{
    /**
     * @param SmsRecipient[] $recipients
     */
    public function __construct(
        public array   $recipients,
        public ?string $remoteId = null,
    )
    {
    }
}

/**
 * Some recipients succeeded, some failed.
 * @see SendSummary
 * @see SmsRecipient
 */

final readonly class PartialSuccess implements SendSummary
{
    /**
     * @param SmsRecipient[] $successes
     * @param SmsRecipient[] $failures
     */
    public function __construct(
        public array   $successes,
        public array   $failures,
        public ?string $remoteId = null,
    )
    {
    }
}

/**
 * The entire send failed (e.g. gateway unreachable, bad credentials,
 * or all recipients failed with no successes).
 * @see SendSummary
 */

final readonly class Failed implements SendSummary
{
    public function __construct(
        public string $error,
    )
    {
    }
}
