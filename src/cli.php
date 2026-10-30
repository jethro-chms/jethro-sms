<?php

/**
 * CLI core for the jethro-sms engine — the package's end product.
 *
 * All argument parsing, action dispatch and execution live here,
 * parameterised by a {@see CliEnvironment} so different hosts can supply
 * their own provider chain and recipient semantics:
 *
 *  - bin/jethro-sms (standalone): pure chain built from SMS_* constants,
 *    phone-number recipients only, no DB logging.
 *  - Jethro's scripts/sms.php (wrapper): full bridge chain (DB logging,
 *    token expansion), person-ID recipients, export-smslog extra action.
 *
 * Execution and presentation are separate: handlers return a
 * {@see CliOutcome} and never print; main() picks a {@see TextPresenter}
 * (default) or {@see JsonPresenter} (`--json`) and renders once. Handlers
 * narrate long steps through Presenter::progress().
 *
 * Extracted from Jethro's scripts/sms.php — see docs/extraction.md §5.
 */

namespace Sms\Cli;
use Sms\PhoneNumber;
use Sms\SenderID;


/**
 * CLI entry point: parse global flags, dispatch $argv[1] to a handler, render
 * its outcome and exit.
 */
function main(array $argv, CliEnvironment $env): never
{
	$args = $argv;
	array_shift($args); // Remove script path

	// --json: one JSON document on stdout, progress on stderr.  Stripped
	// before dispatch so handlers only see their own action's arguments.
	$json = false;
	$args = array_values(array_filter($args, static function (string $arg) use (&$json): bool {
		if ($arg === '--json') {
			$json = true;
			return false;
		}
		return true;
	}));

	if (count($args) < 1) {
		printUsage($env);
		exit(1);
	}

	$ui = $json ? new JsonPresenter() : new TextPresenter();
	$action = $args[0];

	$known = array_merge(
		['sms', 'senderids', 'sendernums', 'sendernums-unregistered', 'is-sendernum-registered',
		 'balance', 'estimate', 'status', 'smsinfo', 'cancel',
		 'validate-sendernum', 'complete-sendernum-validation', 'delete-sendernum',
		 'register-sender-id', 'optouts', 'remove-optout', 'info', 'list'],
		array_keys($env->extraActions),
	);

	// Host extra actions print for themselves; in JSON mode their text is
	// captured and returned as data.text so stdout stays one document.
	if (isset($env->extraActions[$action])) {
		if ($json) {
			ob_start();
			($env->extraActions[$action])($args, $ui);
			$outcome = CliOutcome::success($action, ['text' => (string) ob_get_clean()]);
		} else {
			($env->extraActions[$action])($args, $ui);
			$outcome = CliOutcome::success($action);
		}
		$ui->render($outcome);
		exit($outcome->exitCode);
	}

	try {
		$outcome = match ($action) {
			'sms'                    => handleSms($env, $args, $ui),
			'senderids'              => handleSenderIds($env, $ui),
			'sendernums'             => handleSenderNums($env, $ui),
			'sendernums-unregistered' => handleUnregisteredSenderNums($env, $ui),
			'is-sendernum-registered'          => handleIsSendernumRegistered($env, $args, $ui),
			'balance'                => handleBalance($env, $ui),
			'estimate'               => handleEstimate($env, $args, $ui),
			'status'                 => handleStatus($env, $ui),
			'smsinfo'                => handleSmsInfo($env, $args, $ui),
			'cancel'                 => handleCancel($env, $args, $ui),
			'validate-sendernum' => handleValidateSendernum($env, $args, $ui),
			'complete-sendernum-validation' => handleCompleteSendernumValidation($env, $args, $ui),
			'delete-sendernum'       => handleDeleteSendernum($env, $args, $ui),
			'register-sender-id'     => handleRegisterSenderId($env, $args, $ui),
			'optouts'                => handleOptOuts($env, $ui),
			'remove-optout'          => handleRemoveOptOut($env, $args, $ui),
			'info'                   => handleInfo($env, $ui),
			'list'                   => handleList($env, $args, $ui),
			default                  => throw new CliError("Unknown action '{$action}'. Must be one of: " . implode(', ', $known) . ".\n"),
		};
	} catch (CliError $e) {
		$outcome = CliOutcome::failure($action, $e->getMessage(), $e->exitCode());
	} catch (\InvalidArgumentException $e) {
		// e.g. PhoneNumber rejects a value with no digits; --json callers must
		// still get exactly one document.
		$outcome = CliOutcome::failure($action, $e->getMessage());
	}

	$ui->render($outcome);
	exit($outcome->exitCode);
}

