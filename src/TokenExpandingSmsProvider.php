<?php

declare(strict_types=1);

namespace Sms;

/**
 * Decorator that expands %tokens% in the message body before sending.
 *
 * Uses an s-expression Templater (see templater.php in this package).  Legacy
 * %word% variables are preserved for backward compatibility; %(sexpr)%
 * enables function calls and composition.
 *
 * When the message contains a known variable reference (one of the names
 * in $varNames, or an s-expression referencing one), each recipient gets
 * an individual send() call with their personalised message.  The token
 * resolver (callable(SmsRecipient): array<string, string>) maps variable
 * names to values for each recipient.
 *
 * When recipients share a number (DeduplicatingSmsProvider groups), each
 * variable's values across the group are joined " & " ("Hi Alice & Bob"),
 * with repeated values dropped: two members whose value is the same (e.g.
 * two parents sent a message about the same child) give "Alice", not
 * "Alice & Alice".
 *
 * Without known variables, sends as a single batch (no overhead). A bare
 * '%' such as "20% off" does not trigger per-recipient splitting.
 * @see DecoratingSmsProvider
 * @see Templater
 */

class TokenExpandingSmsProvider extends DecoratingSmsProvider
{
    /**
     * @param string[] $varNames Known variable names without delimiters, e.g. ['firstname','lastname','fullname']
     */
    public function __construct(
        SmsProvider      $inner,
        private \Closure $tokenResolver,
        private Templater $templater,
        private array    $varNames = [],
        private ?\Closure $shortenFn = null,
        private ?\Closure $previewShortenFn = null,
    )
    {
        parent::__construct($inner);
    }

    public function send(
        array     $entries,
        SmsSender $sender,
        ?int      $sendAt = null,
        bool      $preview = false,
    ): \Result
    {
        // Preview mode: use the injected local deterministic shortener (no API
        // call). Real send: use the injected real shortener, which stores the
        // mapping. Both closures are host-supplied (Jethro injects wrappers
        // around its URL-shortener service); standalone use has no shortener,
        // so URLs pass through unchanged. See jethro-sms docs/extraction.md §3.
        $this->templater->registerFunction(
            'shorten',
            ($preview ? $this->previewShortenFn : $this->shortenFn)
                ?? fn(string $url): string => $url,
        );

        // Walk entries, splitting any that contain known variables into
        // one entry per recipient with expanded text.  Entries without
        // variables pass through unchanged, preserving batched multi-recipient
        // HTTP calls at the raw provider.
        $expanded = [];
        foreach ($entries as $entry) {
            $message = $entry['message'];
            $recipients = $entry['recipients'];
            if (!$this->templater->hasTokens($message)) {
                $expanded[] = $entry;
                continue;
            }

            $logicalRecipients = $entry['logicalRecipients'] ?? null;
            if (is_array($logicalRecipients) && count($logicalRecipients) === count($recipients)) {
                // Person variables referenced and the entry carries delivery
                // groups (DeduplicatingSmsProvider) — expand per GROUP: token
                // values of all group members, joined " & ", one message per
                // shared number ("Hi Alice & Bob, …").  Members of a group
                // share the number, so they share the one message body.
                foreach ($recipients as $i => $recipient) {
                    $group = $logicalRecipients[$i];
                    $combined = [];
                    foreach ($group as $member) {
                        $tokens = ($this->tokenResolver)($member);
                        foreach ($this->varNames as $name) {
                            $value = $tokens[$name] ?? null;
                            if ($value !== null && $value !== '' && !in_array($value, $combined[$name] ?? [], true)) {
                                $combined[$name][] = $value;
                            }
                        }
                    }
                    $result = $this->templater->expand($message, fn(string $name): ?string =>
                        isset($combined[$name]) ? implode(' & ', $combined[$name]) : null
                    );
                    if ($result->isFailure()) {
                        if ($this->templater->hasRecognisableToken($message)) {
                            return $result;
                        }
                        // Prose that merely contains "%(": send it literally.
                        $expanded[] = $entry;
                        continue 2;
                    }
                    $expanded[] = ['message' => $result->getValue(), 'recipients' => [$recipient], 'template' => $message, 'logicalRecipients' => [$group]];
                }
            } elseif ($this->templater->referencesVariables($message, $this->varNames)) {
                // Person variables referenced — expand per recipient
                foreach ($recipients as $recipient) {
                    $tokens = ($this->tokenResolver)($recipient);
                    $result = $this->templater->expand($message, fn(string $name): ?string =>
                        $tokens[$name] ?? null
                    );
                    if ($result->isFailure()) {
                        if ($this->templater->hasRecognisableToken($message)) {
                            return $result;
                        }
                        // Prose that merely contains "%(": send it literally.
                        $expanded[] = $entry;
                        continue 2;
                    }
                    $expanded[] = ['message' => $result->getValue(), 'recipients' => [$recipient], 'template' => $message];
                }
            } else {
                // Function-only tokens (e.g. %(shorten url)%) — expand once,
                // no per-recipient data needed. Unknown %word% tokens remain
                // literal (resolver returns null for all names).  Any
                // logicalRecipients key rides along unchanged: the body is
                // identical for every member of a group.
                $result = $this->templater->expand($message, fn(string $_): ?string => null);
                if ($result->isFailure()) {
                    if ($this->templater->hasRecognisableToken($message)) {
                        return $result;
                    }
                    // Prose that merely contains "%(": send it literally.
                    $expanded[] = $entry;
                    continue;
                }
                $newEntry = ['message' => $result->getValue(), 'recipients' => $recipients, 'template' => $message];
                if (isset($entry['logicalRecipients'])) {
                    $newEntry['logicalRecipients'] = $entry['logicalRecipients'];
                }
                $expanded[] = $newEntry;
            }
        }

        return parent::send($expanded, $sender, $sendAt, $preview);
    }
}
