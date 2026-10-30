<?php

declare(strict_types=1);

namespace Sms\Providers;

use Sms\FakeHttpClient;
use Sms\HttpClient;
use Sms\HttpRequest;

final readonly class CellcastFakeHttpClient extends FakeHttpClient
{
    public function send(HttpRequest $request): \Result
    {

        $expected = $this->matchExpected($request);
        if ($expected !== null) return $expected;

        return $this->inner->send($request);
    }

    protected function expectedResponses(): array
    {
        return [
            'POST /business/add' => json_encode([
                'status' => true,
                'message' => 'Business added successfully (test mode — not actually created)',
                'data' => [],
                'testMode' => true,
            ]),
            'POST /customNumber/add' => json_encode([
                'status' => true,
                'message' => 'Custom number created (test mode — not actually created)',
                'testMode' => true,
            ]),
            'POST /customNumber/verifyCustomNumber' => json_encode([
                'status' => true,
                'message' => 'Number verified successfully (test mode — not actually verified)',
                'testMode' => true,
            ]),
            'POST /gateway' => function (HttpRequest $request): string {
                $body = json_decode($request->body, true) ?: [];
                $contacts = $body['contacts'] ?? [];
                $isScheduled = !empty($body['scheduleAt']);
                $status = $isScheduled ? 'queued' : null;
                return json_encode([
                    'status' => true,
                    'message' => $isScheduled
                        ? 'Message scheduled successfully (test mode — request not actually sent)'
                        : 'SMS Sent Successfully (test mode — request not actually sent)',
                    'testMode' => true,
                    // testMode marks each item so CellcastSmsDelivery records it as
                    // TEST_MESSAGE (final): fake IDs are unknown upstream, so a
                    // SCHEDULED/SENT status would never resolve and Cancel would linger.
                    'data' => ['queueResponse' => array_map(
                        fn($c) => array_filter([
                            'Number' => $c,
                            'MessageId' => 'fake_' . bin2hex(random_bytes(8)),
                            'testMode' => true,
                            'jobInfo' => $isScheduled ? ['data' => ['messageData' => ['status' => 'queued']]] : null,
                        ], fn($v) => $v !== null),
                        $contacts,
                    )],
                ]);
            },
        ];
    }
}

/**
 * Fake HTTP client for FiveCent SMS v5.
 *
 * For send() (POST /sms), the v5 provider already embeds "test": true in
 * the JSON body so the upstream API handles dry-run semantics.  This client
 * lets those requests through to the real API.
 *
 * For side-effectful non-send operations (POST /senderid —
 * registerSenderNumber / registerSenderId), the v5 provider does NOT embed
 * a test flag, so we return a mock response here to prevent accidental
 * real registrations.
 * @see FakeHttpClient
 * @see FiveCentSmsV5Provider
 */