// ===========================================================================
// Shared helpers
// ===========================================================================

/**
 * Mask credential values so `info` output (text or --json) is as safe to share
 * as the verbose HTTP log, which {@see \Sms\redactCredentials()} covers.
 *
 * HTTP templates are masked too: they often embed an API key or password.
 */
function maskConstantValue(string $name, mixed $value): mixed
{
	if (!preg_match('/(APIKEY|API_KEY|PASSWORD|SECRET|TOKEN|PRIVATE_KEY|HEADER_TEMPLATE|POST_TEMPLATE)/i', $name)) {
		return $value;
	}
	return $value === null || $value === '' ? $value : '****';
}

/**
 * Parse --key=value pairs from an argument array, populating $opts and collecting
 * positional arguments into $positional.
 *
 * @param string[]                $args        Argument list ($argv with script + action already stripped)
 * @param array<string, string[]> &$opts       Out: key => value[] map (supports repeated --to, etc.)
 * @param string[]                &$positional Out: positional arguments (not starting with --)
 */
function parseArgs(array $args, array &$opts, array &$positional): void
{
	foreach ($args as $arg) {
		if (str_starts_with($arg, '--')) {
			$eq = strpos($arg, '=');
			if ($eq !== false) {
				$key = substr($arg, 2, $eq - 2);
				$val = substr($arg, $eq + 1);
				$opts[$key][] = $val;
			}
		} else {
			$positional[] = $arg;
		}
	}
}

/**
 * Get the configured SMS provider from the host factory or abort the action.
 *
 * When the failure is "not configured" (see \Sms\resolveRawProviderClass()),
 * append plain-text per-provider constant help.
 */
function requireProvider(CliEnvironment $env, bool $logToDb = true): \Sms\SmsProvider
{
	$providerResult = ($env->providerFactory)($logToDb);
	if ($providerResult->isFailure()) {
		$error = $providerResult->getError();
		if (\is_array($error) && !empty($error['notConfigured'])) {
			throw new CliError(($error['message'] ?? 'SMS not configured.') . "\n" . renderCandidateHelpText());
		}
		throw new CliError(\is_array($error) ? ($error['message'] ?? 'SMS not configured.') : $error);
	}
	return $providerResult->getValue();
}

/**
 * Plain-text version of the "not configured" provider/constant help
 * (the Jethro bridge renders the same data as HTML for the web UI).
 */
function renderCandidateHelpText(): string
{
	$out = "Set SMS_PROVIDER or define the required constants for one of these providers:\n";
	foreach (\Sms\providerCandidates() as [$candidate, $label]) {
		if ($candidate::usagePreference() < 0) {
			continue;
		}
		$out .= "\n  $label\n";
		foreach ($candidate::getConstants() as [$key, $required, $purpose]) {
			$defined = (string) (new \Sms\ConstantSettings())[$key]->val('') !== '';
			$out .= sprintf("    [%s] %-28s %-8s  %s\n", $defined ? 'set' : ' - ', $key, $required, $purpose);
		}
	}
	return $out;
}

/**
 * Resolve one --to argument to a recipient, or null to skip it (a warning
 * has already been printed). Delegates to the host resolver when present;
 * standalone accepts phone numbers only.
 */
function resolveRecipient(CliEnvironment $env, string $toArg): ?\Sms\SmsRecipient
{
	if ($env->recipientResolver !== null) {
		return ($env->recipientResolver)($toArg);
	}
	if (ctype_digit($toArg) && strlen($toArg) <= 6) {
		throw new CliError("Recipient '$toArg' looks like a Jethro person ID — person-ID recipients require the Jethro wrapper (scripts/sms.php); pass a full mobile number instead.");
	}
	return new PhoneNumber($toArg);
}

// ===========================================================================
// Handlers
//
// Each returns a CliOutcome and prints nothing: text/JSON rendering happens
// in the presenter, so --json cannot drift from the text output.
// ===========================================================================

