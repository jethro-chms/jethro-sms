<?php

declare(strict_types=1);

namespace Sms\Cli;

use Sms\FormField;
use Sms\OptOutEntry;
use Sms\PhoneNumber;
use Sms\RegistrationStep;
use Sms\SmsDelivery;
use Sms\SendSummary;

/**
 * Machine-readable CLI output (`--json`).
 *
 * Prints exactly one JSON document on stdout:
 *
 *   {"ok":true,"action":"balance","data":{"balance":1234}}
 *   {"ok":false,"action":"balance","error":"Unable to connect …"}
 *
 * Progress narration (which {@see TextPresenter} prints to stdout) goes to
 * STDERR here, so `sms.php --json <action> | jq` works without having to
 * strip prose out of the JSON.
 * Payloads are the same provider-independent value objects the API returns —
 * no upstream JSON leaks through, so the schema is stable across providers.
 *
 * Credential constants are masked in `info` output.
 *
 * @see Presenter
 */

final class JsonPresenter implements Presenter
{
    public function progress(string $line): void
    {
        fwrite(STDERR, $line);
    }

    public function render(CliOutcome $outcome): void
    {
        $payload = ['ok' => $outcome->ok, 'action' => $outcome->action];
        if ($outcome->ok) {
            if ($outcome->note !== null) {
                $payload['note'] = $outcome->note;
            }
            $payload['data'] = $this->data($outcome);
        } else {
            $payload['error'] = $outcome->error;
        }
        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ) . "\n";
    }

    private function data(CliOutcome $outcome): mixed
    {
        $data = $outcome->data;
        if ($data === null) {
            return null; // note-only outcome ("not supported", …)
        }
        return match ($outcome->action) {
            'sms'                    => $this->send($data),
            'senderids'              => $data,
            'sendernums'             => array_map(static fn (PhoneNumber $n) => ['number' => $n->value], $data),
            'sendernums-unregistered' => $data,
            'is-sendernum-registered'          => $data,
            'estimate'               => $data,
            'status'                 => $data,
            'balance'                => ['balance' => $data],
            'info'                   => $this->info($data),
            'smsinfo', 'cancel'      => $this->delivery($data),
            'validate-sendernum' => $this->registrationStep($data['step']),
            'complete-sendernum-validation' => $this->registrationStep($data['step']),
            'delete-sendernum'       => ['number' => $data['number'], 'deleted' => true],
            'register-sender-id'     => $this->registrationStep($data['step']),
            'optouts'                => $this->optOuts($data),
            'remove-optout'          => ['number' => $data['number'], 'removed' => true],
            'list'                   => [
                'since'      => $data['since'],
                'deliveries' => array_map(fn (SmsDelivery $d) => $this->delivery($d), $data['deliveries']),
            ],
            default                  => $data,
        };
    }

    /** @param array{summary: SendSummary, deliveries: SmsDelivery[]} $data */
    private function send(array $data): array
    {
        $summary = $data['summary'];
        $summaryJson = match (true) {
            $summary instanceof \Sms\AllSent => [
                'type'       => 'all_sent',
                'recipients' => count($summary->recipients),
                'remoteId'   => $summary->remoteId,
            ],
            $summary instanceof \Sms\PartialSuccess => [
                'type'     => 'partial',
                'sent'     => count($summary->successes),
                'failed'   => count($summary->failures),
                'remoteId' => $summary->remoteId,
            ],
            $summary instanceof \Sms\Failed => [
                'type'  => 'failed',
                'error' => $summary->error,
            ],
            default => ['type' => 'unknown'],
        };

        return [
            'summary'    => $summaryJson,
            'deliveries' => array_map(fn (SmsDelivery $d) => $this->delivery($d), $data['deliveries']),
        ];
    }

    /** @param array{provider: string, extra: array<string,string>, constants: list<array{key:string, required:string, defined:bool, value:mixed}>} $data */
    private function info(array $data): array
    {
        return [
            'provider'     => $data['provider'],
            'key'          => $data['key'] ?? null,
            'extra'        => $data['extra'],
            'capabilities' => $data['capabilities'] ?? [],
            'segmentCostMillicents' => $data['segmentCostMillicents'] ?? null,
            'deferredSendMaxDelaySeconds' => $data['deferredSendMaxDelaySeconds'] ?? null,
            'constants'    => array_map(fn (array $c) => [
                'key'      => $c['key'],
                'required' => $c['required'],
                'value'    => $c['defined'] ? $this->maskConstant($c['key'], $c['value']) : null,
            ], $data['constants']),
        ];
    }

    /** @param OptOutEntry[] $entries */
    private function optOuts(array $entries): array
    {
        return array_map(fn (OptOutEntry $e) => [
            'number'     => $e->number->value,
            'name'       => $e->name,
            'optedOutAt' => $e->optedOutAt,
        ], $entries);
    }

    private function delivery(SmsDelivery $delivery): array
    {
        return [
            'recipient'         => $delivery->recipient()->value,
            'status'            => $delivery->status()->name,
            'statusText'        => $delivery->statusText(),
            'remoteId'          => $delivery->remoteId(),
            'sendTimestamp'     => $delivery->sendTimestamp(),
            'deliveryTimestamp' => $delivery->deliveryTimestamp(),
            'message'           => $delivery->message(),
            'statusDetail'      => $delivery->statusDetail(),
        ];
    }

    private function registrationStep(RegistrationStep $step): array
    {
        return [
            'message'      => $step->message,
            'instructions' => $step->instructions,
            'contact'      => $step->contact,
            'number'       => $step->number,
            'registered'   => $step->registered,
            'complete'     => $step->isComplete(),
            'form'         => $step->form,
            'fields'       => array_map(static fn (FormField $f) => [
                'name'        => $f->name,
                'label'       => $f->label,
                'type'        => $f->type,
                'required'    => $f->required,
                'value'       => $f->value,
                'options'     => $f->options,
                'description' => $f->description,
            ], $step->fields),
        ];
    }

    /**
     * Mask credential values so `--json` output is as safe to share as the
     * verbose HTTP log (which {@see \Sms\redactCredentials()} already covers).
     */
    private function maskConstant(string $name, mixed $value): mixed
    {
        return maskConstantValue($name, $value);
    }
}
