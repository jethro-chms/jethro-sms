<?php

declare(strict_types=1);

namespace Sms;

enum SmsStatus: string
{
    /** Message has been queued for delivery. */
    case QUEUED = 'queued';

    /** Message has been sent to the carrier. */
    case SENT = 'sent';

    /** Delivery confirmed — message reached the recipient's handset. */
    case DELIVERED = 'delivered';

    /** Delivery failed — invalid number or unreachable recipient. */
    case FAILED = 'failed';

    /** Delivery in progress (success state, details provider-specific). */
    case DELIVERY_IN_PROGRESS = 'in-progress';

    /** Message scheduled for future delivery. */
    case SCHEDULED = 'scheduled';

    /** Message was cancelled before delivery. */
    case CANCELLED = 'cancelled';

    /** Message is in the process of being sent (in-flight). */
    case SENDING = 'sending';

    /** Test mode message (FiveCent dry-run, CellcastFakeHttpClient) — terminal, not a real send. */
    case TEST_MESSAGE = 'test-message';

    /** Not sent: the number is on the provider's opt-out list (OptOutSmsProvider). Terminal; in-memory only — DbLoggingSmsProvider never records these deliveries, so the smsdelivery.status ENUM has no such value. */
    case OPTED_OUT = 'opted-out';

    /** Status unknown — used when smsdelivery.status is empty (pre-upgrade data or bug). */
    case UNKNOWN = 'unknown';

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /** Map from MySQL enum label (or string backing value) to SmsStatus. */
    public static function fromMySql(string $label): self
    {
        return self::tryFrom($label) ?? self::UNKNOWN;
    }

    /** SmsStatus to MySQL enum label. */
    public function toMySql(): string
    {
        return $this->value;
    }

    /** Statuses and their display labels for sidebar filter checkboxes.
     * @return array<string, string>  mySql value => display label */
    public static function filterOptions(): array
    {
        $opts = [];
        foreach (self::cases() as $case) {
            $label = ucfirst(strtolower(str_replace('_', ' ', $case->name)));
            $opts[$case->value] = $label;
        }
        return $opts;
    }

    public function isOk(): bool
    {
        return $this !== self::FAILED && $this !== self::CANCELLED && $this !== self::UNKNOWN
            && $this !== self::OPTED_OUT;
    }

    /** Status labels where the gateway accepted the message for delivery.
     * Use {@see isOk()} for per-instance checks; use this constant for
     * set-membership tests against DB / raw API labels.
     * @var string[] */
    public const ACCEPTED_STATUSES = [
        self::QUEUED->value,
        self::SENT->value,
        self::DELIVERED->value,
        self::DELIVERY_IN_PROGRESS->value,
        self::SCHEDULED->value,
        self::SENDING->value,
    ];

    /** Whether this status is final (the message won't transition further).
     * Terminal states: DELIVERED, FAILED, CANCELLED, TEST_MESSAGE, OPTED_OUT. */
    public function isFinal(): bool
    {
        return !($this === self::QUEUED
            || $this === self::SENT
            || $this === self::SCHEDULED
            || $this === self::SENDING
            || $this === self::DELIVERY_IN_PROGRESS
            || $this === self::UNKNOWN);
    }

    /** MySQL values of every status that may still change (!isFinal()) — the
     * deliveries status polling keeps asking about.
     * @return list<string> */
    public static function nonFinalValues(): array
    {
        return array_values(array_map(
            static fn(self $s): string => $s->value,
            array_filter(self::cases(), static fn(self $s): bool => !$s->isFinal()),
        ));
    }
}
