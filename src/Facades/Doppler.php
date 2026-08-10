<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Facades;

use AlexHackney\Doppler\DopplerManager;
use AlexHackney\Doppler\SyncOptions;
use AlexHackney\Doppler\Testing\Fake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \AlexHackney\Doppler\Support\SyncResult sync(?SyncOptions $options = null)
 * @method static \AlexHackney\Doppler\Support\SyncResult dryRun(?SyncOptions $options = null)
 * @method static array<string, string> secrets(?SyncOptions $options = null)
 * @method static DopplerManager profile(?string $profile)
 * @method static array<string, mixed> rawConfig()
 *
 * @see DopplerManager
 */
final class Doppler extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DopplerManager::class;
    }

    /**
     * Swap the manager for a fake, so a consuming app never has to stub HTTP by hand.
     *
     * @param  array<string, string>  $secrets
     */
    public static function fake(array $secrets = []): Fake
    {
        $fake = new Fake($secrets);

        self::swap($fake);

        return $fake;
    }

    /**
     * Fake a source that cannot be reached, for exercising soft-fail paths.
     */
    public static function fakeUnavailable(string $reason = 'faked outage'): Fake
    {
        $fake = (new Fake)->makeUnavailable($reason);

        self::swap($fake);

        return $fake;
    }

    /**
     * Fake a rejected credential.
     */
    public static function fakeUnauthenticated(): Fake
    {
        $fake = (new Fake)->makeUnauthenticated();

        self::swap($fake);

        return $fake;
    }

    public static function assertSynced(): void
    {
        self::resolvedFake()->assertSynced();
    }

    public static function assertNotSynced(): void
    {
        self::resolvedFake()->assertNotSynced();
    }

    public static function assertSyncCount(int $expected): void
    {
        self::resolvedFake()->assertSyncCount($expected);
    }

    private static function resolvedFake(): Fake
    {
        $instance = self::getFacadeRoot();

        if (! $instance instanceof Fake) {
            throw new \RuntimeException('Doppler::fake() must be called before asserting on it.');
        }

        return $instance;
    }
}
