<?php

declare(strict_types=1);

namespace Sms;

/**
 * Sender and recipient identity types for the SMS pipeline.
 *
 * @see SmsProvider::send()
 */

/**
 * @see PhoneNumber
 * @see SenderID
 */

interface SmsSender
{
    public function __toString(): string;
}

/**
 * A SMS'able person with a phone number.
 * @see PhoneNumber
 */

interface SmsRecipient
{
    public function getPhoneNumber(): PhoneNumber;
}

/**
 * Immutable value object representing a phone number.
 *
 * Normalisation strips ALL non-digit characters — spaces, brackets, dashes,
 * leading +, everything.  This is deliberate: SMS gateways expect raw digits.
 *
 * Implements both SmsSender and SmsRecipient so a bare number can serve
 * as either role in tests and simple configurations.
 * @see SmsSender
 * @see SmsRecipient
 */

final readonly class PhoneNumber implements SmsSender, SmsRecipient
{
    public string $value;

    public function __construct(string $value)
    {
        $this->value = preg_replace('/\D/', '', $value);
        if ($this->value === '') {
            throw new \InvalidArgumentException('Phone number cannot be empty after normalization');
        }
    }

    /**
     * Convert a local number to international format.
     *
     * Only transforms if BOTH prefixes are non-empty AND the number starts
     * with the local prefix.  Otherwise returns $this unchanged — the caller
     * gets the same object back, not a clone.  This is safe because the
     * class is readonly and immutable.
     *
     * Example: internationalise('0', '61') on '0401234567' → '61401234567'
     */
    public function internationalise(string $localPrefix, string $internationalPrefix): self
    {
        if ($localPrefix !== '' && $internationalPrefix !== '' && str_starts_with($this->value, $localPrefix)) {
            return new self($internationalPrefix . substr($this->value, \strlen($localPrefix)));
        }

        return $this;
    }

    /**
     * Whether two numbers refer to the same phone, regardless of whether
     * either is in local ('0401…') or international ('61401…') form.
     *
     * Compares the last 9 digits (an Australian mobile without its '0'/'61'
     * prefix). Numbers shorter than 9 digits only match exactly. Different
     * countries' numbers sharing their last 9 digits would match; acceptable
     * for matching within one church's contacts. Prefix conversion is left
     * to the provider (see Jethro's docs/docs/developer/sms/smsarchitecture.md).
     *
     * Unit tests: jethro-sms/tests/values/test_phone_number.php
     */
    public function sameNumberAs(self $other): bool
    {
        return $this->sameNumberKey() === $other->sameNumberKey();
    }

    /**
     * The sameNumberAs() rule as a key: two numbers are the same phone
     * exactly when their keys are equal — the last 9 digits, or the whole
     * number when shorter. Lets callers group recipients by phone in one
     * pass (DeduplicatingSmsProvider, Jethro\Sms\previewRows()) instead of
     * comparing every pair.
     */
    public function sameNumberKey(): string
    {
        return \strlen($this->value) < 9 ? $this->value : substr($this->value, -9);
    }

    public function getPhoneNumber(): self
    {
        return $this;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

/**
 * A phone number with a human-readable label, used for sender-number
 * registration where the provider needs a display name alongside the number.
 * @see PhoneNumber
 */

final readonly class ContactPhoneNumber
{
    public function __construct(
        public PhoneNumber $phoneNumber,
        public string      $name,
    )
    {
    }
}

/**
 * @see SmsSender
 */

final readonly class SenderID implements SmsSender
{
    public function __construct(
        public string $value,
        public ?bool  $acmaApproved = null,
    )
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

/**
 * A single opt-out / unsubscribe record from the upstream provider.
 *
 * Timestamp and name are null when the provider doesn't supply them:
 * Cellcast provides name but not timestamp; 5centsms v5 provides
 * timestamp but not name.
 * @see PhoneNumber
 */

final readonly class OptOutEntry
{
    public function __construct(
        public PhoneNumber $number,
        public ?int        $optedOutAt = null,
        public ?string     $name = null,
    )
    {
    }
}
