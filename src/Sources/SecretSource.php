<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Sources;

use AlexHackney\Doppler\Credentials\Credential;
use AlexHackney\Doppler\Exceptions\AuthenticationFailed;
use AlexHackney\Doppler\Exceptions\RateLimited;
use AlexHackney\Doppler\Exceptions\SourceUnavailable;

/**
 * Fetches secrets from somewhere.
 *
 * The three exception types are deliberately distinct because they need different operator
 * responses, and collapsing them into one is what forces people to write `|| true` in a
 * deploy script, which then swallows the failures that must not be swallowed:
 *
 *   SourceUnavailable     retry, or carry on with what you have
 *   RateLimited           back off, then retry
 *   AuthenticationFailed  rotate the credential; retrying will never help
 */
interface SecretSource
{
    /**
     * Fetch the config's secrets.
     *
     * @return array<string, string> Key to raw value, exactly as stored upstream.
     *
     * @throws SourceUnavailable Network failure, timeout, 5xx, or an unreadable response.
     * @throws RateLimited HTTP 429.
     * @throws AuthenticationFailed HTTP 401 or 403, or a credential that cannot be scoped.
     */
    public function fetch(Credential $credential): array;

    /**
     * The driver name, as used by --driver and in config.
     */
    public function name(): string;
}
