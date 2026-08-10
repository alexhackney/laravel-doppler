<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Rendering;

use AlexHackney\Doppler\Exceptions\RoundTripFailed;
use AlexHackney\Doppler\Exceptions\UnsupportedValue;

/**
 * Turns a key/value map into file content for one target grammar.
 *
 * There is a renderer per target because the consumers disagree in ways that silently
 * corrupt values rather than failing loudly:
 *
 *   laravel  phpdotenv       quotes are processed; ${ interpolates outside single quotes
 *   systemd  EnvironmentFile no multi-line values exist at all
 *   docker   --env-file      no quote processing whatsoever; quotes become literal
 *   shell    source          needs export and POSIX single-quote escaping
 *
 * Handing a laravel-rendered file to systemd works right up until somebody puts a private
 * key in the config.
 */
interface Renderer
{
    /**
     * Render the map to file content.
     *
     * Implementations sort keys so that output is deterministic and the no-change short
     * circuit can actually fire.
     *
     * @param  array<string, string>  $secrets
     *
     * @throws UnsupportedValue When this grammar cannot express a value at all.
     */
    public function render(array $secrets): string;

    /**
     * The target name, as used by --target and in config.
     */
    public function name(): string;

    /**
     * Load rendered content back into a key/value map, the way this target's consumer will.
     *
     * This is what makes the round-trip guard meaningful: it has to model the real
     * consumer, not a convenient approximation of it.
     *
     * @return array<string, string>
     *
     * @throws RoundTripFailed When the content will not parse.
     */
    public function parse(string $content): array;
}
