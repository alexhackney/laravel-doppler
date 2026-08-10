<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Exceptions;

use AlexHackney\Doppler\Support\ExitCode;

/**
 * Doppler returned 429.
 *
 * Doppler's limit is roughly 240 requests per minute. A per-minute timer across a large
 * fleet will find it, which is why this is soft-failable: the secrets have not changed in
 * the seconds since the last successful sync.
 */
final class RateLimited extends DopplerException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function exitCode(): ExitCode
    {
        return ExitCode::RateLimited;
    }

    public function isSoftFailable(): bool
    {
        return true;
    }

    public static function make(?int $retryAfter): self
    {
        $message = 'Doppler rate limited this request (HTTP 429).';

        if ($retryAfter !== null) {
            $message .= sprintf(' Retry after %d second%s.', $retryAfter, $retryAfter === 1 ? '' : 's');
        }

        return new self($message, $retryAfter);
    }
}
