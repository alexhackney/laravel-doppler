<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Validation;

/**
 * A validation rule.
 *
 * Rules see the whole map rather than one key at a time, because the most valuable rule in
 * the set (required) is about absence, which a per-key callback cannot express.
 */
interface Rule
{
    /**
     * @param  array<string, string>  $secrets
     * @return list<Problem>
     */
    public function check(array $secrets): array;

    /**
     * The rule name, used in output and to identify it in config.
     */
    public function name(): string;
}
