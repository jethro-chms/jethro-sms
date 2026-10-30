<?php

declare(strict_types=1);

namespace Sms;

interface SmsProvider
{
    /**
     * Create a configured provider from an injected settings source.
     *
     * Reads SMS_* settings from $settings and constructs a fully-configured
     * provider instance.  Throws \RuntimeException if required settings
     * are missing or invalid.  {@see ConstantSettings} (global constants)
     * is the default source.
     *
     * @param SmsSettings $settings Configuration source.
     * @param ?string $baseUrl Absolute base URL used to resolve bare-path URL settings.
     */
    public static function fromSettings(SmsSettings $settings, ?string $baseUrl = null): static;

    /**
     * Identifier of the upstream SMS company *and API version* this provider
     * talks to (e.g. '5centsmsv5', 'cellcastv1', 'mobilemessagev1'), or null
     * when the upstream API is unknown (the generic HTTP template provider).
     *
     * Recorded in smsdelivery.provider so a row's raw_response can always be
     * interpreted, and compared against the current provider's key to decide
     * whether a delivery can be polled or matched by history sync. A provider
     * whose upstream API changes incompatibly must return a new versioned key.
     *
     * Not the SMS_PROVIDER setting value: that names a company and is mapped
     * to a class by providerShortNames() (src/factory.php).
     *
     * @see Jethro's docs/docs/developer/sms/database.mdx "Provider column"
     */
    public function getKey(): ?string;

    /**
     * List the PHP constants this provider reads, for admin UI documentation.
     *
     * @return array<array{string, string, string}>  Each element is
     *         [constant_key, 'required'|'optional', purpose].
     */
    public static function getConstants(): array;

    /**
     * Preference score for auto-detection and admin UI ordering.
     *
     * Higher scores are preferred.  -1 means deprecated — excluded from
     * auto-detection and not shown in configuration help.
     */
    public static function usagePreference(): int;

    /**
     * Return a copy of this provider with the given cache wired in.
     *
     * Providers that don't use caching return $this unchanged.
     * Called by getSmsProvider() after fromSettings() — keeps
     * configuration-reading and infrastructure-wiring as separate steps.
     */
    public function withCache(SmsCache $cache): static;

    /**
     * Send one or more SMS messages and return the results.
     *
     * Every send is inherently a batch: the caller passes all (message,
     * recipients) pairs at once, and each decorator processes them in a
     * single invocation.  A single-message send is just a batch of one entry.
     *
     * @param array<int, array{message: string, recipients: SmsRecipient[], logicalRecipients?: list<list<SmsRecipient>>}> $entries
     *     When an entry carries `logicalRecipients`, the arrays run parallel:
     *     `recipients[i]` is the one number dialled for delivery i (the
     *     group's first member) and `logicalRecipients[i]` lists EVERY
     *     recipient that delivery satisfies, primary first.  Written by
     *     DeduplicatingSmsProvider, read by TokenExpandingSmsProvider (which
     *     expands per group); raw providers ignore it and dial `recipients`.
     *     Must not be stripped by decorators: the positional pairing with the
     *     provider response is load-bearing.
     * @param SmsSender $sender
     * @param int|null $sendAt Unix timestamp for deferred delivery (only for providers with DEFERRED_SEND capability), or null for immediate
     * @param bool $preview When true, expand tokens and return per-recipient
     *                      SmsDelivery objects with $message set, but do not
     *                      actually send or persist anything.
     * @return \Result<SmsDeliveryBatch, string>
     */
    public function send(
        array     $entries,
        SmsSender $sender,
        ?int      $sendAt = null,
        bool      $preview = false,
    ): \Result;

    /**
     * Get registered sender IDs from the provider.
     *
     * @param bool $getAll When false (default), sender IDs the provider reports as
     *                     not ACMA-approved (acmaApproved === false) are omitted;
     *                     IDs with unknown status (null) are kept.
     *                     When true, all sender IDs are returned (admin/diagnostic use).
     * @return \Result<SenderID[], string>
     */
    public function getSenderIds(bool $getAll = false): \Result;

    /**
     * Get the current account balance (number of SMSes that may be sent) from the provider.
     *
     * @return \Result<int, string>
     */
    public function getBalance(): \Result;

    /**
     * Quick health check — whether the upstream provider is reachable
     * and responding.  Implementations SHOULD cache the result when a
     * cache is available; callers MUST tolerate transient failures.
     *
     * @return \Result<bool, string>
     */
    public function isOperational(): \Result;

    /**
     * Query the upstream provider to update this delivery with the latest status.
     *
     * The returned SmsDelivery may be a new instance with timestamps populated
     * (deliveryTimestamp, sendTimestamp) alongside the updated status.
     *
     * @return \Result<SmsDelivery, string>
     */
    public function updateDelivery(SmsDelivery $delivery): \Result;

    /**
     * Cancel the previously sent (scheduled/deferred) deliveries in the batch.
     *
     * Cancellation uses ONLY each delivery's remoteId; all other delivery
     * fields are echoed unchanged into the result.  Returns success whenever
     * the cancel operation could be attempted: per-delivery outcomes are read
     * from each returned delivery's status (CANCELLED on success, unchanged
     * otherwise, with the upstream/transport reason in statusDetail()).
     * Result::failure is reserved for operation-level errors
     * (unsupported provider, auth/transport failure).
     *
     * @return \Result<SmsDeliveryBatch, string>  Batch with per-delivery updated statuses
     */
    public function cancel(SmsDeliveryBatch $batch): \Result;

