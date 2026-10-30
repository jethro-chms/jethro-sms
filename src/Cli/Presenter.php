<?php

declare(strict_types=1);

namespace Sms\Cli;

/**
 * Renders CLI progress and results in one output format.
 *
 * Handlers narrate long-running work through {@see progress()} and return
 * their payload in a {@see CliOutcome}; the chosen presenter prints both.
 * Implementations own ALL output, so `--json` can promise that stdout holds
 * exactly one JSON document while progress goes to stderr.
 *
 * @see TextPresenter
 * @see JsonPresenter
 */

interface Presenter
{
    /**
     * Show a progress/narration line printed before the action completes
     * (e.g. "Sending SMS to 3 recipient(s)...").
     *
     * @param string $line Line including its trailing newline.
     */
    public function progress(string $line): void;

    /**
     * Print the action's final outcome.
     */
    public function render(CliOutcome $outcome): void;
}
