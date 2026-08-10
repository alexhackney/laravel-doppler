<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Hooks;

/**
 * The outcome of one hook.
 */
final class HookResult
{
    public function __construct(
        public readonly string $hook,
        public readonly bool $successful,
        public readonly ?int $exitCode = null,
        public readonly string $output = '',
    ) {}
}
