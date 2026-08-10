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
     * The exit code an artisan command should return for this failure.
     */
    abstract public function exitCode(): ExitCode;

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
