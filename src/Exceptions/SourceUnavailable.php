<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Exceptions;

use AlexHackney\Doppler\Support\ExitCode;
use Throwable;

/**
 * The secret source could not be reached: a network error, a timeout, or a 5xx.
 *
 * This is weather, not a defect. It is one of only two failures soft-fail may downgrade,
 * and only when the existing target file is present and passes validation.
 */
final class SourceUnavailable extends DopplerException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function exitCode(): ExitCode
    {
        return ExitCode::SourceUnavailable;
    }

    public function isSoftFailable(): bool
    {
        return true;
    }

    public static function network(string $driver, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Could not reach the %s secret source: %s', $driver, $reason),
            null,
            $previous,
        );
    }

    public static function serverError(int $status): self
    {
        return new self(
            sprintf('Doppler returned HTTP %d. This is an upstream problem, not a configuration one.', $status),
            $status,
        );
    }

    public static function malformedResponse(string $detail): self
    {
        return new self(
            sprintf('Doppler returned a response this package could not read: %s', $detail),
        );
    }
}
