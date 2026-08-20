<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Exceptions;

use AlexHackney\Doppler\Support\ExitCode;

/**
 * Doppler answered and said no, for a reason that is not the credential.
 *
 * A 404 on a project or config name, a 422 on a malformed request. These used to be
 * reported as AuthenticationFailed, which carries "rotate the token; retrying will not
 * help" — advice that sends an operator to the wrong place for a typo'd config name, and
 * tells a monitor branching on exit codes that a credential is bad when it is fine.
 *
 * Never soft-failable, for the same reason an auth failure is not: Doppler is reachable and
 * has given a definite answer, so this will not resolve on its own.
 */
final class RequestRejected extends DopplerException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    public function exitCode(): ExitCode
    {
        return ExitCode::RequestRejected;
    }

    public static function make(int $status, ?string $detail = null): self
    {
        return new self(
            sprintf(
                'Doppler rejected the request (HTTP %d). %s',
                $status,
                $detail ?? 'Check the project and config names. The token itself was accepted.',
            ),
            $status,
        );
    }
}
