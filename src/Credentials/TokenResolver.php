<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Credentials;

use AlexHackney\Doppler\Exceptions\TokenNotFound;

/**
 * Finds a Doppler token, in a fixed order, and records where it came from.
 *
 * The order matters and each position earns its place:
 *
 *   1. --token          explicit, but visible in ps and in shell history, so it warns
 *   2. --token-file     explicit path; when given, no other location is consulted
 *   3. process env      the REAL environment, read before phpdotenv values are considered
 *   4. base_path/.token per-app, for a box serving two sites on different projects
 *   5. /etc/doppler/.token  the box credential, outside the release directory
 *
 * Position 3 is the subtle one. env('DOPPLER_TOKEN') resolves through phpdotenv, which has
 * already loaded the .env this command is about to replace. A stale DOPPLER_TOKEN line in
 * that file is indistinguishable from a genuinely exported variable, and it vanishes the
 * moment the sync succeeds, producing "it worked yesterday and nothing changed". Reading
 * getenv() and $_SERVER directly avoids inheriting the file being replaced.
 */
final class TokenResolver
{
    /**
     * @param  list<string>  $files  Token file paths, searched in order.
     * @param  string  $envVar  Process environment variable name.
     */
    public function __construct(
        private readonly array $files = [],
        private readonly string $envVar = 'DOPPLER_TOKEN',
    ) {}

    /**
     * Resolve a token.
     *
     * @param  string|null  $explicitToken  From --token.
     * @param  string|null  $explicitFile  From --token-file. Suppresses the whole search.
     *
     * @throws TokenNotFound
     */
    public function resolve(
        ?string $explicitToken = null,
        ?string $explicitFile = null,
        ?string $project = null,
        ?string $config = null,
    ): Credential {
        $attempted = [];

        if ($explicitToken !== null && $this->isUsable($explicitToken)) {
            return Credential::make($this->clean($explicitToken), '--token', $project, $config);
        }

        if ($explicitToken !== null) {
            $attempted[] = '--token (given, but empty or whitespace-only)';
        }

        // An explicit --token-file is the only location consulted. Falling through to the
        // configured list would silently sync the wrong project when an operator points at
        // a file that does not exist yet.
        if ($explicitFile !== null) {
            $value = $this->readFile($explicitFile);

            if ($value !== null) {
                return Credential::make($value, $explicitFile, $project, $config);
            }

            throw TokenNotFound::afterSearching([
                sprintf('--token-file=%s (missing, unreadable, or empty)', $explicitFile),
            ]);
        }

        $fromEnv = $this->readProcessEnvironment();

        if ($fromEnv !== null) {
            return Credential::make(
                $fromEnv,
                sprintf('the %s process environment variable', $this->envVar),
                $project,
                $config,
            );
        }

        $attempted[] = sprintf('the %s process environment variable', $this->envVar);

        foreach ($this->files as $path) {
            $value = $this->readFile($path);

            if ($value !== null) {
                return Credential::make($value, $path, $project, $config);
            }

            $attempted[] = $this->describeMissingFile($path);
        }

        throw TokenNotFound::afterSearching($attempted);
    }

    /**
     * Read the real process environment, bypassing phpdotenv entirely.
     *
     * getenv() with no local-only flag reads the process environment. $_SERVER is checked
     * as a fallback because some SAPIs populate one and not the other. Laravel's env()
     * helper is deliberately not used: it reads the file being replaced.
     */
    private function readProcessEnvironment(): ?string
    {
        $value = getenv($this->envVar);

        if (is_string($value) && $this->isUsable($value)) {
            return $this->clean($value);
        }

        $value = $_SERVER[$this->envVar] ?? null;

        if (is_string($value) && $this->isUsable($value)) {
            return $this->clean($value);
        }

        return null;
    }

    /**
     * Read a token file, returning null when it is absent, unreadable, or blank.
     *
     * A blank file is not a token. It is a half-finished provisioning step, and treating
     * it as a credential produces a 401 that reads as "bad token" rather than
     * "you never wrote the file".
     */
    private function readFile(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || ! $this->isUsable($contents)) {
            return null;
        }

        return $this->clean($contents);
    }

    private function describeMissingFile(string $path): string
    {
        if (! file_exists($path)) {
            return $path.' (does not exist)';
        }

        if (! is_readable($path)) {
            return $path.' (exists, but is not readable by the current user)';
        }

        return $path.' (exists, but is empty or whitespace-only)';
    }

    private function isUsable(string $value): bool
    {
        return trim($value) !== '';
    }

    /**
     * Strip surrounding whitespace, including the trailing newline every editor adds.
     *
     * A token with a trailing \n produces a 401 that reads as a bad credential rather than
     * as a bad file, which is an expensive half hour.
     */
    private function clean(string $value): string
    {
        return trim($value);
    }
}
