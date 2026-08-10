<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Validation\Rules;

use AlexHackney\Doppler\Validation\Problem;
use AlexHackney\Doppler\Validation\Rule;

/**
 * A public callback URL must not point at the box itself.
 *
 * Named in the original implementation as the most expensive misconfiguration available,
 * and it is: a third party is billed for work whose result is delivered to a host only the
 * box can reach. Nothing errors. The work is simply paid for and lost.
 */
final class NotLoopbackRule implements Rule
{
    /**
     * Hosts that resolve to the local machine.
     *
     * The IPv4 check is a range test rather than a list, because the whole 127.0.0.0/8
     * block is loopback and 127.0.0.53 in particular shows up on systemd-resolved boxes.
     */
    private const LOOPBACK_HOSTS = [
        'localhost',
        '::1',
        '[::1]',
        '0.0.0.0',
        'host.docker.internal',
    ];

    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        private readonly array $keys,
    ) {}

    public function name(): string
    {
        return 'not_loopback';
    }

    /**
     * @param  array<string, string>  $secrets
     * @return list<Problem>
     */
    public function check(array $secrets): array
    {
        $problems = [];

        foreach ($this->keys as $key) {
            $value = $secrets[$key] ?? null;

            if ($value === null || trim($value) === '') {
                continue;
            }

            $host = parse_url($value, PHP_URL_HOST);

            // Accept a bare host too, so this rule is useful on keys that are a hostname
            // rather than a URL. The absolute_url rule is what objects to the missing
            // scheme, if that is configured for this key.
            if (! is_string($host) || $host === '') {
                $host = trim($value);
            }

            if (! $this->isLoopback($host)) {
                continue;
            }

            $problems[] = new Problem(
                key: $key,
                message: sprintf('points at %s, which only this machine can reach.', $host),
                consequence: 'a third party is billed for work whose callback never arrives, and nothing errors',
                rule: $this->name(),
            );
        }

        return $problems;
    }

    private function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host));

        if (in_array($host, self::LOOPBACK_HOSTS, true)) {
            return true;
        }

        if (str_ends_with($host, '.localhost')) {
            return true;
        }

        return (bool) preg_match('/^127(?:\.\d{1,3}){3}$/', $host);
    }
}
