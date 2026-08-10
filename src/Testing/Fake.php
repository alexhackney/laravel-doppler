<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Testing;

use AlexHackney\Doppler\Exceptions\AuthenticationFailed;
use AlexHackney\Doppler\Exceptions\SourceUnavailable;
use AlexHackney\Doppler\Support\Diff;
use AlexHackney\Doppler\Support\SyncResult;
use AlexHackney\Doppler\SyncOptions;
use PHPUnit\Framework\Assert;

/**
 * A test double for consuming applications.
 *
 * The point of shipping this is that an app testing its own deploy path should never have
 * to know what HTTP calls this package makes. Faking at the package boundary means the
 * app's tests keep passing when the package changes how it talks to Doppler.
 */
final class Fake
{
    /**
     * @var list<SyncOptions>
     */
    private array $syncs = [];

    private ?SourceUnavailable $unavailable = null;

    private ?AuthenticationFailed $unauthenticated = null;

    /**
     * @param  array<string, string>  $secrets
     */
    public function __construct(
        private array $secrets = [],
    ) {}

    /**
     * @param  array<string, string>  $secrets
     */
    public function withSecrets(array $secrets): self
    {
        $this->secrets = $secrets;

        return $this;
    }

    public function makeUnavailable(string $reason = 'faked outage'): self
    {
        $this->unavailable = SourceUnavailable::network('fake', $reason);

        return $this;
    }

    public function makeUnauthenticated(): self
    {
        $this->unauthenticated = AuthenticationFailed::rejected(401, 'dp.st.****fake', 'the fake');

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function secrets(?SyncOptions $options = null): array
    {
        $this->guard();

        return $this->secrets;
    }

    public function sync(?SyncOptions $options = null): SyncResult
    {
        $this->guard();

        $options ??= new SyncOptions;
        $this->syncs[] = $options;

        return new SyncResult(
            written: ! $options->dryRun,
            path: $options->path ?? 'fake://.env',
            unchanged: false,
            diff: Diff::between([], $this->secrets),
            secretCount: count($this->secrets),
            driver: 'fake',
        );
    }

    public function dryRun(?SyncOptions $options = null): SyncResult
    {
        return $this->sync(($options ?? new SyncOptions)->withDryRun());
    }

    public function profile(?string $profile): self
    {
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function rawConfig(): array
    {
        return [];
    }

    private function guard(): void
    {
        if ($this->unauthenticated !== null) {
            throw $this->unauthenticated;
        }

        if ($this->unavailable !== null) {
            throw $this->unavailable;
        }
    }

    public function assertSynced(): void
    {
        Assert::assertNotEmpty($this->syncs, 'Expected a Doppler sync, but none happened.');
    }

    public function assertNotSynced(): void
    {
        Assert::assertEmpty($this->syncs, 'Expected no Doppler sync, but one happened.');
    }

    public function assertSyncCount(int $expected): void
    {
        Assert::assertCount(
            $expected,
            $this->syncs,
            sprintf('Expected %d Doppler sync(s), saw %d.', $expected, count($this->syncs)),
        );
    }

    /**
     * @return list<SyncOptions>
     */
    public function syncs(): array
    {
        return $this->syncs;
    }
}
