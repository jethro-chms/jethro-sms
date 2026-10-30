<?php

declare(strict_types=1);

namespace Sms\Providers;

use Sms\FakeHttpClient;
use Sms\HttpClient;
use Sms\HttpRequest;

final readonly class MobileMessageFakeHttpClient extends FakeHttpClient
{
    public function send(HttpRequest $request): \Result
    {
        $expected = $this->matchExpected($request);
        if ($expected !== null) return $expected;

        // Test mode must never reach the real API to change anything: a POST or
        // DELETE without a fake here (e.g. registering a sender number, which
        // texts a real handset) fails instead of falling through.
        if (strtoupper($request->method) !== 'GET') {
            return \Result::failure(
                'MobileMessage test mode: refusing to send a real ' . strtoupper($request->method) . ' to ' . $request->url
                . ' (no fake response is defined for it)'
            );
        }

        return $this->inner->send($request);
    }

    protected function expectedResponses(): array
    {
        return [
            // Send (and cancel share the /v1/messages path; match on method).
            'POST /v1/messages' => function (HttpRequest $request): string {
                $body = json_decode($request->body, true) ?: [];
                $scheduledFor = (string) ($body['messages'][0]['scheduled_for'] ?? '');
                return json_encode([
                    'status' => 'complete',
                    'total_cost' => count($body['messages'] ?? []),
                    'testMode' => true,
                    'results' => array_map(
                        fn(array $m) => [
                            'status' => 'success',
                            'cost' => 1,
                            'message_id' => 'fake_' . bin2hex(random_bytes(16)),
                            // testMode marks each result so MobileMessageSmsDelivery
                            // records TEST_MESSAGE (final): fake IDs are unknown
                            // upstream, so SENT would never resolve and Cancel
                            // would linger.
                            'testMode' => true,
                            'scheduled_for' => $scheduledFor !== '' ? $scheduledFor : null,
                            'encoding' => 'gsm7',
                        ],
                        $body['messages'] ?? [],
                    ),
                ]);
            },
            // Shape recorded from the live API; cancel takes a JSON body.
            'DELETE /v1/messages' => function (HttpRequest $request): string {
                $body = json_decode($request->body, true) ?: [];
                return json_encode([
                    'status' => 'complete',
                    'message_id' => $body['message_id'] ?? null,
                    'cancelled' => true,
                    'credits_refunded' => 1,
                    'testMode' => true,
                ]);
            },
            // Registering a sender number texts a verification link to the
            // handset, so test mode must never do it. Shape as the mock server
            // returns (the live success envelope isn't recordable).
            'POST /v1/senders' => function (HttpRequest $request): string {
                $body = json_decode($request->body, true) ?: [];
                return json_encode([
                    'status' => 'complete',
                    'sender' => (string) ($body['number'] ?? ''),
                    'testMode' => true,
                ]);
            },
            // Recorded live: `removed` is false when the number wasn't listed.
            'DELETE /v1/unsubscribes' => function (HttpRequest $request): string {
                $body = json_decode($request->body, true) ?: [];
                return json_encode([
                    'status' => 'complete',
                    'number' => (string) ($body['number'] ?? ''),
                    'removed' => false,
                    'testMode' => true,
                ]);
            },
        ];
    }
}