    /**
     * Get all registered sender phone numbers from the provider.
     *
     * Returns a list of phone numbers that have been registered
     * and approved as sender numbers.  Providers that don't support
     * this return an empty list.
     *
     * @return \Result<PhoneNumber[], string>
     */
    public function getSenderNumbers(): \Result;

    /**
     * Check whether a specific phone number is approved as a sender.
     *
     * @param PhoneNumber $number The phone number to check
     * @return \Result<bool, string>
     */
    public function verifySenderNumber(PhoneNumber $number): \Result;

    /**
     * Register a phone number as a sender with the upstream gateway.
     *
     * Opaque, provider-specific state machine.  Call with no arguments to
     * get the initial number-entry form; call with a phone number
     * (+ optional form-submission params) to advance.  Render `fields`
     * as a form; collect user input; repeat until `fields` is empty/null.
     *
     * @param ContactPhoneNumber|null $contact  null for the initial number-entry form
     * @param array<string, string>|null $params  form-submission params
     * @return \Result<RegistrationStep, string>  success; `isComplete()` when fields is empty
     */
    public function registerSenderNumber(?ContactPhoneNumber $contact = null, ?array $params = null): \Result;

    /**
     * Register a sender ID (business identity) with the upstream gateway.
     *
     * Same opaque state machine as registerSenderNumber().  Call with no
     * arguments to get the initial sender-ID entry form; call with a
     * SenderID (+ optional params) to advance.
     *
     * @param SenderID|null $senderId  null for the initial sender-ID entry form
     * @param array<string, string>|null $params  form-submission params
     * @return \Result<RegistrationStep, string>  success; `isComplete()` when fields is empty
     */
    public function registerSenderId(?SenderID $senderId = null, ?array $params = null): \Result;

    /**
     * Remove a registered sender phone number from the provider account.
     *
     * Gated on the DELETE_SENDER_NUMBER capability.  Providers that don't
     * support removal return a failure.
     *
     * Callers identify the sender by phone number; providers that key
     * deletion on a gateway-assigned record id resolve it themselves
     * (e.g. 5CentSMS lists the account, matches the number, deletes by id).
     *
     * @return \Result<bool, string>  true on success; failure with a plain-text error
     */
    public function deleteSenderNumber(PhoneNumber $number): \Result;

    /**
     * Sender-number records as the provider's account lists them — numbers
     * AND alphanumeric IDs, approved or awaiting verification, with the
     * registration date and the provider's record id where available.
     *
     * NB the name is historical: despite "Number", alphanumeric sender IDs
     * share the same upstream list and are included here. Callers that want
     * only numbers filter with {@see SenderNumberRecord::isPhoneNumber()};
     * `senderids` uses the alphanumeric entries to date each ID.
     *
     * The admin status panel uses this to surface stale pending entries
     * (e.g. a number attempted long ago that silently blocks re-registration);
     * `getSenderNumbers()` remains the simple approved-numbers filter.
     *
     * @return \Result<SenderNumberRecord[], string>  failure when the provider
     *         has no record-level list endpoint.
     */
    public function getSenderNumberRecords(): \Result;

    /**
     * Human-readable description of this provider for admin status panels.
     *
     * Examples: "5CentSMS (v5)", "Cellcast", "https://my-gateway.example.com/api"
     */
    public function getDescription(): string;

    /**
     * Check whether this provider supports a specific capability.
     *
     * Use this instead of retrieving and searching the full list.
     */
    public function hasCapability(SmsCapability $cap): bool;

    /**
     * Per-segment cost in millicents (USD 0.00001).
     *
     * 5¢ = 5000, 4.3¢ = 4300, etc.
     */
    public function getSegmentCost(): int;

    /**
     * List all phone numbers that have opted out / unsubscribed.
     *
     * Fetches all pages from the upstream provider internally — the
     * caller receives a flat list.  Expected volume is small; caching
     * is the caller's responsibility.
     *
     * @return \Result<OptOutEntry[], string>
     */
    public function listOptOuts(): \Result;

    /**
     * Remove a phone number from the upstream opt-out list.
     *
     * Gated on the REMOVE_OPT_OUT capability.  Providers that don't
     * support removal return a failure.
     *
     * @return \Result<bool, string>
     */
    public function removeOptOut(PhoneNumber $number): \Result;

    /**
     * Maximum delay in seconds for deferred (scheduled) sends, or null if unspecified.
     *
     * When the provider has DEFERRED_SEND capability, this returns the max
     * seconds ahead that $sendAt may be from now().  Returns null when the
     * provider does not enforce a limit (or does not support deferred send).
     *
     * Callers use this to set the `max` attribute on schedule-datetime inputs
     * and to validate schedule times server-side before sending.
     *
     * @return int|null max seconds or null (unspecified / no limit / N/A)
     */
    public function getDeferredSendMaxDelay(): ?int;

    /**
     * Query recent delivery statuses from the provider in one batch call.
     *
     * Returns all SmsDelivery objects since $since (Unix timestamp).
     * Providers that don't support batch queries return an empty array;
     * the caller falls back to per-delivery updateDelivery().
     *
     * @param ?int $since Unix timestamp — return deliveries after this time. null = last 24h.
     * @return \Result<SmsDelivery[], string>
     */
    public function listRecentDeliveries(?int $since = null): \Result;
}
