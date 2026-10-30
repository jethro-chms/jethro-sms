<?php

declare(strict_types=1);

namespace Sms\Providers;

use Sms\PhoneNumber;
use Sms\SmsDelivery;
use Sms\SmsStatus;
use function Sms\statusFromV5Code;

/**
 * SmsDelivery that parses a FiveCent SMS v5 per-recipient JSON object.
 *
 * The raw response is a JSON object like:
 *   {"destination":"0491570854","status":1002,"status_text":"Sent","id":"abc123","credits":1}
 * @see SmsDelivery
 * @see FiveCentSmsV5Provider
 */

final readonly class FiveCentSmsDelivery extends SmsDelivery
{
    public function __construct(PhoneNumber $recipient, string $rawJson)
    {
        $p = json_decode($rawJson, true) ?? [];
        $s = (int)($p['status'] ?? 0);
        $st = (string)($p['status_text'] ?? '');
        parent::__construct(
            recipient: $recipient,
            status: statusFromV5Code($s, $st),
            remoteId: isset($p['id']) && $p['id'] !== '' ? (string)$p['id'] : null,
            rawResponse: $rawJson,
            segmentsUsed: FiveCentSmsV5Provider::segmentsFrom($p),
        );
    }
}

