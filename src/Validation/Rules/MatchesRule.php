<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Validation\Rules;

use AlexHackney\Doppler\Validation\Problem;
use AlexHackney\Doppler\Validation\Rule;

/**
 * Values with a known shape must match their pattern.
 *
 * The motivating cases are a Stripe key that must start sk_live_ in production, an APP_KEY
 * that must be base64:, and a UUID-shaped tenant identifier. All three fail late and
 * confusingly when wrong.
 */
final class MatchesRule implements Rule
{
    /**
     * @param  array<string, string>  $patterns  Key to PCRE pattern.
     */
    public function __construct(
        private readonly array $patterns,
    ) {}

    public function name(): string
    {
        return 'matches';
    }

    /**
     * @param  array<string, string>  $secrets
     * @return list<Problem>
     */
    public function check(array $secrets): array
    {
        $problems = [];

        foreach ($this->patterns as $key => $pattern) {
            $value = $secrets[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            // A malformed pattern is a configuration defect, and it must not be reported
            // as "the secret is wrong". The @ operator is not enough here: preg_match
            // raises its complaint through the error handler, so a bad pattern would still
            // print a raw PHP warning into the middle of a deploy log. Swallowing it
            // locally turns that into the explained problem below.
            $result = $this->matchQuietly($pattern, $value);

            if ($result === false) {
                $problems[] = new Problem(
                    key: $key,
                    message: sprintf('could not be checked: "%s" is not a valid regular expression.', $pattern),
                    consequence: 'this is a mistake in config/doppler.php, not in the secret',
                    rule: $this->name(),
                );

                continue;
            }

            if ($result === 1) {
                continue;
            }

            $problems[] = new Problem(
                key: $key,
                message: sprintf('does not match the expected shape %s.', $pattern),
                consequence: 'a value of the wrong shape usually fails at first use rather than at boot',
                rule: $this->name(),
            );
        }

        return $problems;
    }

    /**
     * Run preg_match without letting a malformed pattern raise a warning.
     *
     * @return int|false False when the pattern itself is invalid.
     */
    private function matchQuietly(string $pattern, string $value): int|false
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, $value);
        } finally {
            restore_error_handler();
        }
    }
}
