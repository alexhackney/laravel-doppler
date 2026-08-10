<?php

declare(strict_types=1);

use AlexHackney\Doppler\Exceptions\HookFailed;
use AlexHackney\Doppler\Hooks\HookRunner;
use AlexHackney\Doppler\Support\ExitCode;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->target = $this->path('.env');

    config()->set('doppler.token.files', [$this->file('.token', 'dp.st.prd.abcdefghijklmnop')]);
    config()->set('doppler.target.path', $this->target);
    config()->set('doppler.api.base_url', 'https://api.doppler.test/v3');
    config()->set('doppler.api.retries', 0);
    config()->set('doppler.preserve', []);

    Http::fake(['*' => Http::response(['APP_KEY' => 'base64:abc'])]);
});

function runner(): HookRunner
{
    return new HookRunner(app(ConsoleKernel::class), app(ProcessFactory::class));
}

describe('ordering', function () {
    it('always puts config:clear first, whatever order it is configured in', function () {
        // A later hook that boots the app would otherwise read a stale cached config.
        $ordered = runner()->order([
            'artisan:queue:restart',
            'artisan:config:clear',
            'shell:echo done',
        ]);

        expect($ordered[0])->toBe('artisan:config:clear');
    });

    it('preserves the configured order for everything else', function () {
        $ordered = runner()->order([
            'artisan:queue:restart',
            'artisan:config:clear',
            'shell:echo one',
            'shell:echo two',
        ]);

        expect($ordered)->toBe([
            'artisan:config:clear',
            'artisan:queue:restart',
            'shell:echo one',
            'shell:echo two',
        ]);
    });

    it('runs hooks in order after a successful write', function () {
        Process::fake(['*' => Process::result('ok')]);

        config()->set('doppler.hooks', ['shell:echo first', 'shell:echo second']);

        $this->artisan('env:sync')->assertExitCode(ExitCode::Success->value);

        Process::assertRanTimes(fn ($p) => str_contains(
            is_array($p->command) ? implode(' ', $p->command) : $p->command,
            'echo first',
        ), 1);
    });
});

describe('config:cache gating', function () {
    it('aborts every remaining hook when config:cache fails, and exits 7', function () {
        // A cache built from a bad file bakes in blank secrets while the configuration
        // still looks correct, and an auth middleware that fails closed on a blank secret
        // then 503s every request. Restarting workers on top of that spreads the damage.
        Process::fake(['*' => Process::result('ok')]);

        config()->set('doppler.hooks', [
            'artisan:config:cache',
            'shell:echo should-not-run',
        ]);

        // Force config:cache to fail by pointing the app at an unwritable cache path.
        $this->app->useBootstrapPath($this->path('nonexistent-bootstrap'));

        $this->artisan('env:sync')->assertExitCode(ExitCode::HookFailed->value);

        Process::assertNotRan(fn ($p) => str_contains(
            is_array($p->command) ? implode(' ', $p->command) : $p->command,
            'should-not-run',
        ));
    });

    it('explains why the remaining hooks were skipped', function () {
        $exception = HookFailed::make('artisan:config:cache', 1, true);

        expect($exception->getMessage())->toContain('Remaining hooks were skipped');
        expect($exception->getMessage())->toContain('bakes in blank secrets');
        expect($exception->exitCode())->toBe(ExitCode::HookFailed);
    });

    it('reports that the file was written even though a hook failed', function () {
        $exception = HookFailed::make('shell:reload', 1, false);

        expect($exception->getMessage())->toContain('The file was written');
    });
});

describe('hook execution', function () {
    it('runs an artisan hook through the console kernel', function () {
        config()->set('doppler.hooks', ['artisan:config:clear']);

        $this->artisan('env:sync')->assertExitCode(ExitCode::Success->value);
    });

    it('runs a shell hook through the process factory', function () {
        Process::fake(['*' => Process::result('reloaded')]);

        config()->set('doppler.hooks', ['shell:service php-fpm reload']);

        $this->artisan('env:sync')->assertExitCode(ExitCode::Success->value);

        Process::assertRan(fn ($p) => str_contains(
            is_array($p->command) ? implode(' ', $p->command) : $p->command,
            'php-fpm reload',
        ));
    });

    it('fails on an unrecognised hook prefix rather than ignoring it', function () {
        config()->set('doppler.hooks', ['config:clear']);

        $this->artisan('env:sync')->assertExitCode(ExitCode::HookFailed->value);
    });

    it('exits 7 when a shell hook fails', function () {
        Process::fake(['*' => Process::result('', 'boom', 1)]);

        config()->set('doppler.hooks', ['shell:false']);

        $this->artisan('env:sync')->assertExitCode(ExitCode::HookFailed->value);
    });

    it('still wrote the file when a hook failed', function () {
        Process::fake(['*' => Process::result('', 'boom', 1)]);

        config()->set('doppler.hooks', ['shell:false']);

        $this->artisan('env:sync')->assertExitCode(ExitCode::HookFailed->value);

        expect(file_get_contents($this->target))->toBe("APP_KEY='base64:abc'\n");
    });

    it('skips every hook under --no-hooks', function () {
        Process::fake(['*' => Process::result('', 'boom', 1)]);

        config()->set('doppler.hooks', ['shell:false']);

        $this->artisan('env:sync', ['--no-hooks' => true])->assertExitCode(ExitCode::Success->value);

        Process::assertNothingRan();
    });

    it('runs no hooks when nothing changed', function () {
        Process::fake(['*' => Process::result('', 'boom', 1)]);

        config()->set('doppler.hooks', []);
        $this->artisan('env:sync')->assertExitCode(0);

        config()->set('doppler.hooks', ['shell:false']);
        $this->artisan('env:sync')->assertExitCode(ExitCode::Success->value);

        Process::assertNothingRan();
    });

    it('runs no hooks on a dry run', function () {
        Process::fake(['*' => Process::result('', 'boom', 1)]);

        config()->set('doppler.hooks', ['shell:false']);

        $this->artisan('env:sync', ['--dry-run' => true])->assertExitCode(ExitCode::DriftDetected->value);

        Process::assertNothingRan();
    });
});
