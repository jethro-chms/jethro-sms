<?php

declare(strict_types=1);

namespace Sms\Providers;
use Sms\PhoneNumber;
use Sms\SmsDelivery;
use Sms\SmsStatus;


/**
 * Cellcast-specific SmsDelivery that parses JSON response items.
 *
 * @see SmsDelivery
 * @see CellcastSmsProvider
 */

readonly class CellcastSmsDelivery extends SmsDelivery
{
	public function __construct(PhoneNumber $recipient, array $rawItem)
	{
		$msgStatus = $rawItem['jobInfo']['data']['messageData']['status'] ?? null;
		$isScheduled = $msgStatus === 'queued' || $msgStatus === 'pending';
		// testMode: set only by CellcastFakeHttpClient — nothing was sent, and the
		// fake remote ID can never be polled or cancelled upstream.
		$status = !empty($rawItem['testMode']) ? SmsStatus::TEST_MESSAGE
			: ($isScheduled ? SmsStatus::SCHEDULED : SmsStatus::SENT);
		parent::__construct(
			recipient: $recipient,
			status: $status,
			remoteId: isset($rawItem['MessageId']) && $rawItem['MessageId'] !== ''
				? (string) $rawItem['MessageId']
				: null,
			rawResponse: json_encode($rawItem),
		);
	}
}
