<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Tests;

use AlexHackney\Doppler\DopplerServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;

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
     * Laravel's artisan() is typed `PendingCommand|int`, and the int branch only happens
     * after withoutMockingConsoleOutput(), which this suite never calls.
     *
     * Narrowing it here rather than stubbing Laravel's signature globally keeps the
     * override honest: the check is real, so the type is true rather than asserted. It also
     * lets every call site keep chaining ->assertExitCode() without a cast.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     */
    public function artisan($command, $parameters = []): PendingCommand
    {
        $pending = parent::artisan($command, $parameters);

        if (! $pending instanceof PendingCommand) {
            throw new RuntimeException(
                'artisan() returned an exit code rather than a PendingCommand, which means '.
                'console output mocking was disabled. Use Artisan::call() for that.',
            );
        }

        return $pending;
    }

    /**
     * Read a file that must exist, typed as the string it is.
     *
     * file_get_contents() is string|false, and every caller here is reading a file the test
     * just wrote. Failing loudly once beats casting at two dozen call sites.
     */
    protected function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('%s could not be read.', $path));
        }

        return $contents;
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
