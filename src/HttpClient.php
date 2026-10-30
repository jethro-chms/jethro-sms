<?php

declare(strict_types=1);

namespace Sms;

/**
 * HTTP client abstraction for SMS provider API calls.
 *
 * @see FakeHttpClient
 */

/**
 * HTTP client interface for SMS providers.
 *
 * The single-method design means mocks are trivial — an anonymous class
 * with one method.  This is the seam that enables all unit testing of
 * provider logic without real HTTP calls.
 *
 * Returns \Result, not HttpResponse, so mocks can inject connection
 * failures as well as successful responses.
 * @see HttpRequest
 * @see HttpResponse
 * @see NativeHttpClient
 * @see FakeHttpClient
 */

interface HttpClient
{
    /** @return \Result<HttpResponse, string> */
    public function send(HttpRequest $request): \Result;
}

/**
 * @see HttpClient
 * @see HttpResponse
 */

final readonly class HttpRequest
{
    public function __construct(
        public string $url,
        public string $method,
        public string $headers,
        public string $body,
        public int    $timeout = 30,
    )
    {
    }
}

/**
 * @see HttpClient
 * @see HttpRequest
 */

final readonly class HttpResponse
{
    public function __construct(
        public string $body,
        public bool   $fake = false,
    )
    {
    }
}

/**
 * Default HTTP client using PHP stream contexts.
 *
 * The class is `readonly` for immutability, but stream operations are
 * inherently impure.  The public send() delegates to a private doSend()
 * to keep the class signature clean while containing the impure logic.
 *
 * Error handling: PHP warnings during fopen() are converted to exceptions
 * via set_error_handler(), then caught and wrapped as Result::failure().
 * The handler is always restored in the finally block.
 * @see HttpClient
 */

final readonly class NativeHttpClient implements HttpClient
{
    private const MAX_IDENTICAL_REQUESTS = 10;

    private static function userAgent(): string
    {
        return 'JethroPMM (SMS)';
    }

    public function send(HttpRequest $request): \Result
    {
        // Prepend User-Agent — providers don't set one and raw curl/PHP
        // stream defaults are unfriendly to upstream gateways.
        $request = new HttpRequest(
            url: $request->url,
            method: $request->method,
            headers: "User-Agent: " . self::userAgent() . "\r\n" . $request->headers,
            body: $request->body,
            timeout: $request->timeout,
        );

        // Loop detection: track identical requests via a file-scoped counter.
        // Using $GLOBALS instead of a static property because final readonly
        // class restrictions prevent static property defaults in PHP 8.4.
        $key = $request->method . '|' . $request->url . '|' . md5($request->body);
        if (!isset($GLOBALS['__native_http_counts'])) {
            $GLOBALS['__native_http_counts'] = [];
        }
        $GLOBALS['__native_http_counts'][$key] = ($GLOBALS['__native_http_counts'][$key] ?? 0) + 1;
        if ($GLOBALS['__native_http_counts'][$key] > self::MAX_IDENTICAL_REQUESTS) {
            return \Result::failure(
                'Loop detected: identical request sent ' . $GLOBALS['__native_http_counts'][$key]
                . ' times (' . $request->method . ' ' . $request->url . ')'
            );
        }
        return $this->doSend($request);
    }

    private function doSend(HttpRequest $request): \Result
    {
        // PHP's HTTP stream wrapper throws on 4xx/5xx and drops the body
        // for GET requests.  Use curl for all requests when available.
        if (\function_exists('curl_init')) {
            return $this->doSendCurl($request);
        }

        $opts = [
            'http' => [
                'method' => $request->method,
                'content' => $request->body,
                'header' => $request->headers,
                'timeout' => $request->timeout,
            ],
        ];

        $context = stream_context_create($opts);

        set_error_handler(static function ($errno, $errstr) {
            if (0 === error_reporting()) {
                return false;
            }
            throw new \ErrorException($errstr, 0, $errno);
        });

        try {
            $fp = fopen($request->url, 'r', false, $context);
            if (!$fp) {
                $header_text = '';
                if (function_exists('http_get_last_response_headers')) {
                    $headers = http_get_last_response_headers();
                    if (is_array($headers)) {
                        $header_text = implode('; ', $headers);
                    }
                } else {
                    $varname = 'http_response_header';
                    $header_text = is_array($$varname) ? implode('; ', $$varname) : '';
                }
                $error = 'Unable to connect to SMS Server: ' . $header_text;

                return \Result::failure($error);
            }
            $response = stream_get_contents($fp);
            fclose($fp);
        } catch (\Exception $e) {
            return \Result::failure('Unable to connect to SMS Server: ' . $e->getMessage());
        } finally {
            restore_error_handler();
        }

        return \Result::success(new HttpResponse($response));
    }

    private function doSendCurl(HttpRequest $request): \Result
    {
        $ch = curl_init($request->url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => explode("\r\n", trim($request->headers)),
            CURLOPT_POSTFIELDS => $request->body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $request->timeout,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);

        if ($response === false && $error !== '') {
            return \Result::failure('Unable to connect to SMS Server: ' . $error);
        }
        // A 5xx body is a server error page, not an API response; 4xx bodies are
        // left to the providers, whose APIs return JSON error details with them.
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($httpCode >= 500) {
            return \Result::failure("SMS server error (HTTP {$httpCode})");
        }

        return \Result::success(new HttpResponse($response === false ? '' : $response));
    }
}

