<?php

declare(strict_types=1);

namespace AlexHackney\Doppler;

use AlexHackney\Doppler\Contracts\Doppler;
use AlexHackney\Doppler\Credentials\Credential;
use AlexHackney\Doppler\Credentials\TokenResolver;
use AlexHackney\Doppler\Exceptions\DopplerException;
use AlexHackney\Doppler\Exceptions\ValidationFailed;
use AlexHackney\Doppler\Hooks\HookRunner;
use AlexHackney\Doppler\Rendering\Renderer;
use AlexHackney\Doppler\Rendering\RendererFactory;
use AlexHackney\Doppler\Rendering\RoundTripGuard;
use AlexHackney\Doppler\Snapshot\SnapshotStore;
use AlexHackney\Doppler\Sources\ApiSource;
use AlexHackney\Doppler\Sources\CliSource;
use AlexHackney\Doppler\Sources\SecretSource;
use AlexHackney\Doppler\Sources\SnapshotSource;
use AlexHackney\Doppler\Support\Diff;
use AlexHackney\Doppler\Support\ExitCode;
use AlexHackney\Doppler\Support\SyncResult;
use AlexHackney\Doppler\Validation\Problem;
use AlexHackney\Doppler\Validation\Validator;
use AlexHackney\Doppler\Writing\AtomicWriter;
use AlexHackney\Doppler\Writing\Ownership;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The service the facade proxies, and the only place the pipeline order is expressed.
 *
 * Eight stages, each separately testable:
 *
 *   TokenResolver   where the credential came from, and what type it is
 *   SecretSource    fetch the secrets
 *   Overlay         apply preserved keys and the host-local overlay file
 *   Validator       collect problems
 *   Renderer        produce content for the target grammar
 *   RoundTripGuard  load it back; refuse on any mismatch
 *   Writer          lock, temp, chmod, chown, backup, rename
 *   Hooks           config:clear, then the rest, gated on config:cache
 *
 * Nothing after the validator runs when there are unforced problems, and nothing is
 * written when the round trip fails. Every abort path leaves the previous file untouched.
 */
final class DopplerManager implements Doppler
{
    /**
     * @var array<string, mixed>
     */
    private array $config;

