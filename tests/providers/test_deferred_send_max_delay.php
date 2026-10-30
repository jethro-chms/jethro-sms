<?php

/**
 * Unit tests for getDeferredSendMaxDelay() across all providers.
 *
 * Pins improvement 48 (per-provider deferred-send max delay).
 */

namespace Test\Sms\DeferredSendMaxDelay;

use function \Test\{test, assert_true, assert_false, assert_eq};
use \Sms\{
    DecoratingSmsProvider,
    OverridingSmsProvider,
    SmsCapability};
use Sms\Providers\TemplateSmsProvider;
use Sms\Providers\FiveCentSmsV5Provider;
use Sms\Providers\CellcastSmsProvider;
use Sms\Providers\MobileMessageSmsProvider;

require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// FiveCentSmsV5Provider → 365 days
// ---------------------------------------------------------------------------

test('FiveCentSmsV5Provider getDeferredSendMaxDelay() → 365 days (31536000)', function () {
    $p = new FiveCentSmsV5Provider(
        url: 'https://example.com',
        keyId: 'key',
        keySecret: 'secret',
    );
    $delay = $p->getDeferredSendMaxDelay();
    assert_eq($delay, 365 * 86400, 'Expected 365 days (31536000 seconds)');
});

// ---------------------------------------------------------------------------
// CellcastSmsProvider → 24 hours
// ---------------------------------------------------------------------------

test('CellcastSmsProvider getDeferredSendMaxDelay() → 24 hours (86400)', function () {
    $p = new CellcastSmsProvider(
        apiToken: 'tok',
        url: 'https://example.com',
    );
    $delay = $p->getDeferredSendMaxDelay();
    assert_eq($delay, 86400, 'Expected 24 hours (86400 seconds)');
});

// ---------------------------------------------------------------------------
// MobileMessageSmsProvider → null (only a 60s minimum is documented)
// ---------------------------------------------------------------------------

test('MobileMessageSmsProvider getDeferredSendMaxDelay() → null', function () {
    $p = new MobileMessageSmsProvider(
        username: 'u',
        password: 'p',
        url: 'https://example.com',
    );
    $delay = $p->getDeferredSendMaxDelay();
    assert_eq($delay, null, 'Expected null (unspecified)');
});

// ---------------------------------------------------------------------------
// TemplateSmsProvider → null (unspecified)
// ---------------------------------------------------------------------------

test('TemplateSmsProvider getDeferredSendMaxDelay() → null', function () {
    $p = new TemplateSmsProvider(
        url: 'https://example.com',
        postTemplate: 'data',
    );
    $delay = $p->getDeferredSendMaxDelay();
    assert_eq($delay, null, 'Expected null (unspecified)');
});

// ---------------------------------------------------------------------------
// DecoratingSmsProvider → delegates to inner
// ---------------------------------------------------------------------------

test('DecoratingSmsProvider getDeferredSendMaxDelay() delegates to inner (365 days)', function () {
    $inner = new FiveCentSmsV5Provider(
        url: 'https://example.com',
        keyId: 'key',
        keySecret: 'secret',
    );
    $decorator = new class($inner) extends DecoratingSmsProvider {};
    $delay = $decorator->getDeferredSendMaxDelay();
    assert_eq($delay, 365 * 86400, 'Decorator must return inner value (365 days)');
});

test('DecoratingSmsProvider getDeferredSendMaxDelay() delegates to inner (null)', function () {
    $inner = new TemplateSmsProvider(url: 'https://example.com', postTemplate: 'data');
    $decorator = new class($inner) extends DecoratingSmsProvider {};
    $delay = $decorator->getDeferredSendMaxDelay();
    assert_eq($delay, null, 'Decorator must return inner value (null)');
});

// ---------------------------------------------------------------------------
// OverridingSmsProvider → delegates through two decorator layers
// ---------------------------------------------------------------------------

test('OverridingSmsProvider (decorator) delegates getDeferredSendMaxDelay() correctly', function () {
    $inner = new CellcastSmsProvider(apiToken: 'tok', url: 'https://example.com');
    $overriding = new OverridingSmsProvider($inner);
    $delay = $overriding->getDeferredSendMaxDelay();
    assert_eq($delay, 86400, 'OverridingSmsProvider must delegate to Cellcast (24h)');
});

// ---------------------------------------------------------------------------
// Consistency: providers with DEFERRED_SEND have a meaningful delay
// ---------------------------------------------------------------------------

test('Providers with DEFERRED_SEND capability are consistent with getDeferredSendMaxDelay()', function () {
    $v5 = new FiveCentSmsV5Provider(url: 'https://example.com', keyId: 'k', keySecret: 's');
    $cellcast = new CellcastSmsProvider(apiToken: 'tok', url: 'https://example.com');

    // Providers that declare DEFERRED_SEND
    assert_true($v5->hasCapability(SmsCapability::DEFERRED_SEND), 'v5 must have DEFERRED_SEND');
    assert_true($cellcast->hasCapability(SmsCapability::DEFERRED_SEND), 'cellcast must have DEFERRED_SEND');

    // v5 and cellcast have finite max delays
    assert_true($v5->getDeferredSendMaxDelay() > 0, 'v5 delay must be positive');
    assert_true($cellcast->getDeferredSendMaxDelay() > 0, 'cellcast delay must be positive');
});
