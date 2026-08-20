<?php

declare(strict_types=1);

namespace AlexHackney\Doppler;

use AlexHackney\Doppler\Commands\DiffCommand;
use AlexHackney\Doppler\Commands\DoctorCommand;
use AlexHackney\Doppler\Commands\SnapshotCommand;
use AlexHackney\Doppler\Commands\SyncCommand;
use AlexHackney\Doppler\Contracts\Doppler;
use Illuminate\Support\ServiceProvider;

final class DopplerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/doppler.php', 'doppler');

        // The CONTRACT is the binding that matters: it is the facade's accessor, so it is
        // what Doppler::fake() swaps. The concrete class is aliased onto it so an app that
        // already injects DopplerManager keeps working — but such an injection cannot be
        // intercepted by fake(), which is why the contract is what this package's own
        // commands type-hint.
        $this->app->singleton(Doppler::class, function ($app): DopplerManager {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('doppler', []);

            return new DopplerManager($app, $config);
        });

        $this->app->alias(Doppler::class, DopplerManager::class);
        $this->app->alias(Doppler::class, 'doppler');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/doppler.php' => $this->app->configPath('doppler.php'),
            ], 'doppler-config');

            $this->commands([
                SyncCommand::class,
                DiffCommand::class,
                DoctorCommand::class,
                SnapshotCommand::class,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [Doppler::class, DopplerManager::class, 'doppler'];
    }
}
