<?php

declare(strict_types=1);

namespace Sms;

/**
 * Base class for SmsProvider decorators.
 *
 * Delegates every SmsProvider instance method to an inner provider.
 * Subclasses override only the methods they need to intercept.
 *
 * ## Static methods and the decorator contract
 *
 * {@see fromSettings()}, {@see getConstants()}, and {@see usagePreference()}
 * are **concrete-provider** concerns — they describe how a raw gateway class
 * is constructed from injected SmsSettings and what priority it has for auto-detection.
 * Decorators don't participate in this: they wrap an already-instantiated
 * inner provider, so calling these static methods on a decorator class is
 * meaningless.  All three throw {@see \RuntimeException} with a descriptive
 * message rather than silently returning garbage.
 *
 * To get constants or preference from a decorated chain, walk to the
 * innermost provider with {@see getInner()}:
 *
 * ```php
 * $inner = $provider;
 * while ($inner instanceof \Sms\DecoratingSmsProvider) {
 *     $inner = $inner->getInner();
 * }
 * $constants = $inner::getConstants();
 * ```
 *
 * {@see withCache()} is the exception among factory-ish methods: it IS an
 * instance method, so it CAN delegate: it clones this decorator (keeping its
 * constructor state) and swaps in `$this->inner->withCache($cache)`.
 */

class DecoratingSmsProvider implements SmsProvider
{
    public function __construct(
        protected SmsProvider $inner,
    )
    {
    }

    /**
     * Access the innermost concrete provider for metadata queries.
     *
     * Walk the chain to the end:
     *
     * ```php
     * $inner = $provider;
     * while ($inner instanceof DecoratingSmsProvider) {
     *     $inner = $inner->getInner();
     * }
     * ```
     */
    public function getInner(): SmsProvider
    {
        return $this->inner;
    }

    public static function fromSettings(SmsSettings $settings, ?string $baseUrl = null): static
    {
        throw new \RuntimeException(__CLASS__ . ' wraps an existing SmsProvider; use new ' . __CLASS__ . '($provider)');
    }

    public function withCache(SmsCache $cache): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withCache($cache);
        return $clone;
    }

    /** @return \Result<SmsDeliveryBatch, string> */
    public function send(
        array     $entries,
        SmsSender $sender,
        ?int      $sendAt = null,
        bool      $preview = false,
    ): \Result
    {
        return $this->inner->send($entries, $sender, $sendAt, $preview);
    }

    public function getSenderIds(bool $getAll = false): \Result
    {
        return $this->inner->getSenderIds($getAll);
    }

    public function getBalance(): \Result
    {
        return $this->inner->getBalance();
    }

    public function isOperational(): \Result
    {
        return $this->inner->isOperational();
    }

    public function updateDelivery(SmsDelivery $delivery): \Result
    {
        return $this->inner->updateDelivery($delivery);
    }

    public function cancel(SmsDeliveryBatch $batch): \Result
    {
        return $this->inner->cancel($batch);
    }

    /** @inheritDoc */
    public function getSenderNumbers(): \Result
    {
        return $this->inner->getSenderNumbers();
    }

    /** @inheritDoc */
    public function verifySenderNumber(PhoneNumber $number): \Result
    {
        return $this->inner->verifySenderNumber($number);
    }

    /** @inheritDoc */
    public function registerSenderNumber(?ContactPhoneNumber $contact = null, ?array $validationParams = null): \Result
    {
        return $this->inner->registerSenderNumber($contact, $validationParams);
    }

    /** @inheritDoc */
    public function registerSenderId(?SenderID $senderId = null, ?array $validationParams = null): \Result
    {
        return $this->inner->registerSenderId($senderId, $validationParams);
    }

    /** @inheritDoc */
    public function deleteSenderNumber(PhoneNumber $number): \Result
    {
        return $this->inner->deleteSenderNumber($number);
    }

    /** @inheritDoc */
    public function getSenderNumberRecords(): \Result
    {
        return $this->inner->getSenderNumberRecords();
    }

    /** @inheritDoc */
    public function listOptOuts(): \Result
    {
        return $this->inner->listOptOuts();
    }

    /** @inheritDoc */
    public function removeOptOut(PhoneNumber $number): \Result
    {
        return $this->inner->removeOptOut($number);
    }

    /** @return array<array{string, string, string}> */
    public static function getConstants(): array
    {
        throw new \RuntimeException(__CLASS__ . ' wraps an existing SmsProvider; call getConstants() on the inner provider');
    }

    public static function usagePreference(): int
    {
        throw new \RuntimeException(__CLASS__ . ' wraps an existing SmsProvider; call usagePreference() on the inner provider');
    }

    public function getDescription(): string
    {
        return $this->inner->getDescription();
    }

    public function getKey(): ?string
    {
        return $this->inner->getKey();
    }

    public function hasCapability(SmsCapability $cap): bool
    {
        return $this->inner->hasCapability($cap);
    }

    public function getSegmentCost(): int
    {
        return $this->inner->getSegmentCost();
    }

    public function getDeferredSendMaxDelay(): ?int
    {
        return $this->inner->getDeferredSendMaxDelay();
    }

    public function listRecentDeliveries(?int $since = null): \Result
    {
        return $this->inner->listRecentDeliveries($since);
    }
}
