<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Exceptions;

use AlexHackney\Doppler\Support\ExitCode;

/**
 * A target grammar cannot express a value at all.
 *
 * The motivating case is a multi-line value under the systemd or docker targets. A systemd
 * EnvironmentFile has no multi-line form and docker --env-file performs no quote
 * processing, so emitting something that half-works is worse than refusing. Private keys
 * are the common trigger.
 *
 * Reported as a round-trip failure because that is the same class of problem from the
 * operator's point of view: the render cannot be trusted, so nothing is written.
 */
final class UnsupportedValue extends DopplerException
{
    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        string $message,
        public readonly array $keys = [],
        public readonly string $target = '',
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
    public static function multilineNotSupported(array $keys, string $target): self
    {
        return new self(
            sprintf(
                "The %s target cannot express a multi-line value: %s.\n".
                'Nothing was written. Use the laravel target, or move these values out of this config.',
                $target,
                implode(', ', $keys),
            ),
            $keys,
            $target,
        );
    }
}
