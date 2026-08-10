<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Exceptions;

use AlexHackney\Doppler\Support\ExitCode;

/**
 * No usable token was found in any configured location.
 *
 * The message lists every location that was tried, in order, because the common cause is
 * a half-finished provisioning step rather than a missing credential.
 */
final class TokenNotFound extends DopplerException
{
    /**
     * @param  list<string>  $attempted  Human-readable descriptions of each location tried.
     */
    public function __construct(
        string $message,
        public readonly array $attempted = [],
    ) {
        parent::__construct($message);
    }

    public function exitCode(): ExitCode
    {
        return ExitCode::NoToken;
    }

    /**
     * @param  list<string>  $attempted
     */
    public static function afterSearching(array $attempted): self
    {
        $message = "No Doppler token could be resolved.\n\nTried, in order:\n";

        foreach ($attempted as $location) {
            $message .= '  - '.$location."\n";
        }

        $message .= "\nAn empty or whitespace-only file is treated as absent, not as a token.";

        return new self($message, $attempted);
    }
}
