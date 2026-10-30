<?php

declare(strict_types=1);

namespace Sms;

/**
 * Test-double HTTP clients for SMS provider test modes.
 *
 * When SMS_TESTMODE is enabled, the real HttpClient is replaced
 * with a provider-specific fake that returns canned responses.
 *
 * @see HttpClient
 */

/**
 * Abstract base for provider-specific fake HTTP clients used in test mode.
 *
 * Each concrete SMS provider that supports test mode should subclass this
 * and override {@see send()} to return realistic mock responses for POST
 * requests.  GET requests and unrecognised POSTs fall through to the real
 * client so that read-only operations (balance, sender IDs, delivery status)
 * continue to work in test mode.
 *
 * Subclasses:
 *  - {@see CellcastFakeHttpClient} — returns realistic JSON for Cellcast API calls
 *  - {@see TemplateFakeHttpClient} — returns 'OK' for all POSTs (template-based providers)
 *  - {@see FiveCentSmsV5FakeHttpClient} — fakes POST /senderid, refuses DELETEs (sends use the request body's "test" flag)
 */

abstract readonly class FakeHttpClient implements HttpClient
{
    public function __construct(
        protected HttpClient $inner,
    )
    {
    }

    /**
     * Short-circuit requests: provider-specific expected responses
     * first, then fall through to the real client.
     *
     * Unmatched requests fall through to the real client so that
     * read-only operations (balance, sender IDs, etc.) work in test mode.
     */
    public function send(HttpRequest $request): \Result
    {
        // Try provider-specific expected responses
        $expected = $this->matchExpected($request);
        if ($expected !== null) {
            return $expected;
        }

        // Fall through for endpoints not covered
        return $this->inner->send($request);
    }

    /**
     * Provider-specific mock responses — override in each subclass.
     *
     * Keys are "<METHOD> <URL-substring>"; values are a response body
     * string or a Closure(HttpRequest): string for dynamic responses.
     *
     * @return array<string, string|\Closure>
     */
    protected function expectedResponses(): array
    {
        return [];
    }

    /**
     * Match the request against expectedResponses().
     *
     * Each entry key is split on the first space into METHOD and a
     * URL substring.  Matching is case-insensitive str_contains.
     * If the value is a Closure, it is called with the request to
     * produce the response body.  First match wins.
     *
     * @return \Result<HttpResponse>|null null if no expected response matches
     */
    protected function matchExpected(HttpRequest $request): ?\Result
    {
        foreach ($this->expectedResponses() as $key => $body) {
            $space = strpos($key, ' ');
            if ($space === false) continue;

            $keyMethod = strtoupper(substr($key, 0, $space));
            $keyPath = strtolower(substr($key, $space + 1));

            if ($keyMethod === strtoupper($request->method)
                && $keyPath !== ''
                && str_contains(strtolower($request->url), $keyPath)
            ) {
                if ($body instanceof \Closure) {
                    $body = $body($request);
                }
                return \Result::success(new HttpResponse($body, fake: true));
            }
        }

        return null;
    }
}

/**
 * Fake HTTP client for Cellcast.
 *
 * In test mode, returns realistic JSON responses for Cellcast API endpoints
 * so that callers (registerSenderId, registerSenderNumber, send, etc.)
 * exercise the full response-parsing path without contacting the upstream API.
 * @see FakeHttpClient
 * @see CellcastSmsProvider
 */
