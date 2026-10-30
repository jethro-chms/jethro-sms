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
 * Human-readable CLI output — the long-standing text format.
 *
 * Moved verbatim out of the handlers when presentation was split from
 * execution: the byte-for-byte output is unchanged, so scripts and muscle
 * memory keep working.  One method per action, switching on the payload
 * shape the handler returned.
 *
 * @see Presenter
 */

final class TextPresenter implements Presenter
{
    public function progress(string $line): void
    {
        echo $line;
    }

    public function render(CliOutcome $outcome): void
    {
        if (!$outcome->ok) {
            fwrite(STDERR, 'Error: ' . $outcome->error . "\n");
            return;
        }
        if ($outcome->note !== null) {
            echo $outcome->note . "\n";
        }
        if ($outcome->data === null) {
            return; // note-only outcome ("not supported", …) — nothing else to print
        }
        match ($outcome->action) {
            'sms'                    => $this->sendSummary($outcome->data),
            'senderids'              => $this->senderIds($outcome->data),
            'sendernums'             => $this->senderNumbers($outcome->data),
            'sendernums-unregistered' => $this->unregisteredSenderNumbers($outcome->data),
            'is-sendernum-registered'          => $this->isSendernumRegistered($outcome->data),
            'estimate'               => $this->estimate($outcome->data),
            'status'                 => $this->status($outcome->data),
            'balance'                => $this->balance($outcome->data),
            'info'                   => $this->info($outcome->data),
            'smsinfo', 'cancel'      => $this->deliveryStatus($outcome->data),
            'validate-sendernum' => $this->validateSendernum($outcome->data),
            'complete-sendernum-validation' => $this->completeSendernumValidation($outcome->data),
            'delete-sendernum'       => $this->deleted($outcome->data),
            'register-sender-id'     => $this->registerSenderId($outcome->data),
            'optouts'                => $this->optOuts($outcome->data),
            'remove-optout'          => $this->removed($outcome->data),
            'list'                   => $this->recentDeliveries($outcome->data),
            default                  => null, // host extra actions print for themselves
        };
    }

    /** @param array{summary: SendSummary, deliveries: SmsDelivery[]} $data */
    private function sendSummary(array $data): void
    {
        $summary = $data['summary'];
        match (true) {
            $summary instanceof \Sms\AllSent => printf(
                "Sent successfully to %d recipient(s).\n",
                count($summary->recipients),
            ),
            $summary instanceof \Sms\PartialSuccess => printf(
                "Sent to %d recipient(s), failed for %d.\n",
                count($summary->successes),
                count($summary->failures),
            ),
            default => printf("Unexpected send result type: %s\n", $summary::class),
        };
    }

    /** @param list<array{value: string, acmaApproved: ?bool, registeredAt: ?int}> $senderIds */
    private function senderIds(array $senderIds): void
    {
        if ($senderIds === []) {
            echo "No registered sender IDs found.\n";
            return;
        }
        echo "Sender IDs:\n";
        foreach ($senderIds as $senderId) {
            $status = match ($senderId['acmaApproved']) {
                true  => ' (approved)',
                false => ' (not approved — hidden from senders)',
                null  => '',
            };
            $line = '  ' . $senderId['value'] . $status;
            if ($senderId['registeredAt'] !== null) {
                $line .= '  (attempted ' . date('Y-m-d', $senderId['registeredAt']) . ')';
            }
            echo $line . "\n";
        }
    }

    /** @param PhoneNumber[] $numbers */
    private function senderNumbers(array $numbers): void
    {
        if ($numbers === []) {
            echo "No registered sender numbers found.\n";
            return;
        }
        echo "Registered sender numbers:\n";
        foreach ($numbers as $number) {
            echo '  ' . $number->value . "\n";
        }
    }

    private function balance(int $balance): void
    {
        echo 'Account balance: ' . $balance . "\n";
    }

    /** @param list<array{number: string, status: ?string, registeredAt: ?int}> $rows */
    private function unregisteredSenderNumbers(array $rows): void
    {
        if ($rows === []) {
            echo "No unregistered sender numbers found.\n";
            return;
        }
        echo "Unregistered sender numbers (" . count($rows) . "):\n";
        foreach ($rows as $row) {
            $line = '  ' . $row['number'];
            if ($row['status'] !== null) {
                $line .= '  (' . $row['status'];
                if ($row['registeredAt'] !== null) {
                    $line .= ', attempted ' . date('Y-m-d', $row['registeredAt']);
                }
                $line .= ')';
            } elseif ($row['registeredAt'] !== null) {
                $line .= '  (attempted ' . date('Y-m-d', $row['registeredAt']) . ')';
            }
            echo $line . "\n";
        }
    }

