<?php

declare(strict_types=1);

namespace Sms;

/**
 * Decorator that never sends to numbers on the provider's opt-out list.
 *
 * Opted-out recipients are removed from each entry before the inner chain
 * sees them (an entry left empty is dropped).  On the way out each one gets
 * a placeholder delivery — their own number, status OPTED_OUT, no segments,
 * not the cost bearer — merged back in ORIGINAL recipient order, so callers
 * can pair results with recipients by position and report who was skipped.
 * DbLoggingSmsProvider does not record OPTED_OUT deliveries.  Preview takes
 * the same path, so the preview panel can show who is skipped.
 *
 * The list comes from the inner provider's listOptOuts() (session-cached by
 * the raw provider).  It fails open: a provider without LIST_OPT_OUTS, or a
 * failing lookup, filters nothing — the gateway enforces opt-outs itself.
 *
 * ## Chain-order contract
 *
 * INSIDE DbLoggingSmsProvider (which drops the placeholders when logging) and
 * OUTSIDE DeduplicatingSmsProvider (filter before grouping by number).
 * Wired with DbLogging in the host's getSmsProvider(), so system sends built
 * without logging (2FA codes) skip it.  Do not reorder.
 *
 * @see DeduplicatingSmsProvider for the same fan-back-in-order pattern
 * @see Jethro's docs/docs/developer/sms/smsarchitecture.md
 */
class OptOutSmsProvider extends DecoratingSmsProvider
{
    public function send(
        array     $entries,
        SmsSender $sender,
        ?int      $sendAt = null,
        bool      $preview = false,
    ): \Result
    {
        $optedOut = $this->optedOutKeys();
        if ($optedOut === []) {
            return parent::send($entries, $sender, $sendAt, $preview);
        }

        $kept = [];
        /** Per original recipient, in entry order: true = sent on, false = opted out. @var list<array{bool, SmsRecipient, string}> $slots */
        $slots = [];
        $dropped = false;
        foreach ($entries as $entry) {
            $recipients = [];
            foreach ($entry['recipients'] as $recipient) {
                $isOut = isset($optedOut[$recipient->getPhoneNumber()->sameNumberKey()]);
                $slots[] = [!$isOut, $recipient, $entry['message']];
                if ($isOut) {
                    $dropped = true;
                } else {
                    $recipients[] = $recipient;
                }
            }
            if ($recipients !== []) {
                $kept[] = ['recipients' => $recipients] + $entry;
            }
        }

        if (!$dropped) {
            return parent::send($entries, $sender, $sendAt, $preview);
        }

        $deliveries = [];
        $batchId = null;
        if ($kept !== []) {
            $result = parent::send($kept, $sender, $sendAt, $preview);
            if ($result->isFailure()) {
                return $result;
            }
            /** @var SmsDeliveryBatch $innerBatch */
            $innerBatch = $result->getValue();
            $deliveries = $innerBatch->deliveries;
            $batchId = $innerBatch->batchId;
        }

        // One inner delivery per kept recipient, in order (the chain's
        // positional invariant); slot the placeholders back in between.
        $out = [];
        $next = 0;
        foreach ($slots as [$sentOn, $recipient, $message]) {
            if ($sentOn) {
                if (isset($deliveries[$next])) {
                    $out[] = $deliveries[$next];
                }
                $next++;
                continue;
            }
            $out[] = new SmsDelivery(
                recipient: $recipient->getPhoneNumber(),
                status: SmsStatus::OPTED_OUT,
                rawResponse: 'Not sent: this number has opted out of SMS',
                message: $message,
                segmentsUsed: 0,
                costBearer: false,
            );
        }
        for (; $next < count($deliveries); $next++) {
            $out[] = $deliveries[$next];
        }

        return \Result::success(new SmsDeliveryBatch($batchId, $out));
    }

    /**
     * sameNumberKey() of every opted-out number, as a set; empty when the
     * provider can't list opt-outs or the lookup fails (fail open).
     *
     * @return array<string, true>
     */
    private function optedOutKeys(): array
    {
        if (!$this->hasCapability(SmsCapability::LIST_OPT_OUTS)) {
            return [];
        }
        $result = $this->listOptOuts();
        if ($result->isFailure()) {
            return [];
        }
        $keys = [];
        foreach ($result->getValue() as $entry) {
            $keys[$entry->number->sameNumberKey()] = true;
        }
        return $keys;
    }
}
