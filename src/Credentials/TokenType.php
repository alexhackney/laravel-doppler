<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Credentials;

/**
 * Doppler token types, distinguished by prefix.
 *
 * Only service tokens are pre-scoped to a single config. Personal and service-account
 * tokens address the whole workspace and therefore need an explicit project and config,
 * which is worth detecting up front so the failure reads as "you need --project" rather
 * than as a Doppler error about a missing config.
 */
enum TokenType: string
{
    case Service = 'service';
    case Personal = 'personal';
    case ServiceAccount = 'service-account';
    case Unknown = 'unknown';

    /**
     * Classify a raw token by its prefix.
     */
    public static function fromToken(string $token): self
    {
        return match (true) {
            str_starts_with($token, 'dp.st.') => self::Service,
            str_starts_with($token, 'dp.pt.') => self::Personal,
            str_starts_with($token, 'dp.sa.') => self::ServiceAccount,
            default => self::Unknown,
        };
    }

    /**
     * Whether this token type requires an explicit project and config to address secrets.
     *
     * Unknown is treated as not requiring scope: an unrecognised prefix may be a future
     * token format, and failing on it here would be a worse guess than letting Doppler
     * answer for itself.
     */
    public function requiresScope(): bool
    {
        return match ($this) {
            self::Personal, self::ServiceAccount => true,
            self::Service, self::Unknown => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Service => 'service (dp.st.)',
            self::Personal => 'personal (dp.pt.)',
            self::ServiceAccount => 'service account (dp.sa.)',
            self::Unknown => 'unrecognised prefix',
        };
    }
}
