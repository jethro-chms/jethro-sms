<?php

declare(strict_types=1);

namespace Sms\Cli;

/**
 * Abort the current CLI action with a message.
 *
 * Handlers throw this for every early exit (bad arguments, provider
 * failures, user-facing refusals).  {@see main()} catches it, turns it into
 * a {@see CliOutcome::failure()} and lets the presenter render it — so
 * errors come out as text or JSON without each handler knowing which.
 */

final class CliError extends \RuntimeException
{
    public function __construct(string $message, private readonly int $exitCode = 1)
    {
        parent::__construct($message);
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }
}
