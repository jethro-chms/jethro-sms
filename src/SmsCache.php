<?php

declare(strict_types=1);

namespace Sms;

/**
 * Simple key-value cache with no built-in TTL.
 *
 * TTL is intentionally omitted — the providers control invalidation.
 * For example, balance is cached until the next send() call, at which
 * point the provider deletes the cache entry.  This avoids staleness
 * without requiring a clock.
 *
 * The cache is nullable throughout the provider layer (passed as
 * ?SmsCache).  When null, caching is silently skipped.  This lets
 * tests and custom providers omit caching entirely.
 * @see SmsProvider
 */

interface SmsCache
{
    public function get(string $key): mixed;

    /**
     * Store a value with an optional TTL in seconds.
     * 0 (default) means no expiry — the value persists until explicitly deleted.
     */
    public function set(string $key, mixed $value, int $ttl = 0): void;

    public function delete(string $key): void;
}
