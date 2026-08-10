<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Exceptions;

use AlexHackney\Doppler\Support\ExitCode;

/**
 * A post-sync hook failed.
 *
 * The file has already been written when this is thrown, which is why it carries its own
 * exit code rather than being folded into a generic failure. The operator needs to know
 * the secrets are current but the running processes may not be.
 *
 * A failing config:cache is the dangerous case and aborts every subsequent hook: a cache
 * built from a bad .env bakes in blank secrets while the configuration still looks
 * correct.
 */
final class HookFailed extends DopplerException
{
    public function __construct(
        string $message,
        public readonly string $hook = '',
        public readonly ?int $hookExitCode = null,
        public readonly bool $abortedRemaining = false,
    ) {
        parent::__construct($message);
    }

    public function exitCode(): ExitCode
    {
        return ExitCode::HookFailed;
    }

    public static function make(string $hook, ?int $hookExitCode, bool $abortedRemaining): self
    {
        $message = sprintf('Post-sync hook failed: %s', $hook);

        if ($hookExitCode !== null) {
            $message .= sprintf(' (exit code %d)', $hookExitCode);
        }

        $message .= '. The file was written; the running processes may still hold old values.';

        if ($abortedRemaining) {
            $message .= "\nRemaining hooks were skipped because config:cache failed. ".
                'A cache built from a bad file bakes in blank secrets while the configuration still looks correct.';
        }

        return new self($message, $hook, $hookExitCode, $abortedRemaining);
    }
}
