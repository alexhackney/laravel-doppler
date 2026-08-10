<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Sources;

use AlexHackney\Doppler\Credentials\Credential;
use AlexHackney\Doppler\Snapshot\SnapshotStore;

/**
 * Reads from the local encrypted snapshot, never touching the network.
 *
 * Used directly via --driver=snapshot or --fallback-only, and used automatically as the
 * second step in the fallback order when the primary source is unreachable.
 */
final class SnapshotSource implements SecretSource
{
    public function __construct(
        private readonly SnapshotStore $store,
    ) {}

    public function name(): string
    {
        return 'snapshot';
    }

    /**
     * @return array<string, string>
     */
    public function fetch(Credential $credential): array
    {
        return $this->store->read($credential);
    }

    public function store(): SnapshotStore
    {
        return $this->store;
    }
}
