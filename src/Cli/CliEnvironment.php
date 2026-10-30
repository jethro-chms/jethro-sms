<?php

declare(strict_types=1);

namespace Sms\Cli;

/**
 * Host-supplied wiring for the jethro-sms CLI.
 *
 * Injects a provider factory, optional recipient resolver, cancel handler,
 * and extra actions so the CLI can run in both standalone and Jethro-embedded
 * modes without duplicating dispatch logic.
 *
 * @see main()
 */

final class CliEnvironment
{
	/**
	 * @param \Closure $providerFactory   fn(bool $logToDb): \Result — Result of \Sms\SmsProvider
	 * @param ?\Closure $recipientResolver fn(string $toArg): ?\Sms\SmsRecipient; null = warn + skip.
	 *                                     When absent, recipients must be phone numbers.
	 * @param ?\Closure $cancel            fn(\Sms\SmsDeliveryBatch): \Result; when absent the
	 *                                     provider's own cancel() is used.
	 * @param array<string, \Closure>  $extraActions   action name => fn(array $args, Presenter $ui): void
	 * @param array<string, string>    $extraActionHelp action name => usage text shown under
	 *                                     it in printUsage() (lines after the first are
	 *                                     indented to the action-description column)
	 * @param ?\Closure $extraInfoLines   fn(): array<string,string> — extra lines for `info`
	 * @param string $usageProgram        program name shown in usage examples
	 * @param string $usageEnvPrefix      env-var prefix shown before usage examples
	 */
	public function __construct(
		public \Closure  $providerFactory,
		public ?\Closure $recipientResolver = null,
		public ?\Closure $cancel = null,
		public array     $extraActions = [],
		public array     $extraActionHelp = [],
		public ?\Closure $extraInfoLines = null,
		public string    $usageProgram = 'bin/jethro-sms',
		public string    $usageEnvPrefix = '',
	) {
	}
}
