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
 * "Blank" is grammar-dependent, which is the subtle part. Under the laravel grammar the
 * value passes through `env()`, and `Illuminate\Support\Env::getOption()` converts four
 * literals to something blank: `null` and `(null)` become PHP null, `empty` and `(empty)`
 * become "". So `API_KEY=null` satisfies a naive `trim() !== ''` while reaching the
 * application as exactly the nothing this rule exists to prevent.
 *
 * Note the conversion is Laravel's, NOT phpdotenv's: `Dotenv::parse()` returns the literal
 * string. That is why the round-trip guard is indifferent to these values and why this rule
 * cannot be. Under systemd, docker and shell nothing converts them, so there they are
 * ordinary four-character strings and are left alone.
 *
 * Each entry carries a consequence string that is printed with the failure.
 */
final class RequiredRule implements Rule
{
    /**
     * Values Laravel's env() resolves to null or "".
     *
     * Compared lowercased, because Env::getOption() lowercases before matching.
     */
    private const ENV_BLANK_LITERALS = ['null', '(null)', 'empty', '(empty)'];

    /**
     * @param  array<string, string>  $required  Key to consequence description.
     * @param  bool  $envTypedLiterals  Whether values reach the app through Laravel's env().
     * @param  string|null  $unreadableExample  Set when 'env.example' was configured and the
     *                                          file could not be read, so the no-op is
     *                                          reported rather than silently accepted.
     */
    public function __construct(
        private readonly array $required,
        private readonly bool $envTypedLiterals = false,
        private readonly ?string $unreadableExample = null,
    ) {}

    /**
     * Return a copy that knows whether env() will retype its values.
     */
    public function forGrammar(string $grammar): self
    {
        return new self($this->required, $grammar === 'laravel', $this->unreadableExample);
    }

    public function name(): string
    {
        return 'required';
    }

    /**
     * Whether env() would turn this literal into null or "".
     *
     * The value itself is safe to print: it is one of four fixed literals, never a secret.
     */
    private function resolvesToNothing(string $value): bool
    {
        return $this->envTypedLiterals
            && in_array(strtolower($value), self::ENV_BLANK_LITERALS, true);
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
            return new self([], false, $path);
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return new self([], false, $path);
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

        // A rule configured to derive its contract from a file it cannot read enforces
        // nothing at all, and does it while reporting a clean validation pass. That is the
        // failure mode this package refuses everywhere else, so it is a problem, not a
        // shrug. It fires on a box where .env.example was never deployed, which is exactly
        // where the required contract was most load-bearing.
        if ($this->unreadableExample !== null) {
            $problems[] = new Problem(
                key: basename($this->unreadableExample),
                message: sprintf(
                    'validate.required is set to \'env.example\', but %s is missing or unreadable, '.
                    'so no key is being checked at all.',
                    $this->unreadableExample,
                ),
                consequence: 'every required-key contract is silently unenforced on this box',
                rule: $this->name(),
            );
        }

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

            $value = trim($secrets[$key]);

            if ($value === '') {
                $problems[] = new Problem(
                    key: $key,
                    message: 'is required but is empty. Doppler holds this key without a value set.',
                    consequence: $consequence !== '' ? $consequence : null,
                    rule: $this->name(),
                );

                continue;
            }

            if ($this->resolvesToNothing($value)) {
                $problems[] = new Problem(
                    key: $key,
                    message: sprintf(
                        'is required, but the value "%s" is one Laravel\'s env() resolves to %s, '.
                        'so the application receives nothing.',
                        $value,
                        str_starts_with(strtolower($value), 'e') ? 'an empty string' : 'null',
                    ),
                    consequence: $consequence !== '' ? $consequence : null,
                    rule: $this->name(),
                );
            }
        }

        return $problems;
    }
}
