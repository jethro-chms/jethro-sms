<?php

declare(strict_types=1);

namespace Sms\Providers;

use Sms\PhoneNumber;
use Sms\SmsDelivery;
use Sms\SmsStatus;

/**
 * SmsDelivery that parses one entry of a MobileMessage send response.
 *
 * POST /v1/messages replies with `results[]` in request order; each item
 * (verified against the live API):
 *   {"to":"614…","status":"success","cost":1,"message_id":"<uuid>",
 *    "scheduled_for":"2026-10-17 11:00:53"|null,"encoding":"ucs2",…}
 * where status is success | error | blocked (blocked = number unsubscribed
 * upstream) and cost is whole credits (one credit = one SMS segment).
 * `status` is "success" for scheduled sends too; a non-null scheduled_for
 * (UTC) is what marks the message as held, so it maps to SCHEDULED — the
 * state the send cool-off's Cancel link depends on.
 *
 * Test-mode responses (from MobileMessageFakeHttpClient) carry "testMode":
 * true, which records the delivery as TEST_MESSAGE (a final status), so
 * status polling and Cancel don't chase fake UUIDs upstream.
 *
 * @see SmsDelivery
 * @see MobileMessageSmsProvider
 */
final readonly class MobileMessageSmsDelivery extends SmsDelivery
{
    public function __construct(PhoneNumber $recipient, array $result)
    {
        $statusStr = (string) ($result['status'] ?? '');
        $scheduledAt = MobileMessageSmsProvider::utcTimestamp($result['scheduled_for'] ?? null);
        $status = match ($statusStr) {
            'success' => $scheduledAt !== null ? SmsStatus::SCHEDULED : SmsStatus::SENT,
            'blocked' => SmsStatus::OPTED_OUT,
            'error' => SmsStatus::FAILED,
            default => SmsStatus::UNKNOWN,
        };
        if (!empty($result['testMode'])) {
            $status = SmsStatus::TEST_MESSAGE;
        }
        parent::__construct(
            recipient: $recipient,
            status: $status,
            remoteId: isset($result['message_id']) && $result['message_id'] !== '' ? (string) $result['message_id'] : null,
            sendTimestamp: $scheduledAt,
            rawResponse: json_encode($result),
            statusDetail: $statusStr === 'error' ? ((string) ($result['error'] ?? 'Message rejected')) : null,
            segmentsUsed: isset($result['cost']) && is_numeric($result['cost']) ? (int) $result['cost'] : null,
        );
    }
}
