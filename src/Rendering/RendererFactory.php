<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Rendering;

use InvalidArgumentException;

/**
 * Resolves a target name to its renderer.
 */
final class RendererFactory
{
    /**
     * @var array<string, class-string<Renderer>>
     */
    private const TARGETS = [
        'laravel' => LaravelRenderer::class,
        'systemd' => SystemdRenderer::class,
        'docker' => DockerRenderer::class,
        'shell' => ShellRenderer::class,
    ];

    public function make(string $target): Renderer
    {
        $class = self::TARGETS[$target] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown render target "%s". Available targets: %s.',
                $target,
                implode(', ', array_keys(self::TARGETS)),
            ));
        }

        return new $class;
    }

    /**
     * @return list<string>
     */
    public function available(): array
    {
        return array_keys(self::TARGETS);
    }
}
