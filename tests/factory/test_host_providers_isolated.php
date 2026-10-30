<?php

/**
 * Unit tests for resolveRawProviderClass($hostProviders): a host application
 * can map its own short key in SMS_PROVIDER to its own provider class.
 *
 * @isolated-process — defines SMS_PROVIDER, which is process-global.
 */

namespace Test\Sms\FactoryHostIsolated;

use function \Test\{test, assert_true, assert_eq, assert_contains};
use \Sms\Providers\TemplateSmsProvider;

require_once __DIR__ . '/../../src/load.php';

define('SMS_PROVIDER', 'hostkey');

/** Stand-in for a provider class that lives in the host application. */
class HostProvider extends TemplateSmsProvider {}

test('a host short key resolves to the host class', function () {
	$r = \Sms\resolveRawProviderClass(['hostkey' => HostProvider::class]);
	assert_true($r->isSuccess());
	assert_eq($r->getValue(), HostProvider::class);
});

test('without the host mapping the key is an unknown class', function () {
	$r = \Sms\resolveRawProviderClass();
	assert_true($r->isFailure());
	assert_contains($r->getError()['message'], 'class not found: hostkey');
});
