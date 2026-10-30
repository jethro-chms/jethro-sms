<?php

declare(strict_types=1);

namespace Sms;

/**
 * One sender-number record as the provider's account lists it.
 *
 * The rich counterpart to {@see PhoneNumber}: `getSenderNumbers()` answers
 * "which numbers can I send from?", while `SmsProvider::getSenderNumberRecords()`
 * exposes everything the upstream list knows — the registration status, when
 * the record was created, and the provider's own record id (needed for
 * record-keyed operations such as 5CentSMS sender-number deletion).
 *
 * `registeredAt` is the date of the most recent registration *attempt*
 * (re-registering an existing number re-creates the upstream record), which
 * is what an admin needs to judge a stale `pending` entry.  Providers that
 * don't expose a date leave it null.
 *
 * @see SmsProvider::getSenderNumberRecords()
 */
final readonly class SenderNumberRecord
{
    public function __construct(
        /** The sender value as the upstream account lists it (number or alphanumeric ID). */
        public string $value,
        /** Provider status string, e.g. 'approved' | 'pending' | 'unverified'; null when not reported. */
        public ?string $status,
        /** Unix timestamp of the (last) registration attempt; null when unknown. */
        public ?int $registeredAt,
        /** Gateway-assigned record id; null when the upstream list carries none. */
        public ?string $recordId,
    )
    {
    }

    /**
     * Whether this record's value is a phone number (as opposed to an
     * alphanumeric sender ID sharing the same upstream list).
     */
    public function isPhoneNumber(): bool
    {
        return \Sms\senderFromString($this->value) instanceof PhoneNumber;
    }

    /**
     * Whether the record is approved for sending.
     *
     * Providers report approval differently ('approved', 'acma_approved',
     * active, …); each implementation normalises to this boolean.
     */
    public function isApproved(): bool
    {
        return $this->status !== null
            && \in_array(strtolower($this->status), ['approved', 'acma_approved', 'active'], true);
    }
}
