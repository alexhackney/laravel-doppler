<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Commands;

use AlexHackney\Doppler\Credentials\TokenResolver;
use AlexHackney\Doppler\DopplerManager;
use AlexHackney\Doppler\Exceptions\TokenNotFound;
use AlexHackney\Doppler\Support\ExitCode;
use AlexHackney\Doppler\SyncOptions;
use Illuminate\Console\Command;

/**
 * Answers "why did my sync not do what I expected", without a single network call and
 * without printing a single secret.
 *
 * Every check here corresponds to something that has actually cost somebody an afternoon.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'env:doctor {--profile= : Named profile from config/doppler.php}';

    protected $description = 'Diagnose the Doppler setup on this box. No network, no secrets printed';

    public function handle(DopplerManager $doppler): int
    {
        $options = new SyncOptions(profile: $this->stringOption('profile'));
        $config = $doppler->resolveConfig($options);

        $this->newLine();
        $this->components->info('Doppler doctor. No network calls, no secret values.');
        $this->newLine();

        // Both checks always run: a broken token and a broken target are independent
        // problems and an operator wants to see both in one pass, not one per run.
        $tokenHealthy = $this->checkToken($config);
        $targetHealthy = $this->checkTarget($config);
        $healthy = $tokenHealthy && $targetHealthy;

        $this->checkConfigCache($config);
        $this->checkEnvShadowing();
        $this->checkHooks($config);
        $this->checkSnapshot($config, $doppler, $options);

        $this->newLine();

        return $healthy ? ExitCode::Success->value : ExitCode::ValidationFailed->value;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function checkToken(array $config): bool
    {
        /** @var list<string> $files */
        $files = data_get($config, 'token.files', []);
        $envVar = (string) data_get($config, 'token.env_var', 'DOPPLER_TOKEN');

        $resolver = new TokenResolver($files, $envVar);

        try {
            $credential = $resolver->resolve();
        } catch (TokenNotFound $e) {
            $this->components->error('No token could be resolved.');

            foreach ($e->attempted as $location) {
                $this->components->twoColumnDetail('  tried', $location);
            }

            return false;
        }

        $this->components->twoColumnDetail('Token', $credential->redacted());
        $this->components->twoColumnDetail('  from', $credential->source);
        $this->components->twoColumnDetail('  type', $credential->type->label());

        if ($credential->needsScope()) {
            $this->components->error(
                '  This token type needs --project and --config, which are not configured.',
            );

            return false;
        }

        // A token file sitting inside the repository checkout is one command away from
        // being committed, and on a Forge deploy base_path('.token') is exactly that.
        if (is_file($credential->source)) {
            $this->checkTokenFilePermissions($credential->source);
            $this->checkTokenFileIsIgnored($credential->source);
        }

        return true;
    }

    private function checkTokenFilePermissions(string $path): void
    {
        $perms = @fileperms($path);

        if ($perms === false) {
            return;
        }

        $mode = $perms & 0777;

        $this->components->twoColumnDetail('  permissions', sprintf('0%o', $mode));

        if (($mode & 0077) !== 0) {
            $this->components->warn(sprintf(
                '  %s is readable by group or others. chmod 600 it.',
                $path,
            ));
        }
    }

    private function checkTokenFileIsIgnored(string $path): void
    {
        $directory = dirname($path);
        $gitDirectory = $this->findGitRoot($directory);

        if ($gitDirectory === null) {
            return;
        }

        $ignoreFile = $gitDirectory.'/.gitignore';
        $name = basename($path);

        $ignored = is_file($ignoreFile)
            && preg_match('/^\/?'.preg_quote($name, '/').'$/m', (string) @file_get_contents($ignoreFile)) === 1;

        if ($ignored) {
            $this->components->twoColumnDetail('  gitignored', 'yes');

            return;
        }

        $this->components->warn(sprintf(
            '  %s is inside a git work tree and does not appear in %s. It is one `git add .` from being committed.',
            $path,
            $ignoreFile,
        ));
    }

    private function findGitRoot(string $directory): ?string
    {
        $current = realpath($directory);

        if ($current === false) {
            return null;
        }

        while ($current !== '/') {
            if (is_dir($current.'/.git')) {
                return $current;
            }

            $parent = dirname($current);

            // dirname() is its own fixed point at the filesystem root, on every platform.
            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function checkTarget(array $config): bool
    {
        $path = (string) data_get($config, 'target.path', base_path('.env'));
        $grammar = (string) data_get($config, 'target.grammar', 'laravel');

        $this->newLine();
        $this->components->twoColumnDetail('Target', $path);
        $this->components->twoColumnDetail('  grammar', $grammar);

        $directory = dirname($path);

        if (! is_dir($directory)) {
            $this->components->error(sprintf('  %s does not exist.', $directory));

            return false;
        }

        if (! is_writable($directory)) {
            $this->components->error(sprintf(
                '  %s is not writable by the current user, so the atomic write will fail.',
                $directory,
            ));

            return false;
        }

        if (! is_file($path)) {
            $this->components->warn('  Does not exist yet. Soft-fail cannot protect a first sync.');

            return true;
        }

        $perms = @fileperms($path);

        if ($perms !== false) {
            $this->components->twoColumnDetail('  permissions', sprintf('0%o', $perms & 0777));
        }

        $owner = @fileowner($path);
        $current = function_exists('posix_geteuid') ? posix_geteuid() : null;

        if ($owner !== false && $current !== null && $owner !== $current) {
            $this->components->warn(sprintf(
                '  Owned by uid %d, but this process is uid %d. A 0600 file owned by another user '.
                'is invisible to it, and every config value silently becomes empty.',
                $owner,
                $current,
            ));
        }

        $this->components->twoColumnDetail('  readable now', is_readable($path) ? 'yes' : 'NO');

        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function checkConfigCache(array $config): void
    {
        $cached = base_path('bootstrap/cache/config.php');

        $this->newLine();

        if (! file_exists($cached)) {
            $this->components->twoColumnDetail('Cached config', 'none');

            return;
        }

        $this->components->twoColumnDetail('Cached config', $cached);

        /** @var list<string> $hooks */
        $hooks = data_get($config, 'hooks', []);

        $hasCache = $this->hooksContain($hooks, 'config:cache');
        $hasClear = $this->hooksContain($hooks, 'config:clear');

        if (! $hasCache && ! $hasClear) {
            $this->components->error(
                '  A cached config exists but no config:clear or config:cache hook is configured. '.
                'The sync will appear to do nothing: the app keeps serving the cached values.',
            );

            return;
        }

        if ($hasCache) {
            $this->components->warn(
                '  config.php is opcached, so config:cache does not reach web requests until the '.
                'fpm pool reloads. Add a reload hook, or the web tier keeps the old values.',
            );
        }
    }

    /**
     * A stale .env.production on a box silently wins over everything this package writes,
     * whenever APP_ENV is set in the server environment before dotenv loads.
     */
    private function checkEnvShadowing(): void
    {
        $appEnv = getenv('APP_ENV');

        if (! is_string($appEnv) || $appEnv === '') {
            return;
        }

        $shadow = base_path('.env.'.$appEnv);

        if (! file_exists($shadow)) {
            return;
        }

        $this->newLine();
        $this->components->error(sprintf(
            'APP_ENV is "%s" in the process environment and %s exists. That file shadows .env '.
            'entirely, so everything this package writes is ignored.',
            $appEnv,
            $shadow,
        ));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function checkHooks(array $config): void
    {
        /** @var list<string> $hooks */
        $hooks = data_get($config, 'hooks', []);

        $this->newLine();

        if ($hooks === []) {
            $this->components->warn('Hooks: none configured. A running app will not pick up new values.');

            return;
        }

        $this->components->twoColumnDetail('Hooks', (string) count($hooks));

        foreach ($hooks as $hook) {
            $recognised = str_starts_with($hook, 'artisan:') || str_starts_with($hook, 'shell:');

            $this->components->twoColumnDetail(
                '  '.$hook,
                $recognised ? 'ok' : 'UNRECOGNISED (needs artisan: or shell:)',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function checkSnapshot(array $config, DopplerManager $doppler, SyncOptions $options): void
    {
        if (! (bool) data_get($config, 'fallback.enabled', false)) {
            return;
        }

        $store = $doppler->snapshotStore($config, $options);

        $this->newLine();
        $this->components->twoColumnDetail('Snapshot', $store->path());

        if (! $store->exists()) {
            $this->components->warn('  Enabled, but no snapshot has been written yet. Run env:snapshot.');

            return;
        }

        $age = $store->ageInDays();
        $maxAge = (int) data_get($config, 'fallback.max_age_days', 30);

        $this->components->twoColumnDetail('  age', sprintf('%d day(s)', $age ?? 0));

        if ($age !== null && $age > $maxAge) {
            $this->components->warn(sprintf('  Older than the configured %d day limit.', $maxAge));
        }
    }

    /**
     * @param  list<string>  $hooks
     */
    private function hooksContain(array $hooks, string $needle): bool
    {
        foreach ($hooks as $hook) {
            if (str_contains($hook, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
