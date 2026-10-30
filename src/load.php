<?php

/**
 * Canonical loader for the jethro-sms engine.
 *
 * Require this one file to get the whole pure layer: the s-expression
 * Templater, the SmsProvider interface with all
 * value objects and decorators, the concrete providers (under Sms\Providers),
 * and the statusline/segment maths.
 *
 * Load order respects dependencies: enums and interfaces before
 * implementations, base classes before subclasses.
 */

// \Result comes from jethro/jethro-utils. Embedded in Jethro,
// include/general.php has loaded it; standalone entry points
// (bin/jethro-sms, tests/run.php) load the Composer autoloader first.

// Enums (no dependencies)
require_once __DIR__ . '/SmsCapability.php';
require_once __DIR__ . '/SmsStatus.php';

// Interfaces
require_once __DIR__ . '/SmsCache.php';

// Sender/recipient identity types (SmsSender, SmsRecipient, PhoneNumber, etc.)
require_once __DIR__ . '/SmsSender.php';

// Value objects
require_once __DIR__ . '/FormField.php';
require_once __DIR__ . '/RegistrationStep.php';
require_once __DIR__ . '/SenderNumberRecord.php';

// SendSummary tagged union + implementations
require_once __DIR__ . '/SendSummary.php';

// Configuration source for provider factories
require_once __DIR__ . '/SmsSettings.php';

// SmsProvider interface (depends on SmsCapability, RegistrationStep, SendSummary)
require_once __DIR__ . '/SmsProvider.php';

// S-expression templater
require_once __DIR__ . '/TemplateException.php';
require_once __DIR__ . '/Templater.php';

// SmsDelivery + base classes (subclasses are in Providers/)
require_once __DIR__ . '/SmsDelivery.php';

// HTTP infrastructure (HttpClient, NativeHttpClient, etc.)
require_once __DIR__ . '/HttpClient.php';

// Provider decorators (depend on SmsProvider)
require_once __DIR__ . '/DecoratingSmsProvider.php';
require_once __DIR__ . '/TokenExpandingSmsProvider.php';
require_once __DIR__ . '/DeduplicatingSmsProvider.php';
require_once __DIR__ . '/OptOutSmsProvider.php';
require_once __DIR__ . '/OverridingSmsProvider.php';

// Fake HTTP client base (subclasses are in Providers/)
require_once __DIR__ . '/FakeHttpClient.php';

// Concrete providers (Sms\Providers namespace)
require_once __DIR__ . '/Providers/TemplateSmsProvider.php';
require_once __DIR__ . '/Providers/FiveCentSmsV4Provider.php';
require_once __DIR__ . '/Providers/FiveCentSmsV5Provider.php';
require_once __DIR__ . '/Providers/CellcastSmsProvider.php';
require_once __DIR__ . '/Providers/MobileMessageSmsProvider.php';

// Provider-specific delivery types
require_once __DIR__ . '/Providers/FiveCentSmsDelivery.php';
require_once __DIR__ . '/Providers/CellcastSmsDelivery.php';
require_once __DIR__ . '/Providers/MobileMessageSmsDelivery.php';

// Provider-specific fake HTTP clients
require_once __DIR__ . '/Providers/CellcastFakeHttpClient.php';
require_once __DIR__ . '/Providers/FiveCentSmsV5FakeHttpClient.php';
require_once __DIR__ . '/Providers/MobileMessageFakeHttpClient.php';
require_once __DIR__ . '/Providers/TemplateFakeHttpClient.php';

// Standalone functions (depend on all types above)
require_once __DIR__ . '/functions.php';

// Statusline (depends on functions and SmsStatuslineConfig)
require_once __DIR__ . '/SmsStatuslineConfig.php';
require_once __DIR__ . '/sms_statusline.php';

// Provider factory
require_once __DIR__ . '/factory.php';

// CLI
require_once __DIR__ . '/Cli/CliEnvironment.php';
require_once __DIR__ . '/Cli/CliOutcome.php';
require_once __DIR__ . '/Cli/CliError.php';
require_once __DIR__ . '/Cli/Presenter.php';
require_once __DIR__ . '/Cli/TextPresenter.php';
require_once __DIR__ . '/Cli/JsonPresenter.php';
require_once __DIR__ . '/cli.php';
