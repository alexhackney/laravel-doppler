<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Validation;

use AlexHackney\Doppler\Validation\Rules\AbsoluteUrlRule;
use AlexHackney\Doppler\Validation\Rules\DynamicSecretsRule;
use AlexHackney\Doppler\Validation\Rules\MatchesRule;
use AlexHackney\Doppler\Validation\Rules\NoControlCharsRule;
use AlexHackney\Doppler\Validation\Rules\NoPlaceholderRule;
use AlexHackney\Doppler\Validation\Rules\NotLoopbackRule;
use AlexHackney\Doppler\Validation\Rules\RequiredRule;
use InvalidArgumentException;

/**
 * Runs every configured rule and collects the problems.
 *
 * Rules live in config rather than in class constants for one specific reason beyond
 * tidiness: the required list is what makes soft-fail safe. When the source is unreachable
 * and the existing file is kept, the validator runs against that existing file, so a key
 * added to `required` in the same commit that introduces the code reading it turns
 * "deployed against a file that predates the key" into a refusal rather than a quiet
 * failure hours later. That only works if the list is easy enough to edit that people
 * actually keep it current.
 */
final class Validator
{
    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(
        private readonly array $rules = [],
    ) {}

    /**
     * Build the standard rule set from a config array.
     *
     * @param  array<string, mixed>  $config  The `validate` section of config/doppler.php.
     * @param  string|null  $basePath  Used to locate .env.example when required is set to that.
     * @param  string  $grammar  The target grammar. Only `laravel` routes values through
     *                           env(), which retypes `null` and `empty` into nothing.
     */
    public static function fromConfig(array $config, ?string $basePath = null, string $grammar = 'laravel'): self
    {
        $rules = [];

        $required = $config['required'] ?? [];

        if ($required === 'env.example') {
            $rules[] = RequiredRule::fromExampleFile(rtrim($basePath ?? '', '/').'/.env.example')
                ->forGrammar($grammar);
        } elseif (is_array($required) && $required !== []) {
            $rules[] = RequiredRule::fromConfig($required)->forGrammar($grammar);
        }

        $absoluteUrl = $config['absolute_url'] ?? [];

        if (is_array($absoluteUrl) && $absoluteUrl !== []) {
            $rules[] = new AbsoluteUrlRule(array_values($absoluteUrl));
        }

        $notLoopback = $config['not_loopback'] ?? [];

        if (is_array($notLoopback) && $notLoopback !== []) {
            $rules[] = new NotLoopbackRule(array_values($notLoopback));
        }

        if (($config['no_control_chars'] ?? true) !== false) {
            $rules[] = new NoControlCharsRule;
        }

        if (($config['no_placeholder'] ?? true) !== false) {
            $ignorePlaceholders = $config['no_placeholder_ignore'] ?? [];

            $rules[] = new NoPlaceholderRule(
                is_array($ignorePlaceholders) ? array_values($ignorePlaceholders) : [],
            );
        }

        $matches = $config['matches'] ?? [];

        if (is_array($matches) && $matches !== []) {
            $rules[] = new MatchesRule($matches);
        }

        $rules[] = new DynamicSecretsRule((bool) ($config['allow_dynamic_secrets'] ?? false));

        foreach (($config['rules'] ?? []) as $custom) {
            $rules[] = self::instantiateCustomRule($custom);
        }

        return new self($rules);
    }

    /**
     * @param  mixed  $custom
     */
    private static function instantiateCustomRule($custom): Rule
    {
        if ($custom instanceof Rule) {
            return $custom;
        }

        if (! is_string($custom) || ! class_exists($custom)) {
            throw new InvalidArgumentException(
                'Each entry in doppler.validate.rules must be a class name implementing '.Rule::class.'.',
            );
        }

        $instance = new $custom;

        if (! $instance instanceof Rule) {
            throw new InvalidArgumentException(sprintf('%s does not implement %s.', $custom, Rule::class));
        }

        return $instance;
    }

    /**
     * @param  array<string, string>  $secrets
     * @return list<Problem>
     */
    public function validate(array $secrets): array
    {
        $problems = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->check($secrets) as $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * @return list<Rule>
     */
    public function rules(): array
    {
        return $this->rules;
    }
}