    /** @param array{number: string, approved: bool} $data */
    private function isSendernumRegistered(array $data): void
    {
        echo $data['number'] . ($data['approved']
            ? " is an approved sender number.\n"
            : " is not an approved sender number.\n");
    }

    /**
     * @param array{encoding: string, segments: int, segmentCostMillicents: int,
     *              costPerRecipientMillicents: int, nonGsmChars: list<string>} $data
     */
    private function estimate(array $data): void
    {
        echo 'Encoding:  ' . $data['encoding'] . "\n";
        echo 'Segments:  ' . $data['segments'] . "\n";
        echo 'Cost:      ' . number_format($data['costPerRecipientMillicents'] / 1000, 2) . "c per recipient\n";
        if ($data['nonGsmChars'] !== []) {
            echo 'Non-GSM:   ' . implode('', $data['nonGsmChars']) . " (forces UCS-2 encoding)\n";
        }
    }

    /** @param array{key: string, description: string, operational: bool} $data */
    private function status(array $data): void
    {
        echo 'Provider:     ' . $data['description'] . "\n";
        echo 'Key:          ' . $data['key'] . "\n";
        echo "Operational:  Yes\n";
    }

    /** @param array{provider: string, extra: array<string,string>, constants: list<array{key:string, required:string, defined:bool, value:mixed}>} $data */
    private function info(array $data): void
    {
        echo 'Provider: ' . $data['provider'] . "\n";
        foreach ($data['extra'] as $label => $value) {
            echo "$label: $value\n";
        }
        if (isset($data['key'])) {
            echo 'Key: ' . $data['key'] . "\n";
        }
        if (isset($data['capabilities'])) {
            echo 'Capabilities: ' . ($data['capabilities'] === [] ? 'none' : implode(', ', $data['capabilities'])) . "\n";
        }
        if (isset($data['segmentCostMillicents'])) {
            echo 'Segment cost: ' . number_format($data['segmentCostMillicents'] / 1000, 2) . "c per segment\n";
        }
        if (array_key_exists('deferredSendMaxDelaySeconds', $data)) {
            echo 'Max deferred send: '
                . ($data['deferredSendMaxDelaySeconds'] === null ? 'unlimited' : formatDuration($data['deferredSendMaxDelaySeconds']))
                . "\n";
        }
        echo "\n";

        $constants = $data['constants'];
        if ($constants === []) {
            echo "No constants defined by this provider.\n";
            return;
        }
        $maxLen = max(array_map('strlen', array_column($constants, 'key')));
        foreach ($constants as $c) {
            $value = $c['defined'] ? var_export($c['value'], true) : '<not defined>';
            printf("  %-{$maxLen}s  %-10s  %s\n", $c['key'], $c['required'], $value);
        }
    }

    private function deliveryStatus(SmsDelivery $delivery): void
    {
        echo "Status:     " . $delivery->statusText() . " (#" . $delivery->status()->value . ")\n";
        if ($delivery->sendTimestamp() !== null) {
            echo "Sent:       " . date('Y-m-d H:i:s', $delivery->sendTimestamp()) . "\n";
        }
        if ($delivery->deliveryTimestamp() !== null) {
            echo "Delivered:  " . date('Y-m-d H:i:s', $delivery->deliveryTimestamp()) . "\n";
        }
    }

    /** @param array{step: RegistrationStep} $data */
    private function validateSendernum(array $data): void
    {
        $step = $data['step'];
        echo $this->registrationStepText($step);

        if ($step->fields !== []) {
            echo "\nComplete verification with 'complete-sendernum-validation' providing these fields:\n";
            foreach ($step->fields as $f) {
                if ($f->type !== 'hidden') {
                    echo '  --' . $f->name . '=<value>  ' . $f->label . ($f->required ? ' (required)' : '') . "\n";
                }
            }
        }
    }

