<?php

declare(strict_types=1);

namespace Sms\Providers;
use Sms\ContactPhoneNumber;
use Sms\FakeHttpClient;
use Sms\HttpClient;
use Sms\HttpRequest;
use Sms\HttpResponse;
use Sms\LoggingHttpClient;
use Sms\NativeHttpClient;
use Sms\OverridingSmsProvider;
use Sms\PhoneNumber;
use Sms\SenderID;
use Sms\SmsCache;
use Sms\SmsCapability;
use Sms\SmsSettings;
use Sms\SmsDelivery;
use Sms\SmsDeliveryBatch;
use Sms\SmsProvider;
use Sms\SmsRecipient;
use Sms\SmsSender;
use Sms\SmsStatus;


/**
 * The default SMS provider that uses template-based configuration
 * (SMS_HTTP_URL, SMS_HTTP_POST_TEMPLATE, SMS_HTTP_HEADER_TEMPLATE, etc.).
 *
 * This is the v4-style provider — everything is a URL-encoded POST body
 * with placeholders.  It can talk to any gateway that accepts
 * form-encoded HTTP POSTs, not just 5centsms.
 *
 * Settings read by fromSettings():
 *   SMS_HTTP_URL                   — endpoint URL (required)
 *   SMS_HTTP_POST_TEMPLATE         — POST body template with placeholders
 *   SMS_HTTP_HEADER_TEMPLATE       — extra HTTP headers (e.g. auth)
 *   SMS_HTTP_RESPONSE_OK_REGEX     — per-recipient success regex
 *   SMS_HTTP_RESPONSE_ERROR_REGEX  — whole-batch failure regex
 *   SMS_HTTP_RESPONSE_ID_REGEX     — regex to extract remote message ID
 *   SMS_HTTP_URL_BALANCE           — balance endpoint URL
 *   SMS_LOCAL_PREFIX               — local dialling prefix to strip (e.g. '0')
 *   SMS_INTERNATIONAL_PREFIX       — country code to prepend (e.g. '61')
 *   SMS_RECIPIENT_ARRAY_PARAMETER  — POST param name for array-style recipients
 *   SMS_TESTMODE                   — bool; when true, enables test-mode HTTP interception
 *                                      (FakeHttpClient returns 'OK' for POST requests)
 *   SMS_VERBOSE                    — bool; logs request/response to error_log
 *   SMS_SENDER_OPTIONS                  — comma-separated sender options, may include _USER_MOBILE_ (overrides API)
 *
 * Sender override is handled by OverridingSmsProvider via SMS_SENDER.
 *
 * Test mode: when testMode is true, the httpClient is wrapped in a FakeHttpClient
 * which returns HttpResponse('OK') for POST requests (send).  The normal response
 * parsers handle 'OK' as "all recipients succeeded".
 *
 * @see SmsProvider
 * @see FiveCentSmsV4Provider
 */