function handleSms(CliEnvironment $env, array $args, Presenter $ui): CliOutcome
{
	// Skip action, parse remaining
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$from = $opts['from'][0] ?? null;
	$to   = $opts['to'] ?? [];
	$sendAtRaw = $opts['sendat'][0] ?? null;
	$sendAt = $sendAtRaw !== null ? (int) $sendAtRaw : null;
	$messageText = implode(' ', $pos);

	if ($from === null) {
		throw new CliError("--from=<sender> is required for 'sms' action.\n");
	}
	if ($to === []) {
		throw new CliError("--to=<recipient> is required for 'sms' action.\n");
	}
	if ($messageText === '') {
		throw new CliError("<message> is required for 'sms' action.\n");
	}

	$sender = \Sms\senderFromString($from) ?? throw new CliError("--from=<sender> must not be blank.");

	$recipients = [];
	foreach ($to as $toArg) {
		$r = resolveRecipient($env, $toArg);
		if ($r !== null) {
			$recipients[] = $r;
		}
	}

	if ($recipients === []) {
		throw new CliError('No valid recipients.');
	}

	// Host-resolved (person) recipients get DB logging; raw phone numbers don't.
	$hasPersonIds = false;
	foreach ($recipients as $r) {
		if (!$r instanceof PhoneNumber) {
			$hasPersonIds = true;
			break;
		}
	}
	$provider = requireProvider($env, logToDb: $hasPersonIds);

	// Fail locally when the provider would reject the schedule anyway
	// (5CentSMS: 365 days; MobileMessage: its own limit) instead of spending
	// a round trip on it.
	if ($sendAt !== null) {
		$maxDelay = $provider->getDeferredSendMaxDelay();
		if ($maxDelay !== null && $sendAt > time() + $maxDelay) {
			throw new CliError(
				'--sendat is too far ahead: this provider allows at most '
				. formatDuration($maxDelay) . ' from now.'
			);
		}
	}

	$ui->progress('Sending SMS to ' . count($recipients) . " recipient(s)...\n");

	$result = $provider->send(
		entries: [['message' => $messageText, 'recipients' => $recipients]],
		sender: $sender,
		sendAt: $sendAt,
	);

	if ($result->isFailure()) {
		throw new CliError($result->getError());
	}

	/** @var \Sms\SmsDelivery[] $results */
	$results = $result->getValue()->deliveries;
	$summary = \Sms\sendSummary($results, $recipients);
	if ($summary instanceof \Sms\Failed) {
		throw new CliError($summary->error);
	}

	return CliOutcome::success('sms', ['summary' => $summary, 'deliveries' => $results]);
}

function handleSenderIds(CliEnvironment $env, Presenter $ui): CliOutcome
{
	$provider = requireProvider($env);

	$result = $provider->getSenderIds(getAll: true);
	if ($result->isFailure()) {
		throw new CliError('Failed to fetch sender IDs: ' . $result->getError());
	}

	// Enrich with record-level data where the provider has it.  Records
	// include alphanumeric IDs (despite the method's name) and carry the
	// registration attempt date derived from the gateway record id; a
	// provider without a record-level list (or a failed call) just means the
	// date is unknown, not that the listing failed.
	$records = [];
	$recordsResult = $provider->getSenderNumberRecords();
	if ($recordsResult->isSuccess()) {
		foreach ($recordsResult->getValue() as $record) {
			$records[$record->value] = $record;
		}
	}

	$rows = [];
	foreach ($result->getValue() as $senderId) {
		$rows[] = [
			'value'        => $senderId->value,
			'acmaApproved' => $senderId->acmaApproved,
			'registeredAt' => ($records[$senderId->value] ?? null)?->registeredAt,
		];
	}

	return CliOutcome::success('senderids', $rows);
}

function handleSenderNums(CliEnvironment $env, Presenter $ui): CliOutcome
{
	$provider = requireProvider($env);

	$result = $provider->getSenderNumbers();
	if ($result->isFailure()) {
		throw new CliError('Failed to fetch sender numbers: ' . $result->getError());
	}

	return CliOutcome::success('sendernums', $result->getValue());
}

/**
 * List sender numbers whose registration was attempted but never verified.
 *
 * These are the entries that sit on the provider account unapproved — they
 * cannot be used as senders, and a stale one can block a fresh registration
 * attempt.  Each carries the date of the last attempt (the record's creation
 * date; re-registering re-creates the record with a fresh date).
 *
 * Uses getSenderNumberRecords() — the same source as the System
 * Configuration panel — because getSenderNumbers() only returns approved
 * numbers.
 */
function handleUnregisteredSenderNums(CliEnvironment $env, Presenter $ui): CliOutcome
{
	$provider = requireProvider($env);

	$result = $provider->getSenderNumberRecords();
	if ($result->isFailure()) {
		throw new CliError('Failed to fetch sender numbers: ' . $result->getError());
	}

	$rows = [];
	foreach ($result->getValue() as $record) {
		if (!$record->isPhoneNumber() || $record->isApproved()) {
			continue;
		}
		$rows[] = [
			'number'       => $record->value,
			'status'       => $record->status,
			'registeredAt' => $record->registeredAt,
		];
	}

	return CliOutcome::success('sendernums-unregistered', $rows);
}

