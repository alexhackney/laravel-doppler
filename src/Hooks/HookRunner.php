<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Hooks;

use AlexHackney\Doppler\Exceptions\HookFailed;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Process\Factory as ProcessFactory;
use Throwable;

/**
 * Runs post-sync hooks, in an order the package enforces rather than trusts.
 *
 * Rendering the file changes nothing by itself. A running php-fpm with cached config keeps
 * serving the old values, and queue workers read their environment at boot. Without hooks
 * the sync appears to work and changes nothing observable, which is worse than failing.
 *
 * Two ordering guarantees, both of which exist because getting them wrong is expensive:
 *
 *   - config:clear always runs first, whatever order it appears in config. A later hook
 *     that boots the app would otherwise read a stale cached config.
 *
 *   - If config:cache is configured and FAILS, every remaining hook is skipped and the
 *     command exits non-zero. A cache built from a bad file bakes in blank secrets while
 *     the configuration still looks correct, and an auth middleware that fails closed on a
 *     blank secret then returns 503 for every request. Restarting workers on top of that
 *     spreads the damage rather than containing it.
 *
 * Note that config.php is opcached, so config:cache alone does not take effect for web
 * requests until the fpm pool reloads. Anyone caching config also needs a reload hook.
 */
final class HookRunner
{
    public function __construct(
        private readonly ConsoleKernel $kernel,
        private readonly ProcessFactory $process,
        private readonly int $timeout = 60,
    ) {}

    /**
     * Run the configured hooks.
     *
     * @param  list<string>  $hooks
     * @param  callable(string): void|null  $onStart  Called with each hook before it runs.
     * @return list<HookResult>
     *
     * @throws HookFailed
     */
    public function run(array $hooks, ?callable $onStart = null): array
    {
        $ordered = $this->order($hooks);
        $results = [];

        foreach ($ordered as $hook) {
            if ($onStart !== null) {
                $onStart($hook);
            }

            $result = $this->runOne($hook);
            $results[] = $result;

            if ($result->successful) {
                continue;
            }

            // config:cache failing is the one case that must stop everything after it.
            $aborted = $this->isConfigCache($hook) && $hook !== end($ordered);

            throw HookFailed::make($hook, $result->exitCode, $aborted);
        }

        return $results;
    }

    /**
     * Put config:clear first, preserving the caller's order for everything else.
     *
     * @param  list<string>  $hooks
     * @return list<string>
     */
    public function order(array $hooks): array
    {
        $clear = [];
        $rest = [];

        foreach ($hooks as $hook) {
            if ($this->isConfigClear($hook)) {
                $clear[] = $hook;

                continue;
            }

            $rest[] = $hook;
        }

        return [...$clear, ...$rest];
    }

    private function runOne(string $hook): HookResult
    {
        if (str_starts_with($hook, 'artisan:')) {
            return $this->runArtisan(substr($hook, strlen('artisan:')), $hook);
        }

        if (str_starts_with($hook, 'shell:')) {
            return $this->runShell(substr($hook, strlen('shell:')), $hook);
        }

        return new HookResult(
            hook: $hook,
            successful: false,
            exitCode: null,
            output: sprintf(
                'Unrecognised hook "%s". Prefix with artisan: or shell:.',
                $hook,
            ),
        );
    }

    private function runArtisan(string $command, string $hook): HookResult
    {
        $parts = preg_split('/\s+/', trim($command)) ?: [];
        $name = array_shift($parts) ?? '';

        if ($name === '') {
            return new HookResult($hook, false, null, 'Empty artisan hook.');
        }

        try {
            $exitCode = $this->kernel->call($name, $this->parseArtisanArguments($parts));
            $output = $this->kernel->output();
        } catch (Throwable $e) {
            return new HookResult($hook, false, null, $e->getMessage());
        }

        return new HookResult($hook, $exitCode === 0, $exitCode, $output);
    }

    /**
     * Positional arguments keep integer keys; options key on their `--name`.
     *
     * @param  list<string>  $parts
     * @return array<int|string, string|bool>
     */
    private function parseArtisanArguments(array $parts): array
    {
        $arguments = [];
        $positional = 0;

        foreach ($parts as $part) {
            if (! str_starts_with($part, '--')) {
                $arguments[$positional++] = $part;

                continue;
            }

            if (str_contains($part, '=')) {
                [$name, $value] = explode('=', $part, 2);
                $arguments[$name] = $value;

                continue;
            }

            $arguments[$part] = true;
        }

        return $arguments;
    }

    private function runShell(string $command, string $hook): HookResult
    {
        $command = trim($command);

        if ($command === '') {
            return new HookResult($hook, false, null, 'Empty shell hook.');
        }

        try {
            $result = $this->process->newPendingProcess()->timeout($this->timeout)->run($command);
        } catch (Throwable $e) {
            return new HookResult($hook, false, null, $e->getMessage());
        }

        return new HookResult(
            hook: $hook,
            successful: $result->successful(),
            exitCode: $result->exitCode(),
            output: trim($result->output().$result->errorOutput()),
        );
    }

    private function isConfigClear(string $hook): bool
    {
        return preg_match('/^artisan:\s*config:clear\b/', $hook) === 1;
    }

    private function isConfigCache(string $hook): bool
    {
        return preg_match('/^artisan:\s*config:cache\b/', $hook) === 1;
    }
}
