<?php

declare(strict_types=1);

namespace AlexHackney\Doppler;

use AlexHackney\Doppler\Commands\DiffCommand;
use AlexHackney\Doppler\Commands\DoctorCommand;
use AlexHackney\Doppler\Commands\SnapshotCommand;
use AlexHackney\Doppler\Commands\SyncCommand;
use Illuminate\Support\ServiceProvider;

final class DopplerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/doppler.php', 'doppler');

        $this->app->singleton(DopplerManager::class, function ($app): DopplerManager {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('doppler', []);

            return new DopplerManager($app, $config);
        });

        $this->app->alias(DopplerManager::class, 'doppler');
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
        return [DopplerManager::class, 'doppler'];
    }
}