function handleBalance(CliEnvironment $env, Presenter $ui): CliOutcome
{
	$provider = requireProvider($env);

	$result = $provider->getBalance();
	if ($result->isFailure()) {
		throw new CliError('Could not retrieve account balance: ' . $result->getError());
	}

	return CliOutcome::success('balance', $result->getValue());
}

/**
 * Check whether a phone number is an approved sender on the account.
 *
 * Answers the question the sender dropdown answers in the web UI — useful in
 * scripts deciding whether to send as a particular number.  A "no" is a
 * valid answer, so the exit code stays 0; scripts read `data.approved`.
 */
function handleIsSendernumRegistered(CliEnvironment $env, array $args, Presenter $ui): CliOutcome
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$phoneNumber = $opts['phonenumber'][0] ?? null;
	if ($phoneNumber === null) {
		throw new CliError("--phonenumber=<number> is required for 'is-sendernum-registered' action.\n");
	}

	$provider = requireProvider($env, logToDb: false);
	$number = new \Sms\PhoneNumber($phoneNumber);

	$result = $provider->verifySenderNumber($number);
	if ($result->isFailure()) {
		throw new CliError('Could not verify sender number: ' . $result->getError());
	}

	return CliOutcome::success('is-sendernum-registered', [
		'number'   => $number->value,
		'approved' => $result->getValue() === true,
	]);
}

/**
 * Estimate segments and cost for a message without sending it.
 *
 * Mirrors the compose statusline's maths (sms_statusline.php): GSM 03.38
 * messages pack 160/153 characters per segment, UCS-2 (anything containing a
 * non-GSM character, e.g. an emoji or a curly quote) only 70/67.  Cost uses
 * the provider's per-segment price.
 */
function handleEstimate(CliEnvironment $env, array $args, Presenter $ui): CliOutcome
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$text = implode(' ', $pos);
	if ($text === '') {
		throw new CliError("<message> is required for 'estimate' action.\n");
	}

	$provider = requireProvider($env, logToDb: false);

	return CliOutcome::success('estimate', estimateMessage($text, $provider->getSegmentCost()));
}

/**
 * Segments, encoding and cost for one message.
 *
 * Pure function (no provider calls) so the maths is unit-testable; the
 * provider only supplies the per-segment price.
 *
 * @return array{
 *     message: string,
 *     encoding: string,
 *     segments: int,
 *     segmentCostMillicents: int,
 *     costPerRecipientMillicents: int,
 *     nonGsmChars: list<string>
 * }
 */
function estimateMessage(string $text, int $segmentCostMillicents): array
{
	$nonGsm = \Sms\getNonGsmChars($text);
	$isUcs2 = $nonGsm !== [];
	$segments = $isUcs2
		? \Sms\ucs2SegmentCount(\Sms\utf16Length($text))
		: \Sms\gsmSegmentCount(\Sms\gsmLength($text));

	return [
		'message'                    => $text,
		'encoding'                   => $isUcs2 ? 'UCS-2' : 'GSM',
		'segments'                   => $segments,
		'segmentCostMillicents'      => $segmentCostMillicents,
		'costPerRecipientMillicents' => $segments * $segmentCostMillicents,
		'nonGsmChars'                => array_values(\Sms\getNonGsmDisplayChars($text)),
	];
}

/**
 * Live operational check: are the gateway and the credentials working now?
 *
 * Unlike `info` (static configuration) and `balance` (which needs the
 * GET_BALANCE capability), this uses the provider's own isOperational()
 * probe — works for every provider.  Exits non-zero when it fails, so it
 * can drive a monitoring check.
 */
function handleStatus(CliEnvironment $env, Presenter $ui): CliOutcome
{
	$provider = requireProvider($env);

	$result = $provider->isOperational();
	$operational = $result->isSuccess() && $result->getValue() === true;

	if (!$operational) {
		$reason = $result->isFailure() ? $result->getError() : 'Provider did not respond';
		throw new CliError($provider->getDescription() . ' is not operational — ' . $reason);
	}

	return CliOutcome::success('status', [
		'key'         => $provider->getKey(),
		'description' => $provider->getDescription(),
		'operational' => true,
	]);
}

/**
 * Human duration for the deferred-send window, e.g. 365 days.
 */
