<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Support;

/**
 * Process exit codes.
 *
 * These are a public contract. A deploy script, a systemd timer, and a monitor all branch
 * on them, so the integer values must stay stable across minor versions.
 */
enum ExitCode: int
{
    /** Wrote successfully, content was already identical, or kept a valid existing file under soft-fail. */
    case Success = 0;

    /** Validation refused the write. Somebody introduced a real problem. */
    case ValidationFailed = 1;

    /** No token could be resolved from any configured location. */
    case NoToken = 2;

    /** The secret source could not be reached, and there was no usable fallback. */
    case SourceUnavailable = 3;

    /** Authentication failed (401 or 403). Rotate the token; retrying will not help. */
    case AuthenticationFailed = 4;

    /** Rate limited (429). The retry-after value is printed when the API supplies one. */
    case RateLimited = 5;

    /** The rendered content did not survive a parse back to the source values. */
    case RoundTripFailed = 6;

    /** The file was written, but a post-sync hook failed. */
    case HookFailed = 7;

    /**
     * Doppler answered and rejected the request: a bad project or config name, a malformed
     * request. Distinct from 4, which means the credential itself was refused.
     */
    case RequestRejected = 8;

    /** Drift detected. Only ever returned by env:diff and by --dry-run. */
    case DriftDetected = 10;

    /**
     * Whether this code represents a condition that soft-fail may downgrade to success.
     *
     * Only an unreachable source and a rate limit qualify. See plan section 4.10.
     */
    public function isSoftFailable(): bool
    {
        return match ($this) {
            self::SourceUnavailable, self::RateLimited => true,
            default => false,
        };
    }

    /**
     * A short human label, used in command output and log context.
     */
    public function label(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::ValidationFailed => 'validation failed',
            self::NoToken => 'no token resolved',
            self::SourceUnavailable => 'source unavailable',
            self::AuthenticationFailed => 'authentication failed',
            self::RateLimited => 'rate limited',
            self::RoundTripFailed => 'round-trip verification failed',
            self::HookFailed => 'post-sync hook failed',
            self::RequestRejected => 'request rejected',
            self::DriftDetected => 'drift detected',
        };
    }
}
