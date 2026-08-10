<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Validation\Rules;

use AlexHackney\Doppler\Validation\Problem;
use AlexHackney\Doppler\Validation\Rule;

/**
 * Refuses Doppler dynamic secrets by default.
 *
 * Dynamic secrets carry a lease TTL and expire. Rendered into a static file they work
 * perfectly, then stop working hours later with nothing having changed and no deploy to
 * correlate against, which is one of the worse things to debug.
 *
 * Detection is by Doppler's own naming convention for dynamic secret keys, since the
 * download response is a flat key/value map with no lease metadata in it.
 */
final class DynamicSecretsRule implements Rule
{
    /**
     * Doppler prefixes generated dynamic secret keys with the integration name and an
     * underscore-delimited marker.
     */
    private const PATTERNS = [
        '/^DOPPLER_DYNAMIC_/',
        '/_DYNAMIC_SECRET(_|$)/',
        '/^AWS_(ACCESS_KEY_ID|SECRET_ACCESS_KEY|SESSION_TOKEN)_DYNAMIC$/',
    ];

    public function __construct(
        private readonly bool $allowed = false,
    ) {}

    public function name(): string
    {
        return 'dynamic_secrets';
    }

    /**
     * @param  array<string, string>  $secrets
     * @return list<Problem>
     */
    public function check(array $secrets): array
    {
        if ($this->allowed) {
            return [];
        }

        $problems = [];

        foreach (array_keys($secrets) as $key) {
            if (! $this->isDynamic($key)) {
                continue;
            }

            $problems[] = new Problem(
                key: $key,
                message: 'looks like a Doppler dynamic secret, which carries a lease and expires.',
                consequence: 'the value stops working hours later with no change having been made',
                rule: $this->name(),
            );
        }

        return $problems;
    }

    private function isDynamic(string $key): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }
}