function formatDuration(int $seconds): string
{
	if ($seconds % 86400 === 0) {
		$n = intdiv($seconds, 86400);
		return $n . ' ' . ($n === 1 ? 'day' : 'days');
	}
	if ($seconds % 3600 === 0) {
		$n = intdiv($seconds, 3600);
		return $n . ' ' . ($n === 1 ? 'hour' : 'hours');
	}
	if ($seconds % 60 === 0) {
		$n = intdiv($seconds, 60);
		return $n . ' ' . ($n === 1 ? 'minute' : 'minutes');
	}
	return $seconds . ' seconds';
}

function handleInfo(CliEnvironment $env, Presenter $ui): CliOutcome
{
	$provider = requireProvider($env);

	// Walk decorator chain to the inner concrete provider for static metadata methods
	$inner = $provider;
	while ($inner instanceof \Sms\DecoratingSmsProvider) {
		$inner = $inner->getInner();
	}

	$extra = $env->extraInfoLines !== null ? ($env->extraInfoLines)() : [];

	$constants = [];
	foreach ($inner::getConstants() as [$key, $required]) {
		$constants[] = [
			'key'      => $key,
			'required' => $required,
			'defined'  => defined($key),
			'value'    => defined($key) ? maskConstantValue($key, constant($key)) : null,
		];
	}

	$capabilities = [];
	foreach (\Sms\SmsCapability::cases() as $cap) {
		if ($provider->hasCapability($cap)) {
			$capabilities[] = $cap->name;
		}
	}

	return CliOutcome::success('info', [
		'provider'                    => $provider->getDescription(),
		'key'                         => $provider->getKey(),
		'extra'                       => $extra,
		'capabilities'                => $capabilities,
		'segmentCostMillicents'       => $provider->getSegmentCost(),
		'deferredSendMaxDelaySeconds' => $provider->getDeferredSendMaxDelay(),
		'constants'                   => $constants,
	]);
}

function handleSmsInfo(CliEnvironment $env, array $args, Presenter $ui): CliOutcome
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$id = $opts['id'][0] ?? null;
	if ($id === null) {
		throw new CliError("--id=<remote_ref> is required for 'smsinfo' action.\n");
	}

	$provider = requireProvider($env);

	// Construct a minimal delivery for the provider to query
	$delivery = new \Sms\SmsDelivery(
		recipient: new \Sms\PhoneNumber('0000000000'),
		status: \Sms\SmsStatus::SENDING,
		remoteId: $id,
	);

	$result = $provider->updateDelivery($delivery);
	if ($result->isFailure()) {
		throw new CliError('Could not retrieve SMS info: ' . $result->getError());
	}

	return CliOutcome::success('smsinfo', $result->getValue());
}

function handleCancel(CliEnvironment $env, array $args, Presenter $ui): CliOutcome
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$id = $opts['id'][0] ?? null;
	if ($id === null) {
		throw new CliError("--id=<remote_ref> is required for 'cancel' action.\n");
	}

	$ui->progress("Cancelling SMS $id...\n");

	// Wrap the single synthetic delivery (fake recipient — DB-less debug tool)
	// in a batch so the cancel hook can accept it.
	$delivery = new \Sms\SmsDelivery(
		recipient: new \Sms\PhoneNumber('0000000000'),
		status: \Sms\SmsStatus::SCHEDULED,
		remoteId: $id,
	);
	$batch = new \Sms\SmsDeliveryBatch(null, [$delivery]);

	$result = $env->cancel !== null
		? ($env->cancel)($batch)
		: requireProvider($env, logToDb: false)->cancel($batch);
	if ($result->isFailure()) {
		throw new CliError('Could not cancel SMS: ' . $result->getError());
	}

	/** @var \Sms\SmsDeliveryBatch $cancelledBatch */
	$cancelledBatch = $result->getValue();

	return CliOutcome::success('cancel', $cancelledBatch->deliveries[0]);
}

/**
 * Ask the provider to verify a phone number for use as a sender — the
 * initial "I would like to validate this number" step.
 *
 * Phase 1 of the sender-number state machine: 5CentSMS texts a verification
 * link to the handset (and only reports "pending" until it is clicked);
 * Cellcast texts an OTP, which the caller submits with
 * `complete-sendernum-validation`.  The result's fields say what (if
 * anything) the provider needs next.
 */