class TemplateSmsProvider implements SmsProvider
{
    /**
     * @param string $url HTTP endpoint URL to POST the request to.
     * @param string $postTemplate POST body template with placeholders (_MESSAGE_, _RECIPIENTS_COMMAS_, etc.)
     * @param string $headerTemplate Extra HTTP headers (e.g. "X-Api-Key: abc\r\n").
     * @param string $responseOkRegex Regex matched per-recipient against the gateway response body.
     * @param string $responseErrorRegex Regex matched against the entire response for a batch failure signal.
     * @param string $responseIdRegex Regex with capture group to extract the remote message ID.
     * @param string $localPrefix Local dialling prefix stripped when converting to international format.
     * @param string $internationalPrefix Country code prepended when converting to international format.
     * @param string $recipientArrayParameter POST param name for array-style recipients (e.g. 'number').
     * @param string $balanceUrl URL for the balance/account endpoint. Empty = unsupported.
     * @param bool $testMode When true, wraps httpClient in FakeHttpClient so send() returns mock successes.
     * @param bool $verbose When true, logs request/response to PHP error log.
     * @param string $senderIds Comma-separated Sender IDs; overrides API-discovered IDs.
     * @param string $senderId Hardcoded sender ID for all sends.
     * @param HttpClient|null $httpClient Custom HTTP client (injected for testing).
     * @param SmsCache|null $cache Optional cache (unused by TemplateSmsProvider, accepted for API symmetry).
     */
    public function __construct(
        /** HTTP endpoint URL to POST the request to. */
        private string      $url,
        /**
         * POST body template with placeholders for substitution.
         *
         * Supported placeholders:
         *  - `_MESSAGE_` — URL-encoded message text
         *  - `_RECIPIENTS_COMMAS_` — comma-separated, URL-encoded local numbers
         *  - `_RECIPIENTS_NEWLINES_` — newline-separated, URL-encoded local numbers
         *  - `_RECIPIENTS_ARRAY_` — array-style POST params (requires `$recipientArrayParameter`)
         *  - `_RECIPIENTS_INTERNATIONAL_COMMAS_` — comma-separated international numbers
         *  - `_RECIPIENTS_INTERNATIONAL_NEWLINES_` — newline-separated international numbers
         *  - `_RECIPIENTS_INTERNATIONAL_ARRAY_` — array-style international numbers
         *  - `_USER_MOBILE_` — sender's resolved mobile number
         */
        private string      $postTemplate,
        /** Extra HTTP headers beyond Content-Type and Content-Length (e.g. `User: me@example.com\r\nApi-Key: abc123\r\n`). */
        private string      $headerTemplate = '',
        /**
         * Regex matched per-recipient against the gateway response body.
         *
         * The placeholders `_RECIPIENT_` (local number) and `_RECIPIENT_INTERNATIONAL_`
         * (international number) are substituted before matching. A match means that
         * specific recipient was accepted by the gateway.
         */
        private string      $responseOkRegex = '',
        /**
         * Regex matched against the entire gateway response body.
         *
         * A match means the whole batch send failed (e.g. bad credentials, gateway error)
         * regardless of per-recipient results.
         */
        private string      $responseErrorRegex = '',
        /**
         * Regex with a capture group to extract the remote message ID from the
         * gateway response body. The `_RECIPIENT_` placeholder is substituted
         * before matching, and the first capture group is used as the ID.
         */
        private string      $responseIdRegex = '',
        /**
         * Local dialling prefix stripped when converting numbers to international format
         * (e.g. `0` → `61400123456`).
         */
        private string      $localPrefix = '',
        /**
         * Country code prepended when converting numbers to international format
         * (e.g. `61`).
         */
        private string      $internationalPrefix = '',
        /**
         * If set, enables `_RECIPIENTS_ARRAY_` and `_RECIPIENTS_INTERNATIONAL_ARRAY_`
         * placeholders. Controls the POST parameter name (e.g. `number` produces
         * `number[]=61400&number[]=61401`).
         */
        private string      $recipientArrayParameter = '',
        /** URL for the balance/account endpoint. */
        private string      $balanceUrl = '',
        /** Comma-separated Sender IDs. When set, overrides API-discovered Sender IDs. */
        private string      $senderIds = '',
        private ?HttpClient $httpClient = null,
        private ?SmsCache   $cache = null,
        /** Email substituted for `_USER_EMAIL_`; set per request via {@see withUserEmail()}. */
        private ?string     $userEmail = null,
    )
    {
    }

    /**
     * Supply the current user's email for the `_USER_EMAIL_` placeholder.
     *
     * The package has no access to Jethro's user system, so the bridge injects
     * it. A template that uses `_USER_EMAIL_` with no email supplied (cron, CLI)
     * fails loudly rather than sending the literal keyword, as 2.39 did.
     */
    public function withUserEmail(?string $email): static
    {
        $clone = clone $this;
        $clone->userEmail = $email;
        return $clone;
    }

    /**
     * Create a provider from the given settings.
     *
     * Reads the SMS_HTTP_* settings.
     *
     * @param string|null $baseUrl Optional base URL; prepended to bare-path URLs.
     * @throws \RuntimeException when SMS_HTTP_URL is missing
     */
    public static function fromSettings(SmsSettings $settings, ?string $baseUrl = null): static
    {
        $url = (string)$settings['SMS_HTTP_URL']->val('');

        if ($url !== '' && $url[0] === '/' && $baseUrl !== null) {
            $origin = parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST);
            $port = parse_url($baseUrl, PHP_URL_PORT);
            if ($port !== null) $origin .= ':' . $port;
            $url = $origin . $url;
        }

        if ($url === '') {
            throw new \RuntimeException('Missing SMS configuration: ' . $settings['SMS_HTTP_URL']->getName());
        }

        $postTemplate = (string)$settings['SMS_HTTP_POST_TEMPLATE']->val('');

        $headerTemplate = (string)$settings['SMS_HTTP_HEADER_TEMPLATE']->val('');

