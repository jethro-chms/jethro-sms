<?php

declare(strict_types=1);

namespace Sms;

/**
 * Snapshot of the config constants that drive the SMS statusline/preview maths.
 *
 * Read once per request and injectable for tests — avoids tests needing
 * to define/undefine global constants.
 *
 * @see renderStatusline()
 * @see renderPreviewPanel()
 */

final class SmsStatuslineConfig
{
    public function __construct(
        public int $maxLength = 160,
        public int $segmentLength = 160,
        public int $ucs2SegmentLength = 70,
        public float $segmentCost = 0.0,
        public ?int $balance = null,
        /** Whether exceeding $balance blocks Send. False when the provider's balance is advisory (SmsCapability::OVERDRAFT): over-balance then only warns. */
        public bool $balanceBlocksSend = true,
        public bool $shortenUrls = false,
        public bool $testMode = false,
        public string $unicodeMode = 'enabled', // 'enabled' | 'when_free' | 'disabled'
        public bool $isSysadmin = false,
    ) {
    }

}
