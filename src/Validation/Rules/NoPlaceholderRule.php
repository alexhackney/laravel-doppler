<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Validation\Rules;

use AlexHackney\Doppler\Validation\Problem;
use AlexHackney\Doppler\Validation\Rule;

/**
 * Values that are obviously still the placeholder somebody meant to replace.
 *
 * Matching is exact or near-exact rather than substring, because a real secret can legally
 * contain the letters "todo" and refusing a deploy over that would be worse than the
 * problem this catches.
 */
final class NoPlaceholderRule implements Rule
{
    /**
     * Exact matches, lowercased.
     */
    private const EXACT = [
        'changeme',
        'change-me',
        'change_me',
        'todo',
        'tbd',
        'xxx',
        'xxxx',
        'yyy',
        'zzz',
        'placeholder',
        'your-key-here',
        'your_key_here',
        'your-api-key',
        'your_api_key',
        'yourkeyhere',
        'secret',
        'password',
        'null',
        'undefined',
        'none',
        'replace-me',
        'replace_me',
        'fixme',
        'example',
        'test',
        'foo',
        'bar',
    ];

    /**
     * Patterns that are unambiguous even inside a longer value.
     */
    private const PATTERNS = [
        '/^<.*>$/',
        '/^\{\{.*\}\}$/',
        '/^\[.*\]$/',
        '/^your[-_ ].*[-_ ]here$/i',
        '/^(insert|enter|add)[-_ ]/i',
    ];

    /**
     * @param  list<string>  $ignoreKeys  Keys exempt from this rule.
     */
    public function __construct(
        private readonly array $ignoreKeys = [],
    ) {}

    public function name(): string
    {
        return 'no_placeholder';
    }

    /**
     * @param  array<string, string>  $secrets
     * @return list<Problem>
     */
    public function check(array $secrets): array
    {
        $problems = [];

        foreach ($secrets as $key => $value) {
            if (in_array($key, $this->ignoreKeys, true)) {
                continue;
            }

            $trimmed = trim($value);

            if ($trimmed === '' || ! $this->looksLikePlaceholder($trimmed)) {
                continue;
            }

            $problems[] = new Problem(
                key: $key,
                message: 'still holds a placeholder value rather than a real one.',
                consequence: 'the app boots and then fails on first use, somewhere unrelated',
                rule: $this->name(),
            );
        }

        return $problems;
    }

    private function looksLikePlaceholder(string $value): bool
    {
        if (in_array(strtolower($value), self::EXACT, true)) {
            return true;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
