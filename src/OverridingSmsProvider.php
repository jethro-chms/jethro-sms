<?php

declare(strict_types=1);

namespace Sms;

/**
 * Decorator that applies conf.php constant overrides before delegating.
 *
 *   - send(): for user-initiated sends, validates the sender matches
 *     SMS_SENDER if set, otherwise checks the SMS_SENDER_OPTIONS allowlist;
 *     applies SMS_SEND_COOLOFF delay (default 0: off) to immediate sends.
 *     System-initiated
 *     sends (2FA, reminders, CLI) take their sender from config and skip
 *     all three.  The `_USER_MOBILE_` token (in either constant) matches
 *     only the $userMobile injected at construction.
 *   - getSenderIds(): if SMS_SENDER_OPTIONS is set, parses and returns the CSV list.
 * @see DecoratingSmsProvider
 * @see PhoneNumber
 * @see SenderID
 */

class OverridingSmsProvider extends DecoratingSmsProvider
{
	/**
	 * @param SmsProvider      $inner
	 * @param bool             $userInitiated Enforce SMS_SENDER / SMS_SENDER_OPTIONS
	 *                                        and apply the SMS_SEND_COOLOFF undo window.
	 * @param PhoneNumber|null $userMobile    The acting user's mobile, which the
	 *                                        `_USER_MOBILE_` token resolves to; null
	 *                                        when there is no acting user.
	 */
	public function __construct(
		SmsProvider $inner,
		private bool $userInitiated = true,
		private ?PhoneNumber $userMobile = null,
	) {
		parent::__construct($inner);
	}

	public function send(
		array     $entries,
		SmsSender $sender,
		?int      $sendAt = null,
		bool      $preview = false,
	): \Result
	{
		$settings = new ConstantSettings();
		$hs = (string) $settings['SMS_SENDER']->val('');
		if ($hs === '') { $hs = (string) $settings['SENDER_ID']->val(''); }

		// SMS_SENDER and SMS_SENDER_OPTIONS constrain what a *user* may send
		// as.  System-initiated sends (2FA, reminders, CLI) choose their sender
		// from config (e.g. 2FA_SENDER_ID, an ini SMS_FROM) and have no user
		// mobile to resolve _USER_MOBILE_ against, so neither check applies.
		if ($this->userInitiated && $hs === '_USER_MOBILE_') {
			if ($this->userMobile === null) {
				return \Result::failure(
					'SMS_SENDER is set to "_USER_MOBILE_" but there is no current user mobile number to send from.'
				);
			}
			if (!$this->isUserMobile($sender)) {
				return \Result::failure(
					'Sender mismatch: SMS_SENDER is set to "_USER_MOBILE_" ("' . $this->userMobile
					. '") but caller passed "' . (string) $sender . '"'
				);
			}
		} elseif ($this->userInitiated && $hs !== '' && (string) senderFromString($hs) !== (string) $sender) {
			// Compare parsed forms: a configured '+61 412 345 678' reaches us
			// as the normalised PhoneNumber '61412345678'.
			return \Result::failure(
				'Sender mismatch: SMS_SENDER is set to "' . $hs
				. '" but caller passed "' . (string) $sender . '"'
			);
		}

		// Enforce the SMS_SENDER_OPTIONS allowlist server-side.
		if ($this->userInitiated && $hs === '' && !$this->senderAllowed($sender)) {
			return \Result::failure(
				'Sender "' . (string) $sender . '" is not permitted by SMS_SENDER_OPTIONS.'
			);
		}

		// Apply SMS_SEND_COOLOFF delay to immediate (non-deferred) sends
		// only when user-initiated and the provider supports both deferred
		// send and cancellation — the cooloff is an undo window; without
		// cancel it's just added latency.  System-initiated sends (2FA,
		// reminders) skip the delay.  Default 0 (off): holding every web send
		// is surprising latency, and providers differ in the shortest schedule
		// they accept (MobileMessage: >60 s, so at least 90 s).
		if ($sendAt === null && $this->userInitiated) {
			$cooloff = (int) $settings['SMS_SEND_COOLOFF']->val(0);
			if ($cooloff > 0
				&& $this->inner->hasCapability(SmsCapability::DEFERRED_SEND)
				&& $this->inner->hasCapability(SmsCapability::DEFERRED_SEND_CANCEL)) {
				$sendAt = time() + $cooloff;
			}
		}

		return parent::send($entries, $sender, $sendAt, $preview);
	}

	/**
	 * Whether $sender is the acting user's mobile (the `_USER_MOBILE_` token's
	 * resolution).  Always false when no $userMobile was injected.
	 */
	private function isUserMobile(SmsSender $sender): bool
	{
		return $this->userMobile !== null && (string) $sender === $this->userMobile->value;
	}

