<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Validation\Rules;

use AlexHackney\Doppler\Validation\Problem;
use AlexHackney\Doppler\Validation\Rule;

/**
 * Keys that must be present AND non-blank.
 *
 * The blankness test is the whole point. Doppler renders a key it holds but has not set as
 * an empty string, not as an absent one, so a presence check passes on exactly the
 * configuration that breaks production. Every existing hand-rolled copy of this sync got
 * this right, and it is the single most valuable thing they got right.
 *
 * Each entry carries a consequence string that is printed with the failure.
 */
final class RequiredRule implements Rule
{
    /**
     * @param  array<string, string>  $required  Key to consequence description.
     */
    public function __construct(
        private readonly array $required,
    ) {}

    public function name(): string
    {
        return 'required';
    }

    /**
     * Build from config, which accepts either a list of keys or a key => consequence map.
     *
     * @param  array<int|string, string>  $config
     */
    public static function fromConfig(array $config): self
    {
        $required = [];

        foreach ($config as $key => $value) {
            if (is_int($key)) {
                $required[$value] = '';

                continue;
            }

            $required[$key] = $value;
        }

        return new self($required);
    }

    /**
     * Derive the required list from a committed .env.example.
     *
     * Keeps the contract where developers already maintain it: a key added to the example
     * in the same commit that introduces the code reading it becomes a deploy-time refusal
     * on any box whose file predates it.
     *
     * Keys present in the example with an empty value are treated as optional, since that
     * is the conventional way an example marks "set this if you need it".
     */
    public static function fromExampleFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            return new self([]);
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return new self([]);
        }

        $required = [];

        foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $key = trim($key);

            if ($key === '' || trim($value) === '') {
                continue;
            }

            $required[$key] = sprintf('it is set in %s, so something expects it', basename($path));
        }

        return new self($required);
    }

    /**
     * @param  array<string, string>  $secrets
     * @return list<Problem>
     */
    public function check(array $secrets): array
    {
        $problems = [];

        foreach ($this->required as $key => $consequence) {
            if (! array_key_exists($key, $secrets)) {
                $problems[] = new Problem(
                    key: $key,
                    message: 'is required but was not supplied at all.',
                    consequence: $consequence !== '' ? $consequence : null,
                    rule: $this->name(),
                );

                continue;
            }

            if (trim($secrets[$key]) === '') {
                $problems[] = new Problem(
                    key: $key,
                    message: 'is required but is empty. Doppler holds this key without a value set.',
                    consequence: $consequence !== '' ? $consequence : null,
                    rule: $this->name(),
                );
            }
        }

        return $problems;
    }
}
