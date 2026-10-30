<?php

declare(strict_types=1);

namespace Sms\Providers;

use Sms\FakeHttpClient;
use Sms\HttpClient;
use Sms\HttpRequest;

final readonly class FiveCentSmsV5FakeHttpClient extends FakeHttpClient
{
    public function send(HttpRequest $request): \Result
    {

        $expected = $this->matchExpected($request);
        if ($expected !== null) return $expected;

        // Test mode must never change the live account: DELETEs (sender
        // numbers, opt-outs, cancels) have no fake here, so refuse them.
        if (strtoupper($request->method) === 'DELETE') {
            return \Result::failure(
                '5CentSMS test mode: refusing to send a real DELETE to ' . $request->url
                . ' (no fake response is defined for it)'
            );
        }

        // Everything else (POST /sms with "test":true, GETs for balance and
        // sender-id listing) goes through to the real API.
        return $this->inner->send($request);
    }

    protected function expectedResponses(): array
    {
        return [
            'POST /senderid' => json_encode([
                'error' => '',
                'message' => 'Sender ID created (test mode — not actually created)',
            ]),
        ];
    }
}
