<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Validation;

/**
 * One thing a rule objected to.
 *
 * The consequence string is the important field and it is not decoration. A message that
 * says "INTERNAL_SERVICE_TOKEN is empty" tells an operator what happened. A message that
 * adds "the auth middleware fails closed with 503" tells them the blast radius, at the
 * moment they are looking at a red deploy and deciding whether to force it.
 *
 * Never carries a value. These end up in CI logs.
 */
final class Problem
{
    /**
     * @param  string  $key  The offending env key.
     * @param  string  $message  What is wrong, in one line, value-blind.
     * @param  string|null  $consequence  What breaks if this ships.
     * @param  string  $rule  The rule that raised it.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $message,
        public readonly ?string $consequence = null,
        public readonly string $rule = '',
    ) {}

    /**
     * One line, suitable for command output.
     */
    public function describe(): string
    {
        $line = sprintf('%s: %s', $this->key, $this->message);

        if ($this->consequence !== null && $this->consequence !== '') {
            $line .= sprintf(' Consequence: %s.', rtrim($this->consequence, '.'));
        }

        return $line;
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'message' => $this->message,
            'consequence' => $this->consequence,
            'rule' => $this->rule,
        ];
    }
}