/**
 * HttpClient decorator that logs every request/response pair via error_log().
 *
 * Providers that set verbose=true construct this wrapper around NativeHttpClient
 * so that ALL HTTP operations (send, getBalance, getSenderIds, getSmsDelivery)
 * are logged automatically — no per-call-site verbose guards needed.
 * @see HttpClient
 */

final readonly class LoggingHttpClient implements HttpClient
{
    public function __construct(
        private HttpClient $inner,
    )
    {
    }

    public function send(HttpRequest $request): \Result
    {
        $result = $this->inner->send($request);

        $isFake = $result->isSuccess() && $result->getValue()->fake;
        $banner = $isFake ? "[TEST MODE — request not actually sent]\n" : '';
        $prefix = $isFake ? "Fake " : "";
        $msg = $banner . "=== {$prefix}SMS HTTP Request ===\n";
        $msg .= "{$request->method} " . redactCredentials($request->url) . "\n";
        $msg .= redactCredentials($request->headers) . "\n";
        $msg .= redactCredentials($request->body) . "\n";
        if ($result->isSuccess()) {
            $msg .= "=== {$prefix}SMS HTTP Response ===\n";
            $msg .= "{$result->getValue()->body}\n";
        } else {
            $msg .= "=== {$prefix}SMS HTTP Error ===\n";
            $msg .= "{$result->getError()}\n";
        }
        $msg .= "=========================";
        error_log($msg);

        return $result;
    }
}

/**
 * Replace credential values with '****' so verbose request logs can be
 * shared. Covers every way providers send credentials: an Authorization
 * header (Cellcast bearer token), other headers such as `Api-Key:` (template
 * providers' SMS_HTTP_HEADER_TEMPLATE), JSON fields (5CentSMS
 * key-id/key-secret), and form/query parameters (template providers'
 * password, apikey, token, …).
 * A field is treated as a credential when its
 * name contains key, secret, pass or token.
 *
 * Unit tests: jethro-sms/tests/values/test_redact_credentials.php
 */
function redactCredentials(string $text): string
{
    $name = '[\w-]*(?:key|secret|pass|token)[\w-]*';
    return preg_replace(
        [
            '/^(Authorization:\s*(?:\w+\s+)?)\S+/mi',
            '/^(' . $name . ':[ \t]*)[^\r\n]+/mi',
            '/("' . $name . '"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i',
            '/((?:^|[?&\s])' . $name . '=)[^&\s]*/i',
        ],
        ['$1****', '$1****', '$1"****"', '$1****'],
        $text,
    ) ?? $text;
}
