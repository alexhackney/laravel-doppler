<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Exceptions;

use AlexHackney\Doppler\Support\ExitCode;

/**
 * Rendered content did not survive being loaded back.
 *
 * This is the package's most valuable refusal. It converts an entire class of boot-time
 * production failure into a stop on the machine doing the rendering, and it means the
 * renderer's correctness is enforced by code rather than by a table in a document staying
 * accurate.
 *
 * Never names a value. Only keys.
 */
final class RoundTripFailed extends DopplerException
{
    /**
     * @param  list<string>  $keys  The keys that failed to round-trip. Never values.
     */
    public function __construct(
        string $message,
        public readonly array $keys = [],
    ) {
        parent::__construct($message);
    }

    public function exitCode(): ExitCode
    {
        return ExitCode::RoundTripFailed;
    }

    /**
     * @param  list<string>  $keys
     */
    public static function forKeys(array $keys): self
    {
        return new self(
            sprintf(
                "Rendered output does not round-trip: %s.\n".
                'The value contains characters this renderer cannot safely express. Nothing was written.',
                implode(', ', $keys),
            ),
            $keys,
        );
    }

    public static function unparseable(string $reason): self
    {
        return new self(
            sprintf(
                "Rendered output could not be parsed back at all: %s\n".
                'This is a renderer defect. Nothing was written.',
                $reason,
            ),
        );
    }
}