    /** @param array{step: RegistrationStep, mode: string} $data */
    private function completeSendernumValidation(array $data): void
    {
        $step = $data['step'];
        if ($step->fields !== []) {
            echo "\nRequired fields:\n";
            foreach ($step->fields as $f) {
                if ($f->type !== 'hidden') {
                    echo '  --' . $f->name . '=<value>  ' . $f->label . ($f->required ? ' (required)' : '') . "\n";
                }
            }
        } elseif ($step->message !== '' || $step->instructions !== '') {
            // Steps that need no fields usually carry the reason — e.g. an
            // already-active sender number, or a link sent to the handset.
            echo $this->registrationStepText($step);
        } else {
            echo "\nNo validation fields required.\n";
        }
    }

    /** @param array{number: string} $data */
    private function deleted(array $data): void
    {
        echo "Done. The number is no longer registered with the provider.\n";
    }

    /** @param array{step: RegistrationStep, mode: string} $data */
    private function registerSenderId(array $data): void
    {
        /** @var RegistrationStep $step */
        $step = $data['step'];
        if ($data['mode'] === 'schema') {
            echo "Sender ID registration requires the following fields:\n\n";
            foreach ($step->fields as $f) {
                $req = $f->required ? ' (required)' : ' (optional)';
                $val = $f->value !== null ? ' [default: ' . $f->value . ']' : '';
                echo "  --{$f->name}={$f->label}{$req}{$val}\n";
                if ($f->description !== null && $f->description !== '') {
                    echo '    ' . $f->description . "\n";
                }
                if ($f->type === 'select' && $f->options !== null) {
                    echo '    Options: ' . implode(', ', $f->options) . "\n";
                }
                if ($f->type === 'checkbox') {
                    echo "    Pass --{$f->name}=1 to enable\n";
                }
            }
            return;
        }
        echo $this->registrationStepText($step);
    }

    /** @param OptOutEntry[] $entries */
    private function optOuts(array $entries): void
    {
        if ($entries === []) {
            echo "No opted-out numbers found.\n";
            return;
        }
        echo "Opted-out numbers (" . count($entries) . "):\n";
        foreach ($entries as $entry) {
            $line = '  ' . $entry->number->value;
            if ($entry->name !== null) {
                $line .= '  (' . $entry->name . ')';
            }
            if ($entry->optedOutAt !== null) {
                $line .= '  opted out ' . date('Y-m-d H:i:s', $entry->optedOutAt);
            }
            echo $line . "\n";
        }
    }

    /** @param array{number: string} $data */
    private function removed(array $data): void
    {
        echo "Done.\n";
    }

    /** @param array{deliveries: SmsDelivery[], since: ?int} $data */
    private function recentDeliveries(array $data): void
    {
        $deliveries = $data['deliveries'];
        $since = $data['since'];

        if ($deliveries === []) {
            $range = $since !== null ? ' since ' . date('Y-m-d H:i:s', $since) : ' in the last 24 hours';
            echo "No deliveries found{$range}.\n";
            return;
        }
        $range = $since !== null ? ' since ' . date('Y-m-d H:i:s', $since) : ' (last 24 hours)';
        echo "Recent deliveries{$range} — " . count($deliveries) . " result(s):\n\n";
        foreach ($deliveries as $d) {
            echo '  ' . \Sms\formatDeliveryLine($d) . "\n";
        }
    }

    /**
     * Render a sender registration result (registerSenderId()/registerSenderNumber()
     * phase 2) as plain text.
     *
     * Mirrors the web renderer Jethro\Sms\renderRegistrationStepHtml(): the
     * providers return structured data (message, instructions, contact, form) and
     * each presentation layer renders it.
     */
    private function registrationStepText(RegistrationStep $step): string
    {
        $out = '';

        if ($step->message !== '') {
            $out .= $step->message . "\n";
        }

        if ($step->instructions !== '') {
            $out .= "\n" . $step->instructions . "\n";
        }

        if ($step->contact !== '') {
            $out .= '  ' . $step->contact . "\n";
        }

        if ($step->form !== []) {
            $width = 0;
            foreach ($step->form as $row) {
                $width = max($width, strlen((string) ($row['label'] ?? '')));
            }
            $out .= "\n";
            foreach ($step->form as $row) {
                $out .= '  ' . str_pad((string) ($row['label'] ?? '') . ':', $width + 2)
                    . (string) ($row['value'] ?? '') . "\n";
            }
        }

        return $out !== '' ? $out : "Done.\n";
    }
}