	/**
	 * Whether the given sender passes the SMS_SENDER_OPTIONS allowlist.
	 *
	 * No allowlist configured → all senders allowed.  Literal match always
	 * passes.  Special tokens:
	 *   `_USER_MOBILE_` — permits only the $userMobile injected at construction.
	 *   `_SENDER_IDS_`  — permits any sender whose value appears in the inner
	 *                      provider's getSenderIds() list.
	 * Empty segments from duplicate commas are ignored.
	 *
	 * Unit tests: jethro-sms/tests/overriding/test_sender_allowlist.php
	 */
	private function senderAllowed(SmsSender $sender): bool
	{
		$raw = (string) (new ConstantSettings())['SMS_SENDER_OPTIONS']->val('');
		if ($raw === '') {
			return true;
		}
		$configured = senderOptionTokens($raw);
		// Literal match
		if (\in_array((string) $sender, $configured, true)) {
			return true;
		}
		// _USER_MOBILE_ permits only the acting user's own mobile
		if (\in_array('_USER_MOBILE_', $configured, true) && $this->isUserMobile($sender)) {
			return true;
		}
		// _SENDER_IDS_ permits any sender whose value is in the inner provider's list
		if (\in_array('_SENDER_IDS_', $configured, true)) {
			$idsResult = parent::getSenderIds();
			if ($idsResult->isSuccess()) {
				foreach ($idsResult->getValue() as $s) {
					if ($s->value === (string) $sender) {
						return true;
					}
				}
			}
		}
		return false;
	}

    /**
     * Returns the sender IDs for the dropdown, honouring SMS_SENDER_OPTIONS.
     *
     * When SMS_SENDER_OPTIONS is absent, delegates unchanged to the inner provider.
     *
     * When present, two modes apply depending on whether `_SENDER_IDS_` appears
     * in the configured list:
     *
     * **`_SENDER_IDS_` present** — the token expands to every sender ID the inner
     * provider reports (via GET_SENDER_IDS).  Any additional literal IDs in the
     * configured list are appended (deduped by value, preserving inner provider
     * metadata where available).  If the inner provider has no GET_SENDER_IDS
     * capability, `_SENDER_IDS_` expands to nothing; only literal IDs remain.
     * An inner API failure is propagated as-is.
     *
     * **`_SENDER_IDS_` absent** — existing intersection behaviour: if the inner
     * provider has GET_SENDER_IDS, only upstream IDs whose values appear in the
     * configured list are returned; if there is no capability, the configured
     * list is parsed directly as the full sender-ID set.
     *
     * `_USER_MOBILE_` and empty segments are always ignored when building the
     * return list.
     *
     * @inheritDoc
     */
    public function getSenderIds(bool $getAll = false): \Result
    {
        if (!defined('SMS_SENDER_OPTIONS') || SMS_SENDER_OPTIONS === '') {
            return parent::getSenderIds($getAll);
        }

        $configured = array_values(array_filter(
            array_map('trim', explode(',', SMS_SENDER_OPTIONS)),
            fn(string $s) => $s !== '',
        ));

        $specialTokens = ['_USER_MOBILE_', '_SENDER_IDS_'];

        // ── _SENDER_IDS_ mode ────────────────────────────────────────────────
        // The token expands to every ID the inner provider knows about, plus
        // any literal IDs explicitly listed alongside it.
        if (\in_array('_SENDER_IDS_', $configured, true)) {
            $literalIds = array_values(array_filter(
                $configured,
                fn(string $s) => !\in_array($s, $specialTokens, true),
            ));

            $ids = [];
            if ($this->inner->hasCapability(SmsCapability::GET_SENDER_IDS)) {
                $innerResult = parent::getSenderIds(getAll: true);
                if ($innerResult->isFailure()) {
                    return $innerResult;
                }
                $ids = $innerResult->getValue();
            }

            // Append literal IDs not already present from the inner provider
            $innerValues = array_map(fn(SenderID $s) => $s->value, $ids);
            foreach ($literalIds as $lit) {
                if (!\in_array($lit, $innerValues, true)) {
                    $ids[] = new SenderID($lit);
                }
            }

            return \Result::success($getAll ? $ids : \Sms\excludeUnapprovedSenderIds($ids));
        }

        // ── Existing intersection / CSV-parse logic ───────────────────────────
        // If the inner provider has no sender-ID API, SMS_SENDER_OPTIONS is
        // authoritative — parse it directly as the full list.
        if (!$this->inner->hasCapability(SmsCapability::GET_SENDER_IDS)) {
            $ids = parseSenderIdsFromCsv(SMS_SENDER_OPTIONS);
            return \Result::success($getAll ? $ids : \Sms\excludeUnapprovedSenderIds($ids));
        }

        // Inner provider supports sender-ID discovery — fetch all IDs and
        // intersect with the admin-configured SMS_SENDER_OPTIONS allowlist.
        $innerResult = parent::getSenderIds(getAll: true);
        if ($innerResult->isFailure()) {
            return $innerResult;
        }
        $innerIds = $innerResult->getValue();

        if ($innerIds === []) {
            return \Result::failure(
                'No sender IDs registered upstream. '
                . 'Register one at your provider dashboard, or remove SMS_SENDER_OPTIONS to fall back to user mobile.'
            );
        }

        $filtered = array_values(array_filter(
            $innerIds,
            fn(SenderID $s) => \in_array($s->value, $configured, true),
        ));

        if ($filtered === []) {
            $available = implode(', ', array_map(fn(SenderID $s) => $s->value, $innerIds));
            $wanted = implode(', ', $configured);
            return \Result::failure(
                'SMS_SENDER_OPTIONS (' . $wanted . ') contains a Sender ID not registered with the SMS provider.'
                . ' Available: ' . $available . '.'
            );
        }

        return \Result::success($getAll ? $filtered : \Sms\excludeUnapprovedSenderIds($filtered));
    }

    public function getSegmentCost(): int
    {
        if (defined('SMS_SEGMENT_COST') && SMS_SEGMENT_COST !== '') {
            return (int) round((float) SMS_SEGMENT_COST * 100000);
        }
        return parent::getSegmentCost();
    }
}
