<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Validation\Rules;

use AlexHackney\Doppler\Validation\Problem;
use AlexHackney\Doppler\Validation\Rule;

/**
 * No stray control characters in any value.
 *
 * The usual cause is a paste into the Doppler web UI from an editor that added a trailing
 * CR, or a copied token that brought a newline with it. The failure is silent and brutal:
 * a credential with an invisible trailing \r produces a 401 that reads as a bad password
 * rather than as a bad paste, and people go looking in entirely the wrong place.
 *
 * LF is allowed by default because it is legitimate in a PEM private key, which is a
 * genuine and common secret. Everything else in the C0 range is rejected.
 */
final class NoControlCharsRule implements Rule
{
    public function __construct(
        private readonly bool $allowNewlines = true,
    ) {}

    public function name(): string
    {
        return 'no_control_chars';
    }

    /**
     * @param  array<string, string>  $secrets
     * @return list<Problem>
     */
    public function check(array $secrets): array
    {
        $problems = [];

        foreach ($secrets as $key => $value) {
            $found = $this->findControlCharacters($value);

            if ($found === []) {
                continue;
            }

            $problems[] = new Problem(
                key: $key,
                message: sprintf(
                    'contains %s. This is almost always a stray character from a paste, not part of the secret.',
                    $this->describe($found),
                ),
                consequence: 'an invisible trailing character produces a 401 that reads as a bad credential',
                rule: $this->name(),
            );
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function findControlCharacters(string $value): array
    {
        $found = [];
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $ord = ord($value[$i]);

            if ($ord > 31 && $ord !== 127) {
                continue;
            }

            // Tab is ordinary inside a value and never causes the failure this rule is
            // about, so it is not flagged.
            if ($ord === 9) {
                continue;
            }

            if ($ord === 10 && $this->allowNewlines) {
                continue;
            }

            $name = match ($ord) {
                10 => 'a newline',
                13 => 'a carriage return',
                0 => 'a null byte',
                127 => 'a delete character',
                default => sprintf('control character 0x%02X', $ord),
            };

            if (! in_array($name, $found, true)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $found
     */
    private function describe(array $found): string
    {
        if (count($found) === 1) {
            return $found[0];
        }

        $last = array_pop($found);

        return implode(', ', $found).' and '.$last;
    }
}
