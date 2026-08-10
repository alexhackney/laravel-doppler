<?php

declare(strict_types=1);

namespace AlexHackney\Doppler;

/**
 * Per-invocation overrides for a sync.
 *
 * Everything here corresponds to a command flag. Null means "use the configured value",
 * which is what lets --soft-fail and --no-soft-fail both override a config setting in
 * their own direction.
 */
final class SyncOptions
{
    /**
     * @param  list<string>  $only  Allow-list of keys.
     * @param  list<string>  $except  Deny-list of keys.
     */
    public function __construct(
        public readonly ?string $token = null,
        public readonly ?string $tokenFile = null,
        public readonly ?string $project = null,
        public readonly ?string $config = null,
        public readonly ?string $profile = null,
        public readonly ?string $path = null,
        public readonly ?string $target = null,
        public readonly ?string $driver = null,
        public readonly array $only = [],
        public readonly array $except = [],
        public readonly bool $dryRun = false,
        public readonly bool $force = false,
        public readonly ?bool $softFail = null,
        public readonly bool $runHooks = true,
        public readonly ?string $fallbackPath = null,
        public readonly bool $fallbackOnly = false,
        public readonly bool $writeFallback = false,
    ) {}

    public function withDryRun(bool $dryRun = true): self
    {
        return $this->with(['dryRun' => $dryRun]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(
            token: $changes['token'] ?? $this->token,
            tokenFile: $changes['tokenFile'] ?? $this->tokenFile,
            project: $changes['project'] ?? $this->project,
            config: $changes['config'] ?? $this->config,
            profile: $changes['profile'] ?? $this->profile,
            path: $changes['path'] ?? $this->path,
            target: $changes['target'] ?? $this->target,
            driver: $changes['driver'] ?? $this->driver,
            only: $changes['only'] ?? $this->only,
            except: $changes['except'] ?? $this->except,
            dryRun: $changes['dryRun'] ?? $this->dryRun,
            force: $changes['force'] ?? $this->force,
            softFail: array_key_exists('softFail', $changes) ? $changes['softFail'] : $this->softFail,
            runHooks: $changes['runHooks'] ?? $this->runHooks,
            fallbackPath: $changes['fallbackPath'] ?? $this->fallbackPath,
            fallbackOnly: $changes['fallbackOnly'] ?? $this->fallbackOnly,
            writeFallback: $changes['writeFallback'] ?? $this->writeFallback,
        );
    }
}