function handleValidateSendernum(CliEnvironment $env, array $args, Presenter $ui): CliOutcome
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$phoneNumber = $opts['phonenumber'][0] ?? null;
	$label       = $opts['label'][0] ?? null;

	$provider = requireProvider($env, logToDb: true);

	if (!$provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_NUMBER)) {
		return CliOutcome::success('validate-sendernum', null, 'This API provider does not support sender-number validation.');
	}

	if ($phoneNumber === null) {
		throw new CliError("--phonenumber=<number> is required for 'validate-sendernum' action.\n");
	}

	$contact = new \Sms\ContactPhoneNumber(new \Sms\PhoneNumber($phoneNumber), $label ?? 'CLI registration');

	$ui->progress("Registering phone number {$contact->phoneNumber->value} as a sender...\n");

	$result = $provider->registerSenderNumber($contact, null);

	if ($result->isFailure()) {
		throw new CliError('Registration failed: ' . $result->getError());
	}

	return CliOutcome::success('validate-sendernum', ['step' => $result->getValue()]);
}

/**
 * Complete verification of a sender phone number — phase 2.
 *
 * Submits the provider-specific validation fields (e.g. the OTP for
 * Cellcast) to finish the registration started by `validate-sendernum`.
 * With no fields supplied it shows the schema the provider expects.
 */
function handleCompleteSendernumValidation(CliEnvironment $env, array $args, Presenter $ui): CliOutcome
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$phoneNumber = $opts['phonenumber'][0] ?? null;
	if ($phoneNumber === null) {
		throw new CliError("--phonenumber=<number> is required for 'complete-sendernum-validation' action.\n");
	}

	$provider = requireProvider($env, logToDb: true);

	if (!$provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_NUMBER)) {
		return CliOutcome::success('complete-sendernum-validation', null, 'This API provider does not support sender-number validation.');
	}

	$contact = new \Sms\ContactPhoneNumber(new \Sms\PhoneNumber($phoneNumber), $opts['label'][0] ?? 'CLI registration');

	// Collect validation params (exclude phonenumber and label — they're in $contact)
	$params = [];
	foreach ($opts as $key => $values) {
		if ($key !== 'phonenumber' && $key !== 'label') {
			$params[$key] = $values[0];
		}
	}

	if ($params === []) {
		// Asking the provider for the schema re-runs registration (phase 1),
		// which sends a fresh OTP or verification link and can invalidate the
		// code the user already has. Say what is needed instead.
		return CliOutcome::success('complete-sendernum-validation', null,
			'Supply the validation parameters from the provider, e.g. --otp=<code>. Nothing was sent.');
	}

	// Submit validation
	$result = $provider->registerSenderNumber($contact, $params);

	if ($result->isFailure()) {
		throw new CliError('Validation failed: ' . $result->getError());
	}

	return CliOutcome::success('complete-sendernum-validation', [
		'step' => $result->getValue(),
		'mode' => 'submitted',
	]);
}

/**
 * Remove a registered sender phone number from the provider account.
 *
 * Usage: delete-sendernum --phonenumber=<number>
 *
 * Gated on the DELETE_SENDER_NUMBER capability.  The provider resolves the
 * number to its own record id (5CentSMS deletes by gateway record id, not
 * by number), so the CLI only needs the number.  Irreversible upstream —
 * the number must re-verify before it can be used again.
 */
function handleDeleteSendernum(CliEnvironment $env, array $args, Presenter $ui): CliOutcome
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$phoneNumber = $opts['phonenumber'][0] ?? null;
	if ($phoneNumber === null) {
		throw new CliError("--phonenumber=<number> is required for 'delete-sendernum' action.\n");
	}

	$provider = requireProvider($env, logToDb: true);

	if (!$provider->hasCapability(\Sms\SmsCapability::DELETE_SENDER_NUMBER)) {
		return CliOutcome::success('delete-sendernum', null, 'This API provider lacks the ability to delete Sender Numbers.');
	}

	$number = new \Sms\PhoneNumber($phoneNumber);

	$ui->progress("Deleting sender number {$number->value}...\n");

	$result = $provider->deleteSenderNumber($number);

	if ($result->isFailure()) {
		throw new CliError('Deletion failed: ' . $result->getError());
	}

	return CliOutcome::success('delete-sendernum', ['number' => $number->value]);
}

