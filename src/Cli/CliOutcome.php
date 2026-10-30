<?php

declare(strict_types=1);

namespace Sms\Cli;

/**
 * The result of one CLI action, independent of how it is presented.
 *
 * Handlers do the work and return one of these; {@see Presenter} decides
 * whether it reads as text or as JSON.  This is what keeps `--json` from
 * turning into per-handler output branches.
 *
 * `note` carries informational text that is not the action's payload — e.g.
 * "this provider does not support that".  Text prints it; JSON emits it as
 * the envelope's `note` field (and still exits 0, since it is not an error).
 *
 * @see TextPresenter
 * @see JsonPresenter
 */

final readonly class CliOutcome
{
    public function __construct(
        public bool $ok,
        public string $action,
        public mixed $data = null,
        public ?string $error = null,
        public ?string $note = null,
        public int $exitCode = 0,
    ) {
    }

    public static function success(string $action, mixed $data = null, ?string $note = null): self
    {
        return new self(ok: true, action: $action, data: $data, note: $note);
    }

    public static function failure(string $action, string $error, int $exitCode = 1): self
    {
        return new self(ok: false, action: $action, error: $error, exitCode: $exitCode);
    }
}
