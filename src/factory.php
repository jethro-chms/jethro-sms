<?php

/**
 * Raw-provider selection: short-name map, auto-detection candidates, and
 * SMS_PROVIDER resolution.
 *
 * Extracted verbatim from Jethro's getSmsProvider() so that every host
 * (the Jethro bridge, the standalone CLI) shares one resolution algorithm.
 * Hosts wrap the returned class in their own decorator chains and render
 * their own "not configured" help from providerCandidates().
 *
 * Detection rules (see also docs/architecture: Provider auto-detection):
 * candidates are sorted by usagePreference() descending; the first whose
 * required constants are all defined wins. The deprecated v4 provider only
 * matches when SMS_HTTP_URL is exactly the v4 endpoint. Providers with
 * usagePreference() < 0 are excluded from "not configured" help text.
 */

namespace Sms;
use Sms\Providers\CellcastSmsProvider;
use Sms\Providers\FiveCentSmsV4Provider;
use Sms\Providers\FiveCentSmsV5Provider;
use Sms\Providers\MobileMessageSmsProvider;
use Sms\Providers\TemplateSmsProvider;


/**
 * Short configuration keys accepted in SMS_PROVIDER.
 *
 * @return array<string, class-string<SmsProvider>>
 */
function providerShortNames(): array
{
	return [
		'5centsmsv5' => FiveCentSmsV5Provider::class,
		'cellcast' => CellcastSmsProvider::class,
		'mobilemessage' => MobileMessageSmsProvider::class,
	];
}

/**
 * Auto-detection candidates, sorted by usagePreference() descending.
 *
 * @return list<array{0: class-string<SmsProvider>, 1: string}>  [class, human label]
 */
function providerCandidates(): array
{
	$candidates = [
		[MobileMessageSmsProvider::class, 'MobileMessage'],
		[FiveCentSmsV5Provider::class, '5CentSMS (v5)'],
		[CellcastSmsProvider::class, 'Cellcast'],
		[FiveCentSmsV4Provider::class, '5CentSMS (v4)'],
		[TemplateSmsProvider::class, 'Template'],
	];
	usort($candidates, static fn ($a, $b) => $b[0]::usagePreference() <=> $a[0]::usagePreference());
	return $candidates;
}

/**
 * Resolve the raw provider class from SMS_PROVIDER (short key, FQCN, or
 * 'auto'/undefined for auto-detection).
 *
 * @param array<string, class-string<SmsProvider>> $hostProviders  Extra short
 *     keys supplied by the host application for its own provider classes
 *     (key => class). Built-in keys win on a collision. Host providers are
 *     only selected by an explicit SMS_PROVIDER, never auto-detected.
 * @return \Result  Success with class-string<SmsProvider>. Failure with
 *     array{message: string, notConfigured?: bool} — notConfigured is set
 *     when no provider matched, so hosts can append candidate/constant help
 *     (built from providerCandidates() + getConstants()) in their own
 *     presentation format.
 */
function resolveRawProviderClass(array $hostProviders = []): \Result
{
	$settings = new ConstantSettings();
	$class = null;
	if ($settings['SMS_PROVIDER']->val()) {
		$raw = SMS_PROVIDER;
		if (!\is_string($raw)) {
			return \Result::failure(['message' => 'SMS_PROVIDER must be a fully-qualified class name string (e.g. \\Sms\\Providers\\CellcastSmsProvider::class), got ' . \get_debug_type($raw)]);
		}
		if ($raw !== 'auto') {
			// Short key (e.g. '5centsmsv5') or FQCN
			$class = (providerShortNames() + $hostProviders)[$raw] ?? $raw;
			if (!\class_exists($class)) {
				return \Result::failure(['message' => 'SMS_PROVIDER class not found: ' . $class . '. Use a leading backslash (\\Sms\\Providers\\CellcastSmsProvider::class) if the class is not in the current namespace.']);
			}
			if (!is_subclass_of($class, SmsProvider::class)) {
				return \Result::failure(['message' => 'SMS_PROVIDER must implement SmsProvider: ' . $class]);
			}
		}
	}

	if ($class === null) {
		// Auto-detect: iterate providers sorted by usagePreference (descending),
		// pick the first whose required constants are all defined.
		foreach (providerCandidates() as [$candidate]) {
			$missing = false;
			foreach ($candidate::getConstants() as [$key, $required]) {
				if ($required === 'required' && (string) $settings[$key]->val('') === '') {
					$missing = true;
					break;
				}
			}
			if (!$missing) {
				// V4-specific: only match if SMS_HTTP_URL is the v4 endpoint
				if ($candidate === FiveCentSmsV4Provider::class) {
					$url = rtrim((string) $settings['SMS_HTTP_URL']->val(''), '/');
					if (!\in_array($url, ['https://www.5centsms.com.au/api/v4/sms', 'https://www.5centsms.com.au/api/v4'], true)) {
						continue;
					}
				}
				$class = $candidate;
				break;
			}
		}
	}

	if ($class === null) {
		return \Result::failure(['message' => 'SMS not configured.', 'notConfigured' => true]);
	}

	return \Result::success($class);
}