function handleRegisterSenderId(CliEnvironment $env, array $args, Presenter $ui): CliOutcome
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$provider = requireProvider($env, logToDb: true);

	if (!$provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_ID)) {
		return CliOutcome::success('register-sender-id', null, 'This API provider lacks the ability to register Sender IDs.');
	}

	$senderIdStr = $opts['senderid'][0] ?? '';
	if ($senderIdStr === '') {
		throw new CliError('--senderid is required');
	}
	$senderId = new SenderID($senderIdStr);

	// Phase 1: Get the field schema (may create the sender ID upstream)
	$schemaResult = $provider->registerSenderId($senderId, null);
	if ($schemaResult->isFailure()) {
		throw new CliError($schemaResult->getError());
	}
	$schema = $schemaResult->getValue();
	$fields = $schema->fields;

	// Collect known field params from opts (excluding senderid, already consumed)
	$knownNames = array_map(fn($f) => $f->name, $fields);
	$params = [];
	foreach ($knownNames as $name) {
		if ($name === 'senderid') continue;
		if (isset($opts[$name])) {
			$params[$name] = $opts[$name][0];
		}
	}

	// No validation params → the caller gets the field schema back
	if ($params === []) {
		return CliOutcome::success('register-sender-id', ['step' => $schema, 'mode' => 'schema']);
	}

	$ui->progress("Registering sender ID...\n");
	$result = $provider->registerSenderId($senderId, $params);

	if ($result->isFailure()) {
		throw new CliError($result->getError());
	}

	return CliOutcome::success('register-sender-id', ['step' => $result->getValue(), 'mode' => 'confirmed']);
}

// ===========================================================================
// Opt-out handlers
// ===========================================================================

function handleOptOuts(CliEnvironment $env, Presenter $ui): CliOutcome
{
	$provider = requireProvider($env, logToDb: false);

	if (!$provider->hasCapability(\Sms\SmsCapability::LIST_OPT_OUTS)) {
		return CliOutcome::success('optouts', null, 'This API provider does not support listing opt-outs.');
	}

	$result = $provider->listOptOuts();
	if ($result->isFailure()) {
		throw new CliError('Failed to list opt-outs: ' . $result->getError());
	}

	return CliOutcome::success('optouts', $result->getValue());
}

function handleRemoveOptOut(CliEnvironment $env, array $args, Presenter $ui): CliOutcome
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$number = $opts['number'][0] ?? null;
	if ($number === null) {
		throw new CliError("--number=<phone_number> is required for 'remove-optout' action.\n");
	}

	$provider = requireProvider($env, logToDb: false);

	if (!$provider->hasCapability(\Sms\SmsCapability::REMOVE_OPT_OUT)) {
		return CliOutcome::success('remove-optout', null, 'This API provider does not support removing opt-outs.');
	}

	$phoneNumber = new \Sms\PhoneNumber($number);
	$ui->progress("Removing {$phoneNumber->value} from opt-out list...\n");

	$result = $provider->removeOptOut($phoneNumber);
	if ($result->isFailure()) {
		throw new CliError('Failed to remove opt-out: ' . $result->getError());
	}

	return CliOutcome::success('remove-optout', ['number' => $phoneNumber->value]);
}

function handleList(CliEnvironment $env, array $args, Presenter $ui): CliOutcome
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$sinceRaw = $opts['since'][0] ?? null;
	$since = $sinceRaw !== null ? (int) $sinceRaw : null;

	$provider = requireProvider($env, logToDb: false);

	$result = $provider->listRecentDeliveries($since);
	if ($result->isFailure()) {
		throw new CliError('Failed to list recent deliveries: ' . $result->getError());
	}

	return CliOutcome::success('list', [
		'deliveries' => $result->getValue(),
		'since'      => $since,
	]);
}

// ===========================================================================
// Usage
// ===========================================================================

function printUsage(CliEnvironment $env): void
{
	$prog = $env->usageEnvPrefix . $env->usageProgram;

	$senderNumberNote = '';
	$senderIdBlock    = '';
	$provider         = null;
	try {
		$providerResult = ($env->providerFactory)(false);
		if ($providerResult->isSuccess()) {
			$provider = $providerResult->getValue();

			if ($provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_ID)) {
				$schemaResult = $provider->registerSenderId();
				$step = $schemaResult->isSuccess() ? $schemaResult->getValue() : null;
				$fields = $step !== null ? $step->fields : [];
				if ($fields !== []) {
					$lines = [];
					foreach ($fields as $f) {
						$req = $f->required ? ' (required)' : ' (optional)';
						$lines[] = "                        --{$f->name}=...{$req}";
					}
					$senderIdBlock = "\n                           Pass with no extra args to see required fields,\n                           then pass the fields below to submit the registration:\n" . implode("\n", $lines);
				}
			} else {
				$senderIdBlock = "\n                           This API provider lacks the ability to register Sender IDs.";
			}

			if (!$provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_NUMBER)) {
				$senderNumberNote = "\n                           This API provider does not support sender-number validation.";
			}
		}
	} catch (\Throwable) {
		// Provider not available — leave help sections empty
	}

	$senderNumberArgs = $senderNumberNote === ''
		? "\n                           Required: --phonenumber=<number>\n                           Optional: --label=<name>  (human-readable label)"
		: '';

	$validateNumberNote = '';
	if ($senderNumberNote === '' && $provider !== null) {
		// Show provider-specific validation fields
		$schemaResult = $provider->registerSenderNumber();
		$step   = $schemaResult->isSuccess() ? $schemaResult->getValue() : null;
		$fields = $step?->fields ?? [];
		$message = $step?->message ?? null;
		if ($message !== null && $message !== '') {
			$validateNumberNote = "\n                           " . str_replace("\n", "\n                           ", $message);
		}
		if ($fields !== []) {
			$lines = [];
			foreach ($fields as $f) {
				if ($f->type !== 'hidden') {
					$req = $f->required ? ' (required)' : '';
					$lines[] = "                        --{$f->name}=...{$req}";
				}
			}
			$validateNumberNote .= "\n                           Required fields:\n" . implode("\n", $lines);
		}
	} elseif ($senderNumberNote !== '') {
		$validateNumberNote = "\n                           This API provider does not support sender-number validation.";
	}

	$extraActionLines = '';
	foreach ($env->extraActions as $name => $_) {
		$help = str_replace("\n", "\n              ", $env->extraActionHelp[$name] ?? '');
		$label = strlen($name) < 12 ? str_pad($name, 12) : "$name\n              ";
		$extraActionLines .= "\n  " . ($help === '' ? $name : $label . $help) . "\n";
	}

	echo <<<USAGE