    private ?string $activeProfile = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly Application $app,
        array $config,
    ) {
        $this->config = $config;
    }

    /**
     * Select a named profile for the next call.
     */
    public function profile(?string $profile): static
    {
        $clone = new self($this->app, $this->config);
        $clone->activeProfile = $profile;

        return $clone;
    }

    /**
     * Fetch secrets without touching any file.
     *
     * @return array<string, string>
     */
    public function secrets(?SyncOptions $options = null): array
    {
        $options ??= new SyncOptions;
        $config = $this->resolveConfig($options);
        $credential = $this->resolveCredential($config, $options);

        return $this->filter($this->source($config, $options)->fetch($credential), $options);
    }

    /**
     * Run a sync without writing anything.
     */
    public function dryRun(?SyncOptions $options = null): SyncResult
    {
        return $this->sync(($options ?? new SyncOptions)->withDryRun());
    }

    /**
     * Render, verify, write, and run hooks.
     */
    public function sync(?SyncOptions $options = null): SyncResult
    {
        $options ??= new SyncOptions;
        $config = $this->resolveConfig($options);

        $path = $options->path ?? $this->stringConfig($config, 'target.path', base_path('.env'));
        $targetName = $options->target ?? $this->stringConfig($config, 'target.grammar', 'laravel');
        $renderer = (new RendererFactory)->make($targetName);

        try {
            $secrets = $this->gather($config, $options);
        } catch (DopplerException $e) {
            return $this->handleSourceFailure($e, $config, $options, $path, $renderer, $targetName);
        }

        return $this->completeSync($secrets, $config, $options, $path, $renderer, $targetName);
    }

    /**
     * Fetch and post-process the secrets: filter, preserve, overlay.
     *
     * @param  array<string, mixed>  $config
     * @return array{secrets: array<string, string>, preserved: list<string>, overlaid: list<string>, driver: string, credential: Credential}
     */
    private function gather(array $config, SyncOptions $options): array
    {
        $credential = $this->resolveCredential($config, $options);
        $source = $this->source($config, $options);

        $secrets = $source->fetch($credential);
        $secrets = $this->filter($secrets, $options);

        $path = $options->path ?? $this->stringConfig($config, 'target.path', base_path('.env'));
        $existing = $this->readExisting($path, $options, $config);

        [$secrets, $preserved] = $this->applyPreserved($secrets, $existing, $config);
        [$secrets, $overlaid] = $this->applyOverlay($secrets, $config, $options);

        if ($options->writeFallback && ! $options->dryRun) {
            $this->snapshotStore($config, $options)->write($secrets, $credential);
        }

        return [
            'secrets' => $secrets,
            'preserved' => $preserved,
            'overlaid' => $overlaid,
            'driver' => $source->name(),
            'credential' => $credential,
        ];
    }

    /**
     * @param  array{secrets: array<string, string>, preserved: list<string>, overlaid: list<string>, driver: string, credential: Credential}  $gathered
     * @param  array<string, mixed>  $config
     */
    private function completeSync(
        array $gathered,
        array $config,
        SyncOptions $options,
        string $path,
        Renderer $renderer,
        string $targetName,
    ): SyncResult {
        $secrets = $gathered['secrets'];

        $problems = $this->validate($secrets, $config, $targetName);

        if ($problems !== [] && ! $options->force) {
            throw ValidationFailed::fromProblems($problems);
        }

        $content = $renderer->render($secrets);

        // The guard runs before anything touches the filesystem, on every path including
        // a dry run, so --dry-run genuinely proves the write would have been safe.
        (new RoundTripGuard)->verify($renderer, $content, $secrets);

        $diff = Diff::between($this->readExisting($path, $options, $config), $secrets);

        if ($options->dryRun) {
            return new SyncResult(
                written: false,
                path: $path,
                exitCode: $diff->hasDrift() ? ExitCode::DriftDetected : ExitCode::Success,
                unchanged: ! $diff->hasDrift(),
                diff: $diff,
                problems: $problems,
                overlaidKeys: $gathered['overlaid'],
                preservedKeys: $gathered['preserved'],
                secretCount: count($secrets),
                driver: $gathered['driver'],
                target: $targetName,
                renderedContent: $content,
            );
        }

        $writer = new AtomicWriter(
            ownership: $this->ownership($path),
            backup: (bool) $this->config($config, 'target.backup', true),
        );

        $outcome = $writer->write($path, $content);

        if (! $outcome->written) {
            // Nothing changed, so nothing downstream should run either. This is what makes
            // the command safe on a timer, and it means a green run with no output is
            // meaningful rather than ambiguous.
            return new SyncResult(
                written: false,
                path: $path,
                unchanged: true,
                diff: $diff,
                problems: $problems,
                overlaidKeys: $gathered['overlaid'],
                preservedKeys: $gathered['preserved'],
                secretCount: count($secrets),
                driver: $gathered['driver'],
                target: $targetName,
            );
        }

        $hooks = [];
        $exitCode = ExitCode::Success;

        if ($options->runHooks) {
            /** @var list<string> $configured */
            $configured = $this->config($config, 'hooks', []);

            $hooks = $this->hookRunner()->run($configured);
        }

        return new SyncResult(
            written: true,
            path: $path,
            exitCode: $exitCode,
            diff: $diff,
            problems: $problems,
            overlaidKeys: $gathered['overlaid'],
            preservedKeys: $gathered['preserved'],
            hooks: $hooks,
            secretCount: count($secrets),
            driver: $gathered['driver'],
            target: $targetName,
        );
    }

    /**
     * The soft-fail decision.
     *
     * The whole matrix in one place, because getting any row wrong is expensive:
     *
     *   unreachable or rate limited, existing file present, non-empty, valid
     *       -> keep it, log at ERROR level, exit 0. The deploy proceeds on config that is
     *          stale by minutes and almost certainly identical.
     *
     *   unreachable, and no existing file, or it is empty, or it fails validation
     *       -> hard fail. There is nothing to fall back to, and continuing means
     *          config:cache bakes a blank config while everything still looks correct.
     *
     *   authentication failed, validation failed, round trip failed
     *       -> hard fail, always. Not an outage. Somebody introduced a real problem.
     *
     * Validating the kept file is what makes this safe rather than merely convenient. It
     * covers the one case where "it probably has not changed" is wrong: the deploy that
     * introduces a new key. Code reading FOO_API_KEY deployed against an older file boots
     * fine and fails later, somewhere else, quietly.
     *
     * @param  array<string, mixed>  $config
     */
    private function handleSourceFailure(
        DopplerException $exception,
        array $config,
        SyncOptions $options,
        string $path,
        Renderer $renderer,
        string $targetName,
    ): SyncResult {
        // A snapshot is tried before soft-fail, because fresh-ish secrets beat stale ones.
        if ($exception->isSoftFailable() && $this->snapshotEnabled($config, $options) && ! $options->fallbackOnly) {
            $recovered = $this->trySnapshot($config, $options, $path, $renderer, $targetName);

            if ($recovered !== null) {
                return $recovered;
            }
        }

        if (! $this->softFailEnabled($config, $options) || ! $exception->isSoftFailable()) {
            throw $exception;
        }

        $existing = $this->readExistingRaw($path);

        if ($existing === null || trim($existing) === '') {
            throw $this->explainHardFail(
                $exception,
                sprintf(
                    'Soft-fail was requested, but %s does not exist or is empty, so there is nothing to keep. '.
                    'Continuing would let config:cache bake a blank configuration.',
                    $path,
                ),
            );
        }

        $existingSecrets = $renderer->parse($existing);
        $problems = $this->validate($existingSecrets, $config, $targetName);

        if ($problems !== []) {
            throw $this->explainHardFail(
                $exception,
                sprintf(
                    "Soft-fail was requested, but the existing %s does not pass validation, so keeping it is not safe:\n%s",
                    $path,
                    implode("\n", array_map(fn (Problem $p): string => '  - '.$p->describe(), $problems)),
                ),
            );
        }

        $age = $this->fileAge($path);

        // Error level, deliberately. A soft-fail produces a deploy that looks completely
        // successful, so this has to reach Sentry and the dashboards rather than only the
        // deploy output that nobody reads on a green build.
        Log::error('doppler: kept the existing environment file after a source failure', [
            'path' => $path,
            'reason' => $exception->getMessage(),
            'exit_code_suppressed' => $exception->exitCode()->value,
            'file_age_seconds' => $age,
            'secret_count' => count($existingSecrets),
        ]);

        return new SyncResult(
            written: false,
            path: $path,
            exitCode: ExitCode::Success,
            softFailed: true,
            softFailReason: $exception->getMessage(),
            keptFileAge: $age,
            secretCount: count($existingSecrets),
            driver: $options->driver ?? $this->stringConfig($config, 'driver', 'api'),
            target: $targetName,
        );
    }

    /**
     * Rethrow the original failure with an explanation of why soft-fail did not apply.
     */
    private function explainHardFail(DopplerException $exception, string $explanation): DopplerException
    {
        return $exception->explain($explanation);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function trySnapshot(
        array $config,
        SyncOptions $options,
        string $path,
        Renderer $renderer,
        string $targetName,
    ): ?SyncResult {
        try {
            $credential = $this->resolveCredential($config, $options);
            $store = $this->snapshotStore($config, $options);
            $secrets = $store->read($credential);

            $age = $store->ageInDays();
            $maxAge = (int) $this->config($config, 'fallback.max_age_days', 30);

            Log::warning('doppler: the source was unreachable, so a local snapshot was used', [
                'path' => $store->path(),
                'age_days' => $age,
                'stale' => $age !== null && $age > $maxAge,
            ]);

            $existing = $this->readExisting($path, $options, $config);
            [$secrets, $preserved] = $this->applyPreserved($secrets, $existing, $config);
            [$secrets, $overlaid] = $this->applyOverlay($secrets, $config, $options);

            return $this->completeSync(
                [
                    'secrets' => $secrets,
                    'preserved' => $preserved,
                    'overlaid' => $overlaid,
                    'driver' => 'snapshot',
                    'credential' => $credential,
                ],
                $config,
                $options,
                $path,
                $renderer,
                $targetName,
            );
        } catch (DopplerException) {
            // No usable snapshot. Fall through to the soft-fail decision, which is the
            // next rung down the ladder.
            return null;
        }
    }

    /**
     * @param  array<string, string>  $secrets
     * @param  array<string, mixed>  $config
     * @return list<Problem>
     */
    public function validate(array $secrets, array $config, string $grammar = 'laravel'): array
    {
        /** @var array<string, mixed> $validateConfig */
        $validateConfig = $this->config($config, 'validate', []);

        return Validator::fromConfig($validateConfig, $this->app->basePath(), $grammar)
            ->validate($secrets);
    }

    /**
     * Carry keys forward from the existing file when the source supplies no value.
     *
     * APP_KEY is the default and the reason the mechanism exists: it encrypts queued job
     * payloads sitting in the local database, so replacing it orphans every one of them.
     *
     * @param  array<string, string>  $secrets
     * @param  array<string, string>  $existing
     * @param  array<string, mixed>  $config
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function applyPreserved(array $secrets, array $existing, array $config): array
    {
        /** @var list<string> $keys */
        $keys = $this->config($config, 'preserve', []);
        $preserved = [];

        foreach ($keys as $key) {
            $supplied = $secrets[$key] ?? '';

            if (trim($supplied) !== '') {
                continue;
            }

            $carried = $existing[$key] ?? '';

            if (trim($carried) === '') {
                continue;
            }

            $secrets[$key] = $carried;
            $preserved[] = $key;
        }

        return [$secrets, $preserved];
    }

    /**
     * Merge a host-local overlay file on top of the rendered secrets.
     *
     * This is the answer to per-box values that must not live in a shared config: a server
     * identity, or queue topology that differs between two workers pointed at the same
     * Doppler config.
     *
     * @param  array<string, string>  $secrets
     * @param  array<string, mixed>  $config
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function applyOverlay(array $secrets, array $config, SyncOptions $options): array
    {
        $overlayPath = $this->config($config, 'overlay');

        if (! is_string($overlayPath) || $overlayPath === '' || ! is_file($overlayPath)) {
            return [$secrets, []];
        }

        $contents = @file_get_contents($overlayPath);

        if ($contents === false) {
            return [$secrets, []];
        }

        $overlay = (new RendererFactory)->make('laravel')->parse($contents);
        $overlaid = [];

        foreach ($overlay as $key => $value) {
            $secrets[$key] = $value;
            $overlaid[] = $key;
        }

        sort($overlaid, SORT_STRING);

        return [$secrets, $overlaid];
    }

    /**
     * @param  array<string, string>  $secrets
     * @return array<string, string>
     */
    private function filter(array $secrets, SyncOptions $options): array
    {
        if ($options->only !== []) {
            $secrets = array_intersect_key($secrets, array_flip($options->only));
        }

        if ($options->except !== []) {
            $secrets = array_diff_key($secrets, array_flip($options->except));
        }

        return $secrets;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function readExisting(string $path, SyncOptions $options, array $config): array
    {
        $raw = $this->readExistingRaw($path);

        if ($raw === null) {
            return [];
        }

        $targetName = $options->target ?? $this->stringConfig($config, 'target.grammar', 'laravel');

        try {
            return (new RendererFactory)->make($targetName)->parse($raw);
        } catch (DopplerException) {
            // An unparseable existing file is not a reason to refuse to replace it. It is
            // a reason to replace it. The diff simply treats every key as new.
            return [];
        }
    }

    private function readExistingRaw(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private function fileAge(string $path): ?int
    {
        $mtime = @filemtime($path);

        return $mtime === false ? null : max(time() - $mtime, 0);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveCredential(array $config, SyncOptions $options): Credential
    {
        /** @var list<string> $files */
        $files = $this->config($config, 'token.files', []);

        $resolver = new TokenResolver(
            files: $files,
            envVar: $this->stringConfig($config, 'token.env_var', 'DOPPLER_TOKEN'),
        );

        return $resolver->resolve(
            explicitToken: $options->token,
            explicitFile: $options->tokenFile,
            project: $options->project,
            config: $options->config,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function source(array $config, SyncOptions $options): SecretSource
    {
        $driver = $options->fallbackOnly
            ? 'snapshot'
            : ($options->driver ?? $this->stringConfig($config, 'driver', 'api'));

        return match ($driver) {
            'api' => new ApiSource(
                http: $this->app->make(HttpFactory::class),
                baseUrl: $this->stringConfig($config, 'api.base_url', 'https://api.doppler.com/v3'),
                timeout: (int) $this->config($config, 'api.timeout', 15),
                retries: (int) $this->config($config, 'api.retries', 2),
                retryDelayMs: (int) $this->config($config, 'api.retry_delay_ms', 500),
            ),
            'cli' => new CliSource(
                process: $this->app->make(ProcessFactory::class),
                binary: $this->stringConfig($config, 'cli.binary', 'doppler'),
                timeout: (int) $this->config($config, 'cli.timeout', 30),
            ),
            'snapshot' => new SnapshotSource($this->snapshotStore($config, $options)),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown driver "%s". Available drivers: api, cli, snapshot.',
                $driver,
            )),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function snapshotStore(array $config, SyncOptions $options): SnapshotStore
    {
        $passphrase = $this->config($config, 'fallback.passphrase');

        return new SnapshotStore(
            path: $options->fallbackPath ?? $this->stringConfig($config, 'fallback.path', '/etc/doppler/snapshot.enc'),
            passphrase: is_string($passphrase) && $passphrase !== '' ? $passphrase : null,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function snapshotEnabled(array $config, SyncOptions $options): bool
    {
        return $options->fallbackPath !== null
            || (bool) $this->config($config, 'fallback.enabled', false);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function softFailEnabled(array $config, SyncOptions $options): bool
    {
        return $options->softFail ?? (bool) $this->config($config, 'soft_fail', false);
    }

    private function ownership(string $path): Ownership
    {
        return new Ownership([
            $path,
            $this->app->basePath('composer.json'),
            $this->app->basePath(),
        ]);
    }

    private function hookRunner(): HookRunner
    {
        return new HookRunner(
            kernel: $this->app->make(ConsoleKernel::class),
            process: $this->app->make(ProcessFactory::class),
        );
    }

    /**
     * Merge the selected profile over the base configuration.
     *
     * @return array<string, mixed>
     */
    public function resolveConfig(SyncOptions $options): array
    {
        $profileName = $options->profile ?? $this->activeProfile;

        if ($profileName === null) {
            return $this->config;
        }

        /** @var array<string, array<string, mixed>> $profiles */
        $profiles = $this->config['profiles'] ?? [];

        if (! array_key_exists($profileName, $profiles)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown profile "%s". Defined profiles: %s.',
                $profileName,
                $profiles === [] ? 'none' : implode(', ', array_keys($profiles)),
            ));
        }

        return $this->mergeDeep($this->config, $profiles[$profileName]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergeDeep(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value)) {
                $base[$key] = $this->mergeDeep($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Dot-notation read against a config array.
     *
     * @param  array<string, mixed>  $config
     */
    private function config(array $config, string $key, mixed $default = null): mixed
    {
        $value = $config;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value ?? $default;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function stringConfig(array $config, string $key, string $default): string
    {
        $value = $this->config($config, $key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * The raw configuration this manager was built with.
     *
     * @return array<string, mixed>
     */
    public function rawConfig(): array
    {
        return $this->config;
    }
}
