<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Validation\Rules;

use AlexHackney\Doppler\Validation\Problem;
use AlexHackney\Doppler\Validation\Rule;

/**
 * Named keys must carry a scheme and a host.
 *
 * A bare hostname fails much later and much less clearly, from somewhere inside Guzzle or
 * the S3 SDK, as "URI must include a scheme and host". Catching it at render time turns
 * a confusing runtime error into a named key.
 */
final class AbsoluteUrlRule implements Rule
{
    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        private readonly array $keys,
    ) {}

    public function name(): string
    {
        return 'absolute_url';
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

            // Absence and blankness belong to the required rule. This one only judges
            // values that exist, so the two can be configured independently.
            if ($value === null || trim($value) === '') {
                continue;
            }

            $scheme = parse_url($value, PHP_URL_SCHEME);
            $host = parse_url($value, PHP_URL_HOST);

            if (! is_string($scheme) || ! is_string($host) || $host === '') {
                $problems[] = new Problem(
                    key: $key,
                    message: 'must be an absolute URL including a scheme and host, such as https://example.com.',
                    consequence: 'HTTP clients and the S3 SDK fail late and unclearly on a bare host',
                    rule: $this->name(),
                );

                continue;
            }

            if (! in_array(strtolower($scheme), ['http', 'https'], true)) {
                $problems[] = new Problem(
                    key: $key,
                    message: sprintf('uses the "%s" scheme, but only http and https are valid here.', $scheme),
                    consequence: 'HTTP clients will refuse the URL',
                    rule: $this->name(),
                );
            }
        }

        return $problems;
    }
}
