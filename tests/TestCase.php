<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Tests;

use AlexHackney\Doppler\DopplerServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * A scratch directory, unique per test, cleaned up afterwards.
     */
    protected string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/laravel-doppler-test-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0755, true);

        // No test may reach the network. A stray request is a test defect, not a warning.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->workspace);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [DopplerServiceProvider::class];
    }

    /**
     * Absolute path inside this test's scratch directory.
     */
    protected function path(string $relative = ''): string
    {
        return rtrim($this->workspace.'/'.ltrim($relative, '/'), '/');
    }

    /**
     * Write a file inside the scratch directory and return its absolute path.
     */
    protected function file(string $relative, string $contents): string
    {
        $path = $this->path($relative);

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = array_diff(scandir($directory) ?: [], ['.', '..']);

        foreach ($entries as $entry) {
            $path = $directory.'/'.$entry;

            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