Usage: {$prog} [--json] <action> [options] [message]

  --json      Print one JSON document instead of text (progress goes to
              stderr). Payloads are provider-independent value objects.

Actions:
  sms         Send an SMS message
              Required: --from=<sender> --to=<recipient> <message>
              Optional: --sendat=<unix_timestamp>  (deferred delivery,
                  e.g. --sendat=\$(date -d '+2 hours' +%s))
              --to can be specified multiple times

  senderids   List registered sender IDs

  sendernums  List registered sender numbers (phone numbers)

  sendernums-unregistered
              List sender numbers whose registration was attempted but never
              verified, with the date of the last attempt

  is-sendernum-registered
              Check whether a phone number is an approved sender
              Required: --phonenumber=<number>

  estimate    Segments, encoding and cost for a message, without sending it
              Usage: estimate <message>

  status      Live check that the provider is reachable and the credentials work
              (exits non-zero when it is not)

  balance     Check the current account balance

  info        Show provider description and current constant values

  smsinfo     Get delivery status for a previously sent SMS
              Required: --id=<remote_ref>

  cancel      Cancel a previously sent (scheduled/deferred) SMS
              Required: --id=<remote_ref>


  list        List recent deliveries (batch status query)
              Optional: --since=<unix_timestamp>  (default: last 24 hours,
                  e.g. --since=\$(date -d '-2 days' +%s))

  optouts     List opted-out / unsubscribed phone numbers

  remove-optout  Remove a phone number from the opt-out list
                 Required: --number=<phone_number>

  validate-sendernum       Ask the provider to verify a phone number for use as a
                           sender (sends the verification link / OTP){$senderNumberNote}{$senderNumberArgs}

  complete-sendernum-validation
                           Submit the verification code to complete sender-number
                           registration{$validateNumberNote}

  delete-sendernum         Remove a registered sender number from the account
                           Required: --phonenumber=<number>  (irreversible)

  register-sender-id       Register a sender ID (business identity){$senderIdBlock}
{$extraActionLines}
Examples:
  {$prog} sms --from=Jethro --to=0491570159 "Meeting at 7pm"
  {$prog} sms --from=Jethro --to=0491570159 --to=0491570158 "Reminder"
  {$prog} sms --from=Jethro --to=0491570159 --sendat=1717000000 "Deferred message"
  {$prog} senderids
  {$prog} sendernums
  {$prog} sendernums-unregistered
  {$prog} is-sendernum-registered --phonenumber=61491570006
  {$prog} validate-sendernum --phonenumber=0491570159
  {$prog} complete-sendernum-validation --phonenumber=0491570159 --otp=123456
  {$prog} estimate "Meeting at 7pm — bring a friend 😊"
  {$prog} status
  {$prog} --json balance | jq .data.balance
  {$prog} optouts
  {$prog} remove-optout --number=61491570156
  {$prog} delete-sendernum --phonenumber=0491570159
  {$prog} info
  {$prog} smsinfo --id=6a1439267bbc1d12b20627ea
  {$prog} list
  {$prog} list --since=\$(date -d '-2 days' +%s)
  {$prog} cancel --id=6a17f61098ec8240f4060994

USAGE;
}
