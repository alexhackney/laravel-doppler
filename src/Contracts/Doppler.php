<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Contracts;

use AlexHackney\Doppler\Snapshot\SnapshotStore;
use AlexHackney\Doppler\Support\SyncResult;
use AlexHackney\Doppler\SyncOptions;

/**
 * The public surface of this package, and the type everything resolves through.
 *
 * This exists so `Doppler::fake()` can substitute a test double without a TypeError.
 * `Facade::swap()` rebinds the facade's accessor, so the accessor has to be a type the fake
 * also satisfies. When that accessor was the concrete manager, every container resolution of
 * it — including this package's own commands, which take it as a `handle()` argument —
 * exploded the moment a consuming app called `Doppler::fake()` and then ran `env:sync`,
 * which is the single thing most worth testing.
 *
 * **Type-hint this, not `DopplerManager`.** The concrete class stays bound and aliased for
 * anyone who already injects it, but a `fake()` cannot intercept that binding, so a test
 * against it silently talks to the real Doppler.
 */
interface Doppler
{
    /**
     * Render, verify, write, and run hooks.
     */
    public function sync(?SyncOptions $options = null): SyncResult;

    /**
     * Run a sync without writing anything.
     */
    public function dryRun(?SyncOptions $options = null): SyncResult;

    /**
     * Fetch secrets without touching any file.
     *
     * @return array<string, string>
     */
    public function secrets(?SyncOptions $options = null): array;

    /**
     * Select a named profile for the next call.
     */
    public function profile(?string $profile): static;

    /**
     * The configuration as given, before any profile is merged over it.
     *
     * @return array<string, mixed>
     */
    public function rawConfig(): array;

    /**
     * The configuration for this invocation, with the selected profile merged over it.
     *
     * @return array<string, mixed>
     */
    public function resolveConfig(SyncOptions $options): array;

    /**
     * The snapshot store this invocation would read from or write to.
     *
     * @param  array<string, mixed>  $config
     */
    public function snapshotStore(array $config, SyncOptions $options): SnapshotStore;
}
