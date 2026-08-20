<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Commands;

use AlexHackney\Doppler\Contracts\Doppler;
use AlexHackney\Doppler\Credentials\TokenResolver;
use AlexHackney\Doppler\Exceptions\TokenNotFound;
use AlexHackney\Doppler\Support\ExitCode;
use AlexHackney\Doppler\SyncOptions;
use AlexHackney\Doppler\Writing\AtomicWriter;
use Illuminate\Console\Command;

/**
 * Answers "why did my sync not do what I expected", without a single network call and
 * without printing a single secret.
 *
 * Every check here corresponds to something that has actually cost somebody an afternoon.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'env:doctor
        {--profile= : Named profile from config/doppler.php}
        {--fix : Append the missing .gitignore entries for the files this package writes}';

    protected $description = 'Diagnose the Doppler setup on this box. No network, no secrets printed';

    /**
     * Secret-bearing files found inside a git work tree that nothing ignores.
     *
     * Keyed by git root, because the token can legitimately live in a different repository
     * from the target — a box serving two sites is the whole reason base_path('.token')
     * exists — and each root gets its own .gitignore.
     *
     * @var array<string, list<string>>
     */
    private array $unignored = [];

    /**
     * Whether POSIX file modes mean anything on this platform.
     *
     * PHP's chmod() on Windows toggles the read-only attribute and nothing else, so a
     * 0600 request is a silent no-op and the file reports 0666. Access there is governed
     * by NTFS ACLs, which this package does not set. Saying so is the honest option:
     * printing "chmod 600 it" on a platform where that cannot work sends an operator
     * chasing a fix that does not exist, and staying silent lets them assume the mode was
     * applied when it was not.
     */
    private function enforcesFileModes(): bool
    {
        return PHP_OS_FAMILY !== 'Windows';
    }

    public function handle(Doppler $doppler): int
    {
        // Reset, because the container hands back the same command instance on a second
        // Artisan::call within one process. Without this a second --fix re-appends every
        // entry the first one already wrote.
        $this->unignored = [];

        $options = new SyncOptions(profile: $this->stringOption('profile'));
        $config = $doppler->resolveConfig($options);

        $this->newLine();
        $this->components->info('Doppler doctor. No network calls, no secret values.');
        $this->newLine();

        if (! $this->enforcesFileModes()) {
            $this->components->warn(
                'Windows: PHP cannot set POSIX file modes here, so the 0600 this package '.
                'requests on every file it writes is a no-op and modes below read as 0666. '.
                'Access is governed by NTFS ACLs, which this package does not set. Treat the '.
                'directory holding these files as the security boundary.',
            );
            $this->newLine();
        }

        // Both checks always run: a broken token and a broken target are independent
        // problems and an operator wants to see both in one pass, not one per run.
        $tokenHealthy = $this->checkToken($config);
        $targetHealthy = $this->checkTarget($config);
        $healthy = $tokenHealthy && $targetHealthy;

        $this->checkConfigCache($config);
        $this->checkEnvShadowing();
        $this->checkHooks($config);
        $this->checkSnapshot($config, $doppler, $options);
        $this->reportOrFixIgnores();

        $this->newLine();

        return $healthy ? ExitCode::Success->value : ExitCode::ValidationFailed->value;
    }

    /**
     * Offer, or apply, the .gitignore entries the checks above found missing.
     *
     * This is the closest thing to install-time automation that is actually reliable.
     * Composer runs `scripts` only for the root package, so a library cannot hook its own
     * installation without shipping a composer-plugin — and since Composer 2.2 a plugin
     * does nothing at all unless the application separately allow-lists it, so the
     * "automatic" version would silently not run for most people. An explicit flag on the
     * command that already found the problem is both simpler and harder to miss.
     *
     * Only ever appends, and only entries the ignore check just proved are missing, so it
     * is idempotent and cannot reorder or drop anything already in the file.
     */
    private function reportOrFixIgnores(): void
    {
        if ($this->unignored === []) {
            return;
        }

        $this->newLine();

        if (! $this->option('fix')) {
            $this->components->twoColumnDetail(
                'To fix',
                'php artisan env:doctor --fix',
            );

            return;
        }

        foreach ($this->unignored as $gitDirectory => $paths) {
            $this->appendIgnoreEntries($gitDirectory, $paths);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private function appendIgnoreEntries(string $gitDirectory, array $paths): void
    {
        $file = $gitDirectory.'/.gitignore';

        $entries = [];

        foreach ($paths as $path) {
            // Anchored to the repository root, so the entry means this exact file and not
            // any same-named file elsewhere in the tree.
            $entries[] = '/'.$this->relativeToGitRoot($gitDirectory, $path);
        }

        $entries = array_values(array_unique($entries));

        $existing = is_file($file) ? @file_get_contents($file) : '';

        if ($existing === false) {
            $this->components->error(sprintf('  %s exists but could not be read.', $file));

            return;
        }

        $block = '';

        // A file not ending in a newline would otherwise glue the comment onto whatever
        // the last line already was.
        if ($existing !== '' && ! str_ends_with($existing, "\n")) {
            $block .= "\n";
        }

        $block .= "\n# alexhackney/laravel-doppler: never commit these\n";
        $block .= implode("\n", $entries)."\n";

        if (@file_put_contents($file, $block, FILE_APPEND) === false) {
            $this->components->error(sprintf('  %s could not be written.', $file));

            return;
        }

        $this->components->info(sprintf('Appended %d entr%s to %s:', count($entries), count($entries) === 1 ? 'y' : 'ies', $file));

        foreach ($entries as $entry) {
            $this->components->twoColumnDetail('  added', $entry);
        }
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
            $this->checkFileIsIgnored($credential->source, 'your Doppler token');
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

        if (($mode & 0077) !== 0 && $this->enforcesFileModes()) {
            $this->components->warn(sprintf(
                '  %s is readable by group or others. chmod 600 it.',
                $path,
            ));
        }
    }

    /**
     * Warn when a secret-bearing file sits inside a git work tree without being ignored.
     *
     * Both file-based sources git reads per repository are consulted: .gitignore, and
     * .git/info/exclude for an operator who would rather not touch a tracked file. A global
     * excludes file cannot be resolved from here without shelling out to git, so the warning
     * names the files that were actually read rather than asserting the file is committable.
     *
     * @param  string  $what  What the file is, for the warning text.
     */
    private function checkFileIsIgnored(string $path, string $what): bool
    {
        $gitDirectory = $this->findGitRoot(dirname($path));

        if ($gitDirectory === null) {
            return true;
        }

        $sources = [$gitDirectory.'/.gitignore', $gitDirectory.'/.git/info/exclude'];

        foreach ($sources as $source) {
            if ($this->ignores($source, $gitDirectory, $path)) {
                $this->components->twoColumnDetail('  ignored by', $source);

                return true;
            }
        }

        $this->components->warn(sprintf(
            '  %s (%s) is inside a git work tree and is not ignored by %s. It is one '.
            '`git add .` from being committed.',
            $path,
            $what,
            implode(' or ', $sources),
        ));

        $this->unignored[$gitDirectory][] = $path;

        return false;
    }

    /**
     * A path expressed relative to its repository root.
     *
     * The git root came from realpath(), so the path has to be resolved the same way or the
     * prefix will not strip on any box where the checkout sits under a symlink. An atomic
     * deploy's current -> releases/N is exactly that.
     */
    private function relativeToGitRoot(string $gitDirectory, string $path): string
    {
        $resolved = realpath($path);
        $resolved = $resolved === false ? $path : $resolved;

        return str_starts_with($resolved, $gitDirectory.'/')
            ? substr($resolved, strlen($gitDirectory) + 1)
            : basename($path);
    }

    /**
     * Whether an ignore file carries a pattern matching this token file.
     *
     * A deliberate subset of git's grammar, chosen so the failure direction is a spurious
     * warning rather than a silent "it is ignored" on a file that is not. A pattern with no
     * slash matches at any depth, so it is tested against the basename; a pattern carrying a
     * slash is anchored to the repository root, so it is tested against the relative path
     * with FNM_PATHNAME. Negations are skipped rather than honoured, for the same reason.
     */
    private function ignores(string $ignoreFile, string $gitDirectory, string $path): bool
    {
        if (! is_file($ignoreFile) || ! is_readable($ignoreFile)) {
            return false;
        }

        $contents = @file_get_contents($ignoreFile);

        if ($contents === false) {
            return false;
        }

        $name = basename($path);
        $relative = $this->relativeToGitRoot($gitDirectory, $path);

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $pattern = trim($line);

            if ($pattern === '' || str_starts_with($pattern, '#') || str_starts_with($pattern, '!')) {
                continue;
            }

            $anchored = str_contains(rtrim($pattern, '/'), '/') && ! str_starts_with($pattern, '**/');

            $pattern = rtrim(ltrim($pattern, '/'), '/');

            if (str_starts_with($pattern, '**/')) {
                $pattern = substr($pattern, 3);
            }

            if ($pattern === '') {
                continue;
            }

            $matched = $anchored
                ? fnmatch($pattern, $relative, FNM_PATHNAME)
                : fnmatch($pattern, $name);

            if ($matched) {
                return true;
            }
        }

        return false;
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

        $this->checkFileIsIgnored($path, 'your rendered environment file');
        $this->checkWriteArtefacts($path);

        return true;
    }

    /**
     * The two files a sync leaves beside the target.
     *
     * Worth its own check because neither is obvious from the outside and one of them holds
     * every secret the previous render did. Laravel's application .gitignore covers
     * `.env.backup` and `.env.lock` only insofar as they sit next to a `.env` it already
     * names — point target.path at `config/app.env` and nothing covers either of them.
     */
    private function checkWriteArtefacts(string $path): void
    {
        $backup = $path.AtomicWriter::BACKUP_SUFFIX;

        if (is_file($backup)) {
            $this->newLine();
            $this->components->twoColumnDetail('Backup', $backup);

            $perms = @fileperms($backup);

            if ($perms !== false) {
                $mode = $perms & 0777;

                $this->components->twoColumnDetail('  permissions', sprintf('0%o', $mode));

                if (($mode & 0077) !== 0 && $this->enforcesFileModes()) {
                    $this->components->warn(sprintf(
                        '  %s holds every secret from the previous render and is readable by '.
                        'group or others. chmod 600 it.',
                        $backup,
                    ));
                }
            }

            $this->checkFileIsIgnored($backup, 'every secret from the previous render');
        }

        $lock = $path.AtomicWriter::LOCK_SUFFIX;

        if (is_file($lock)) {
            // Empty by design, so this is repository hygiene rather than a leak.
            $this->checkFileIsIgnored($lock, 'the sync lock, empty but permanent');
        }
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
    private function checkSnapshot(array $config, Doppler $doppler, SyncOptions $options): void
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
