<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Exceptions;

use AlexHackney\Doppler\Support\ExitCode;
use RuntimeException;

/**
 * Base for every exception this package throws.
 *
 * Each subclass maps to exactly one process exit code so that a deploy script, a systemd
 * timer, or a monitor can tell an outage apart from a defect without parsing output.
 */
abstract class DopplerException extends RuntimeException
{
    /**
     * Why a failure was not downgraded, when something tried to downgrade it.
     *
     * Held separately rather than concatenated into the message, because rebuilding the
     * exception to append text meant `new static($message)` — which silently dropped
     * SourceUnavailable::$status, RateLimited::$retryAfter, and the previous-exception
     * chain that Sentry needs. Cloning keeps all three.
     */
    protected ?string $explanation = null;

    /**
     * The exit code an artisan command should return for this failure.
     */
    abstract public function exitCode(): ExitCode;

    /**
     * Attach an explanation, keeping everything else intact.
     *
     * Mutates rather than copying: PHP's Exception declares __clone private, so an
     * exception cannot be cloned at all. Mutating the caught instance immediately before
     * rethrowing it is safe — the alternative it replaced, rebuilding via
     * `new static($message)`, is what silently dropped $status, $retryAfter and $previous.
     */
    public function explain(string $explanation): static
    {
        $this->explanation = $explanation;

        return $this;
    }

    public function explanation(): ?string
    {
        return $this->explanation;
    }

    /**
     * The message an operator should see: the failure, then why it was not survivable.
     *
     * Commands print this rather than getMessage(), which carries only the first half.
     */
    public function fullMessage(): string
    {
        return $this->explanation === null
            ? $this->getMessage()
            : $this->getMessage()."\n\n".$this->explanation;
    }

    /**
     * Whether this failure may be downgraded to a successful exit when soft-fail is on
     * and the existing target file is present and valid.
     *
     * Only transient, self-resolving conditions are soft-failable. A revoked token, a
     * validation refusal, or a render that will not round-trip are all defects that get
     * worse when hidden.
     */
    public function isSoftFailable(): bool
    {
        return false;
    }
}
