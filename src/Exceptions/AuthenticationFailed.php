<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Exceptions;

use AlexHackney\Doppler\Support\ExitCode;

/**
 * Doppler rejected the credential (401 or 403).
 *
 * Never soft-failable. A revoked or rotated token is a provisioning problem that will not
 * fix itself, and downgrading it means every subsequent deploy runs on an ever-staler file
 * while reporting success.
 */
final class AuthenticationFailed extends DopplerException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    public function exitCode(): ExitCode
    {
        return ExitCode::AuthenticationFailed;
    }

    /**
     * @param  string  $redactedToken  Always the redacted form. Never the raw value.
     */
    public static function rejected(int $status, string $redactedToken, string $source): self
    {
        return new self(
            sprintf(
                'Doppler rejected the token (HTTP %d). Token %s, resolved from %s. '.
                'Rotate or re-provision it; retrying will not help.',
                $status,
                $redactedToken,
                $source,
            ),
            $status,
        );
    }

    public static function scopeRequired(string $tokenType): self
    {
        return new self(
            sprintf(
                'This is a %s token, so --project and --config are required. '.
                'Only service tokens (dp.st.) are pre-scoped to a single config.',
                $tokenType,
            ),
        );
    }
}