        $responseOkRegex = (string)$settings['SMS_HTTP_RESPONSE_OK_REGEX']->val('');

        $responseErrorRegex = (string)$settings['SMS_HTTP_RESPONSE_ERROR_REGEX']->val('');

        $responseIdRegex = (string)$settings['SMS_HTTP_RESPONSE_ID_REGEX']->val('');

        $balanceUrl = (string)$settings['SMS_HTTP_URL_BALANCE']->val('');

        return new static(
            url: $url,
            postTemplate: $postTemplate,
            headerTemplate: $headerTemplate,
            responseOkRegex: $responseOkRegex,
            responseErrorRegex: $responseErrorRegex,
            responseIdRegex: $responseIdRegex,
            // Empty setting (e.g. blank row in `setting` table) means "use default".
            localPrefix: (string)$settings['SMS_LOCAL_PREFIX']->val('') ?: '0',
            internationalPrefix: (string)$settings['SMS_INTERNATIONAL_PREFIX']->val('') ?: '61',
            recipientArrayParameter: (string)$settings['SMS_RECIPIENT_ARRAY_PARAMETER']->val(''),
            balanceUrl: $balanceUrl,
            httpClient: static::createHttpClient(
                filter_var($settings['SMS_TESTMODE']->val(false), FILTER_VALIDATE_BOOLEAN),
                filter_var($settings['SMS_VERBOSE']->val(false), FILTER_VALIDATE_BOOLEAN),
            ),
            senderIds: (string)$settings['SMS_SENDER_OPTIONS']->val(''),
        );
    }

    /**
     * Create the HTTP client for TemplateSmsProvider (and subclasses).
     */
    public static function createHttpClient(bool $testMode, bool $verbose): HttpClient
    {
        $client = $testMode ? new TemplateFakeHttpClient(new NativeHttpClient()) : new NativeHttpClient();
        if ($verbose) {
            $client = new LoggingHttpClient($client);
        }
        return $client;
    }

    /** Template-based providers don't use caching; returns $this unchanged. */
    public function withCache(SmsCache $cache): static
    {
        return $this;
    }

    /**
     * Whether the POST template requires the caller to supply a sender mobile number.
     *
     * Used by jethro_sms.php to determine whether to warn about missing SMS_SENDER
     * configuration when the template uses _USER_MOBILE_.
     */
    public function usesUserMobile(): bool
    {
        return str_contains($this->postTemplate, '_USER_MOBILE_');
    }

    public function getBalance(): \Result
    {
        if ($this->balanceUrl === '') {
            return \Result::failure('Unsupported operation');
        }

        $request = new HttpRequest(
            method: 'GET',
            url: $this->balanceUrl,
            headers: $this->headerTemplate ?: '',
            body: '',
        );

        $response = $this->httpClient->send($request);
        if ($response->isFailure()) {
            return \Result::failure('Balance request failed: ' . $response->getError());
        }

        return $this->parseBalance($response->getValue());
    }

    private function parseBalance(HttpResponse $response): \Result
    {
        $data = json_decode($response->body, true);

        if (!\is_array($data)) {
            return \Result::failure('Invalid JSON response from balance API. Raw: ' . $response->body);
        }

        if (!empty($data['error'])) {
            return \Result::failure($data['error'] . ' — raw: ' . $response->body);
        }

        if (!isset($data['account']['balance'])) {
            return \Result::failure('No balance field in account response. Raw: ' . $response->body);
        }

        return \Result::success((int)$data['account']['balance']);
    }

    public function isOperational(): \Result
    {
        $cached = $this->cache?->get('sms_operational');
        if ($cached !== null) {
            return \Result::success((bool)$cached);
        }

        if ($this->balanceUrl === '') {
            return \Result::success(true);
        }

        $balanceResult = $this->getBalance();
        if ($balanceResult->isSuccess()) {
            $this->cache?->set('sms_operational', true, 300);
            return \Result::success(true);
        }

        $this->cache?->set('sms_operational', false, 60);
        return \Result::success(false);
    }

    public function getSenderIds(bool $getAll = false): \Result
    {
        // No API-based sender ID discovery; OverridingSmsProvider handles SMS_SENDER_OPTIONS.
        return \Result::success([]);
    }

    /** Delivery status updates are not supported by template-based providers. */
    public function updateDelivery(SmsDelivery $delivery): \Result
    {
        return \Result::failure('SMS delivery status is not available for this provider. Use the v5 API (SMS_5CENTSMS_* constants) for this feature.');
    }

    /** Not supported by template-based providers. */
    public function cancel(SmsDeliveryBatch $batch): \Result
    {
        return \Result::failure('Cancelling SMS is not available for this provider. Use the v5 API.');
    }

    /** @return array<array{string, string, string}> */
    public static function getConstants(): array
    {
        return [
            ['SMS_HTTP_URL', 'required', 'Gateway endpoint URL'],
            ['SMS_HTTP_POST_TEMPLATE', 'required', 'POST body template with _RECIPIENT_/_MESSAGE_ placeholders'],
            ['SMS_HTTP_RESPONSE_OK_REGEX', 'required', 'Regex for successful send responses'],
            ['SMS_HTTP_HEADER_TEMPLATE', 'optional', 'Additional HTTP headers'],
            ['SMS_HTTP_RESPONSE_ERROR_REGEX', 'optional', 'Regex for error responses'],
            ['SMS_HTTP_RESPONSE_ID_REGEX', 'optional', 'Regex to extract remote message ID from response'],
            ['SMS_HTTP_URL_BALANCE', 'optional', 'Balance endpoint URL'],
            ['SMS_SENDER_OPTIONS', 'optional', 'Comma-separated sender options, may include _USER_MOBILE_'],
            ['SMS_TESTMODE', 'optional', 'Dry-run test mode: bool (returns "OK" for POST requests)'],
            ['SMS_VERBOSE', 'optional', 'Log HTTP to error log'],
        ];
    }



    /** The configured HTTP gateway is unknown, so its API can't be identified. */
    public function getKey(): ?string
    {
        return null;
    }

    public static function usagePreference(): int
    {
        return -2;
    }

    public function getDescription(): string
    {
        return \Sms\redactCredentials($this->url);
    }

    /** @inheritDoc */
    public function getSenderNumbers(): \Result
    {
        return \Result::success([]);
    }

    /** @inheritDoc */
    public function verifySenderNumber(PhoneNumber $number): \Result
    {
        return \Result::success(true);
    }

    /** @inheritDoc */
    public function registerSenderNumber(?ContactPhoneNumber $contact = null, ?array $validationParams = null): \Result
    {
        return \Result::failure('Not supported');
    }

    /** @inheritDoc */
    public function registerSenderId(?SenderID $senderId = null, ?array $validationParams = null): \Result
    {
        return \Result::failure('Not supported');
    }

    /** @inheritDoc */
    public function listOptOuts(): \Result
    {
        return \Result::failure('Not supported');
    }

    /** @inheritDoc */
    public function removeOptOut(PhoneNumber $number): \Result
    {
        return \Result::failure('Not supported');
    }

    public function deleteSenderNumber(PhoneNumber $number): \Result
    {
        return \Result::failure('Not supported');
    }

    public function getSenderNumberRecords(): \Result
    {
        return \Result::failure('Not supported');
    }

    public function hasCapability(SmsCapability $cap): bool
    {
        if ($cap === SmsCapability::GET_BALANCE && $this->balanceUrl !== '') {
            return true;
        }
        return false;
    }

    /**
     * Build mock SmsDelivery objects for preview mode (no HTTP).
     *
     * @param SmsRecipient[] $recipients
     * @return SmsDelivery[]
     */
    private function mockDeliveries(string $message, array $recipients): array
    {
        return array_map(
            fn(SmsRecipient $r) => new SmsDelivery(
                recipient: $r->getPhoneNumber(),
                status: SmsStatus::QUEUED,
                message: $message,
            ),
            $recipients,
        );
    }

    public const SEGMENT_COST_MILLICENTS = 5000;

    public function getSegmentCost(): int
    {
        return static::SEGMENT_COST_MILLICENTS;
    }

    public function getDeferredSendMaxDelay(): ?int
    {
        return null; // Template-based providers don't support deferred send
    }

    public function listRecentDeliveries(?int $since = null): \Result
    {
        return \Result::success([]);
    }

    /**
     * Send an SMS via the template-based provider.
     *
     * @param string $message The message text
     * @param array<int, array{message: string, recipients: SmsRecipient[]}> $entries
     * @param SmsSender $sender Sender number or ID
     * @param int|null $sendAt Not supported by template-based providers
     * @param bool $preview When true, returns mock deliveries without sending.
     * @return \Result<SmsDeliveryBatch, string>
     */
    public function send(
        array     $entries,
        SmsSender $sender,
        ?int      $sendAt = null,
        bool      $preview = false,
    ): \Result
    {
        $senderNumber = (string) $sender;
        $allDeliveries = [];
        $entries = array_values($entries);

        foreach ($entries as $index => $entry) {
            $message = $entry['message'];
            $recipients = $entry['recipients'];

            if ($preview) {
                foreach ($this->mockDeliveries($message, $recipients) as $d) {
                    $allDeliveries[] = $d;
                }
                continue;
            }

            $recipientNumbers = array_map(static fn(SmsRecipient $p) => $p->getPhoneNumber()->value, $recipients);
            try {
                $request = $this->buildHttpRequest($message, $recipientNumbers, $senderNumber);
            } catch (\RuntimeException $e) {
                // _USER_EMAIL_ in the template with no current user (cron/CLI).
                // This is a configuration error, so nothing has been sent yet.
                return \Result::failure($e->getMessage());
            }

            $response = $this->httpClient->send($request);

            if ($response->isFailure()) {
                // Earlier entries may already have been accepted and charged;
                // keep them so the whole send is logged and a retry is honest.
                return \Sms\finishStoppedSend($entries, $index, $allDeliveries, $response->getError(), mayHaveBeenSent: true);
            }

            foreach ($this->parseResponse($response->getValue(), $recipientNumbers) as $d) {
                $allDeliveries[] = $d->with(message: $message);
            }
        }

        return \Result::success(new SmsDeliveryBatch(null, $allDeliveries));
    }

    /**
     * `param[]=a&param[]=b` for an array-style POST body.
     *
     * The brackets and separators stay literal; only the values are encoded,
     * which is the form gateways expect (2.39 sent the same).
     *
     * @param string[] $values
     */
    private static function arrayParams(string $param, array $values): string
    {
        return implode('&', array_map(
            static fn(string $v): string => $param . '[]=' . urlencode($v),
            $values,
        ));
    }

    /**
     * Build an HTTP POST request by substituting placeholders in the config template.
     *
     * URL-encoding rules:
     *   - _MESSAGE_, _RECIPIENTS_COMMAS_, _RECIPIENTS_NEWLINES_, _USER_MOBILE_
     *     are always URL-encoded.
     *   - _RECIPIENTS_ARRAY_ and its international variant are URL-encoded
     *     (parameter name + values).
     *   - International placeholders (_RECIPIENTS_INTERNATIONAL_*) are ONLY
     *     substituted when BOTH localPrefix and internationalPrefix are non-empty.
     *     If either is missing, the placeholder is left as literal text in the
     *     request body — the gateway will receive the raw placeholder string.
     *
     * @param string[] $recipients Already-normalised phone numbers (digits only)
     */
    private function buildHttpRequest(
        string $message,
        array  $recipients,
        string $senderNumber,
    ): HttpRequest
    {
        $content = $this->postTemplate;

        $content = str_replace('_MESSAGE_', urlencode($message), $content);
        $content = str_replace('_RECIPIENTS_COMMAS_', urlencode(implode(',', $recipients)), $content);
        $content = str_replace('_RECIPIENTS_NEWLINES_', urlencode(implode("\n", $recipients)), $content);

        // The current user's email. 2.39 substituted it too; without one (cron,
        // CLI) fail rather than sending the literal keyword.
        if (str_contains($this->postTemplate, '_USER_EMAIL_')) {
            if ($this->userEmail === null || $this->userEmail === '') {
                throw new \RuntimeException('Your SMS config includes the _USER_EMAIL_ keyword but there is no current user!');
            }
            $content = str_replace('_USER_EMAIL_', urlencode($this->userEmail), $content);
        }

        // Array-style recipients: `param[]=a&param[]=b`, values encoded but the
        // brackets and separators literal, as 2.39 sent them.
        $recipientArrayParam = $this->recipientArrayParameter;
        if ($recipientArrayParam !== '') {
            $content = str_replace(
                '_RECIPIENTS_ARRAY_',
                self::arrayParams($recipientArrayParam, $recipients),
                $content,
            );
        }

        $content = str_replace('_USER_MOBILE_', urlencode($senderNumber), $content);

        // International number variants
        $localPrefix = $this->localPrefix;
        $internationalPrefix = $this->internationalPrefix;
        if ($localPrefix !== '' && $internationalPrefix !== '') {
            $intls = array_map(
                static fn(string $t) => (new PhoneNumber($t))->internationalise($localPrefix, $internationalPrefix)->value,
                $recipients,
            );
            $content = str_replace('_RECIPIENTS_INTERNATIONAL_COMMAS_', urlencode(implode(',', $intls)), $content);
            $content = str_replace('_RECIPIENTS_INTERNATIONAL_NEWLINES_', urlencode(implode("\n", $intls)), $content);
            if ($recipientArrayParam !== '') {
                $content = str_replace(
                    '_RECIPIENTS_INTERNATIONAL_ARRAY_',
                    self::arrayParams($recipientArrayParam, $intls),
                    $content,
                );
            }
        }

        $header = $this->headerTemplate;
        $header .= 'Content-Length: ' . \strlen($content) . "\r\n";
        $header .= "Content-Type: application/x-www-form-urlencoded\r\n";

        return new HttpRequest(
            url: $this->url,
            method: 'POST',
            headers: $header,
            body: $content,
        );
    }

    /**
     * Parse the gateway response into per-recipient results.
     *
     * Processing order (first match wins):
     *   1. Overall error regex match → every recipient FAILED, with the body
     *   2. Empty body ('' / '0' / false) → every recipient FAILED (not executed)
     *   3. Body === 'OK' → all recipients marked success (test-mode artifact;
     *      the template-based provider's mock HttpClient returns 'OK')
     *   4. No OK regex configured → all recipients marked failure with
     *      "No response OK regex configured"
     *   5. Per-recipient OK regex matching → each recipient independently
     *      checked; \r characters are stripped from the body before matching
     *      because preg_match with /m can behave oddly with CR
     *
     * Always returns exactly one delivery per recipient, in order, so
     * decorators can pair results with person IDs by position.
     *
     * @param string[] $recipients Phone numbers as raw digit strings
     */
    private function parseResponse(
        HttpResponse $response,
        array        $recipients,
    ): array
    {
        $body = $response->body;

        $responseErrorRegex = $this->responseErrorRegex;

        $allFailed = static fn(string $detail): array => array_map(
            static fn(string $dest) => new SmsDelivery(
                recipient: new PhoneNumber($dest),
                status: SmsStatus::FAILED,
                rawResponse: $body,
                statusDetail: $detail,
            ),
            $recipients,
        );

        // Check for overall error
        if ($responseErrorRegex !== '' && preg_match('/' . $responseErrorRegex . '/', $body)) {
            return $allFailed('Gateway rejected the request: ' . $body);
        }

        // Check if the request was executed (non-empty response)
        $executed = !\in_array($body, ['', '0', false], true);
        if (!$executed) {
            return $allFailed('Gateway did not execute the request (empty response)');
        }

        // In test mode, the mock response is "OK" — treat all recipients as successful
        if ($body === 'OK') {
            return array_map(
                static fn(string $dest) => new SmsDelivery(
                    recipient: new PhoneNumber($dest),
                    status: SmsStatus::SENT,
                ),
                $recipients,
            );
        }

        $responseOkRegex = $this->responseOkRegex;

        // If no OK regex configured, we can't confirm per-recipient
        if ($responseOkRegex === '') {
            return array_map(
                static fn(string $dest) => new SmsDelivery(
                    recipient: new PhoneNumber($dest),
                    status: SmsStatus::FAILED,
                ),
                $recipients,
            );
        }

        // Parse per-recipient success/failure
        $results = [];
        $bodyClean = str_replace("\r", '', $body);

        $localPrefix = $this->localPrefix;
        $internationalPrefix = $this->internationalPrefix;
        $responseIdRegex = $this->responseIdRegex;

        foreach ($recipients as $dest) {
            $reps = [
                '_RECIPIENT_INTERNATIONAL_' => (new PhoneNumber($dest))->internationalise($localPrefix, $internationalPrefix)->value,
                '_RECIPIENT_' => $dest,
            ];
            $pattern = '/' . str_replace(array_keys($reps), array_values($reps), $responseOkRegex) . '/m';
            $matched = preg_match($pattern, $bodyClean);

            $remoteId = null;
            if ($matched && $responseIdRegex !== '') {
                $idPattern = '/' . str_replace(array_keys($reps), array_values($reps), $responseIdRegex) . '/m';
                if (preg_match($idPattern, $bodyClean, $m)) {
                    $remoteId = $m[1] ?? null;
                }
            }

            $results[] = new SmsDelivery(
                recipient: new PhoneNumber($dest),
                status: $matched ? SmsStatus::SENT : SmsStatus::FAILED,
                remoteId: $remoteId,
            );
        }

        return $results;
    }
}
