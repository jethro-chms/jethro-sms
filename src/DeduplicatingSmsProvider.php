<?php

declare(strict_types=1);

namespace Sms;

/**
 * Decorator that sends one message per PHONE NUMBER: recipients sharing a
 * number (parent/child with the parent's mobile) get one gateway message
 * with their token values combined ("Hi Alice & Bob, …") instead of two
 * half-messages to the same handset.
 *
 * The unit of DELIVERY becomes the phone; the unit of RECORD stays the
 * person.  The decorator rewrites each entry so its `recipients` hold one
 * representative per number and attaches a parallel `logicalRecipients`
 * array (see SmsProvider::send()); after the inner provider answers, it
 * fans the per-number results back out to one delivery per person, in the
 * original recipient order — so DbLoggingSmsProvider's positional
 * insertSms() pairing and sendSummary() keep working unchanged.  Non-bearer
 * rows carry segments_used 0 and costBearer FALSE, and later status polls
 * guard their cost fields on cost_bearer (DbLoggingSmsProvider).
 *
 * ## Chain-order contract
 *
 * Must sit INSIDE DbLoggingSmsProvider (logging needs per-person results)
 * and OUTSIDE TokenExpandingSmsProvider (token combination needs to see
 * logicalRecipients).  Wired once, in the host's getSmsProvider() — do not
 * reorder.
 *
 * Preview takes the same path as a send, so preview deliveries carry the
 * combined message the shared phone will really receive; the Jethro
 * preview panel then shows one row per number (Jethro\Sms\previewRows()).
 *
 * @see SmsProvider::send() for the logicalRecipients entry key
 * @see DecoratingSmsProvider
 */
class DeduplicatingSmsProvider extends DecoratingSmsProvider
{
    public function send(
        array     $entries,
        SmsSender $sender,
        ?int      $sendAt = null,
        bool      $preview = false,
    ): \Result
    {
        $rewritten = [];
        /** One per number dialled, in the order the inner chain answers. @var list<array{members: non-empty-list<SmsRecipient>, message: string}> $groups */
        $groups = [];
        /** One per original recipient, in entry order: [index into $groups, index among its members]. @var list<array{int, int}> $slots */
        $slots = [];
        $merged = false;

        foreach ($entries as $entry) {
            // Cluster by number — sameNumberKey(), so a local and an
            // international copy of one number land together — noting where
            // each recipient went.  Groups and members keep first-occurrence
            // order; a group's first member (its primary) is the one dialled.
            $base = count($groups);
            $entryGroups = [];
            $groupOfNumber = [];
            foreach ($entry['recipients'] as $recipient) {
                $g = $groupOfNumber[$recipient->getPhoneNumber()->sameNumberKey()] ??= count($entryGroups);
                $slots[] = [$base + $g, count($entryGroups[$g] ?? [])];
                $entryGroups[$g][] = $recipient;
            }
            foreach ($entryGroups as $members) {
                $groups[] = ['members' => $members, 'message' => $entry['message']];
            }

            if (count($entryGroups) === count($entry['recipients'])) {
                // Every number in this entry unique — pass through untouched
                // (no metadata, byte-identical) so dedup is inert for
                // single-recipient and all-unique sends.
                $rewritten[] = $entry;
                continue;
            }
            $merged = true;
            $newEntry = [
                'message' => $entry['message'],
                'recipients' => array_map(static fn(array $members) => $members[0], $entryGroups),
                'logicalRecipients' => $entryGroups,
            ];
            if (isset($entry['template'])) {
                $newEntry['template'] = $entry['template'];
            }
            $rewritten[] = $newEntry;
        }

        if (!$merged) {
            return parent::send($entries, $sender, $sendAt, $preview);
        }

        $result = parent::send($rewritten, $sender, $sendAt, $preview);
        if ($result->isFailure()) {
            return $result;
        }

        /** @var SmsDeliveryBatch $innerBatch */
        $innerBatch = $result->getValue();
        $deliveries = $innerBatch->deliveries;

        // Invariant the inner chain must uphold: exactly one delivery per
        // number dialled, in request order (TokenExpanding may split entries
        // per group but preserves order; raw parsers return one delivery per
        // recipient in request order; finishStoppedSend() pads failures per
        // recipient).  If a gateway under-reports, the missing groups are
        // padded as FAILED below — every person still gets logged.
        //
        // Fan out in ORIGINAL recipient order, not group order: DbLogging
        // pairs deliveries with the entries' recipients by position
        // (insertSms(), sendSummary()), and people sharing a number need not
        // be adjacent — [Alice, Carol, Bob] with Alice and Bob on one phone.
        $out = [];
        foreach ($slots as [$g, $m]) {
            $member = $groups[$g]['members'][$m];
            $delivery = $deliveries[$g] ?? null;
            if ($delivery === null) {
                $out[] = new SmsDelivery(
                    recipient: $member->getPhoneNumber(),
                    status: SmsStatus::FAILED,
                    rawResponse: 'No result was returned for this message',
                    message: $groups[$g]['message'],
                    costBearer: $m === 0,
                );
            } elseif ($m === 0) {
                // The primary keeps the provider's delivery as-is.
                $out[] = $delivery;
            } else {
                // Secondary person sharing the number: own phone number on
                // the row, no segments/cost attributed (the one message is
                // charged once, on the primary's row).
                $out[] = $delivery->with(
                    recipient: $member->getPhoneNumber(),
                    segmentsUsed: 0,
                    costBearer: false,
                );
            }
        }
        // Gateway reported more deliveries than we sent — pass the extras
        // through; the phone-keyed fallback in insertSms()/sendSummary()
        // handles unmapped results as it does today.
        for ($i = count($groups); $i < count($deliveries); $i++) {
            $out[] = $deliveries[$i];
        }

        return \Result::success(new SmsDeliveryBatch($innerBatch->batchId, $out));
    }
}
