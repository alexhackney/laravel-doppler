<?php

declare(strict_types=1);

use AlexHackney\Doppler\Support\Diff;
use AlexHackney\Doppler\Support\ExitCode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->target = $this->path('.env');
    $this->tokenFile = $this->file('.token', 'dp.st.prd.abcdefghijklmnop');

    config()->set('doppler.token.files', [$this->tokenFile]);
    config()->set('doppler.target.path', $this->target);
    config()->set('doppler.api.base_url', 'https://api.doppler.test/v3');
    config()->set('doppler.api.retries', 0);
    config()->set('doppler.hooks', []);
    config()->set('doppler.preserve', []);
});

describe('env:diff', function () {
    it('exits 0 when the file matches Doppler', function () {
        file_put_contents($this->target, "APP_KEY='same'\n");

        Http::fake(['*' => Http::response(['APP_KEY' => 'same'])]);

        $this->artisan('env:diff')->assertExitCode(ExitCode::Success->value);
    });

    it('exits 10 on drift, so a scheduled run is actionable without parsing output', function () {
        file_put_contents($this->target, "APP_KEY='old'\n");

        Http::fake(['*' => Http::response(['APP_KEY' => 'new'])]);

        $this->artisan('env:diff')->assertExitCode(ExitCode::DriftDetected->value);
    });

    it('detects a hand-edited box', function () {
        file_put_contents($this->target, "APP_KEY='same'\nHAND_EDITED='oops'\n");

        Http::fake(['*' => Http::response(['APP_KEY' => 'same'])]);

        $this->artisan('env:diff')
            ->expectsOutputToContain('HAND_EDITED')
            ->assertExitCode(ExitCode::DriftDetected->value);
    });

    it('detects a key Doppler added that the box has not picked up', function () {
        file_put_contents($this->target, "APP_KEY='same'\n");

        Http::fake(['*' => Http::response(['APP_KEY' => 'same', 'NEW_KEY' => 'value'])]);

        $this->artisan('env:diff')
            ->expectsOutputToContain('NEW_KEY')
            ->assertExitCode(ExitCode::DriftDetected->value);
    });

    it('never writes anything', function () {
        file_put_contents($this->target, "APP_KEY='old'\n");

        Http::fake(['*' => Http::response(['APP_KEY' => 'new'])]);

        $this->artisan('env:diff')->assertExitCode(ExitCode::DriftDetected->value);

        expect(file_get_contents($this->target))->toBe("APP_KEY='old'\n");
    });

    it('reports the source failure rather than claiming no drift', function () {
        file_put_contents($this->target, "APP_KEY='old'\n");

        Http::fake(['*' => Http::response([], 500)]);

        $this->artisan('env:diff')->assertExitCode(ExitCode::SourceUnavailable->value);
    });

    it('names every key a validation rule objected to', function () {
        // This is the command you schedule, so it is the one where the detail matters most:
        // a count with no keys leaves an operator with nothing to act on but a rerun.
        file_put_contents($this->target, "APP_KEY='old'\n");

        Http::fake(['*' => Http::response(['APP_KEY' => 'new', 'INTERNAL_SERVICE_TOKEN' => '', 'CALLBACK_URL' => ''])]);

        config()->set('doppler.validate.required', [
            'INTERNAL_SERVICE_TOKEN' => 'the auth middleware fails closed with 503',
            'CALLBACK_URL' => 'the webhook receiver 404s',
        ]);

        $exitCode = Artisan::call('env:diff');
        $output = Artisan::output();

        expect($exitCode)->toBe(ExitCode::ValidationFailed->value);
        expect($output)->toContain('INTERNAL_SERVICE_TOKEN');
        expect($output)->toContain('the auth middleware fails closed with 503');
        expect($output)->toContain('CALLBACK_URL');
        expect($output)->toContain('the webhook receiver 404s');
    });

    it('does not offer --force, which it does not have', function () {
        file_put_contents($this->target, "APP_KEY='old'\n");

        Http::fake(['*' => Http::response(['SECRET_KEY' => 'changeme'])]);

        Artisan::call('env:diff');

        expect(Artisan::output())->not->toContain('--force');
    });
});

describe('diff computation', function () {
    it('classifies added, removed, changed and unchanged', function () {
        $diff = Diff::between(
            ['SAME' => '1', 'CHANGED' => 'old', 'REMOVED' => 'x'],
            ['SAME' => '1', 'CHANGED' => 'new', 'ADDED' => 'y'],
        );

        expect($diff->unchanged)->toBe(['SAME']);
        expect($diff->changed)->toBe(['CHANGED']);
        expect($diff->added)->toBe(['ADDED']);
        expect($diff->removed)->toBe(['REMOVED']);
        expect($diff->driftCount())->toBe(3);
        expect($diff->hasDrift())->toBeTrue();
    });

    it('reports no drift for identical maps', function () {
        $diff = Diff::between(['A' => '1'], ['A' => '1']);

        expect($diff->hasDrift())->toBeFalse();
        expect($diff->driftCount())->toBe(0);
    });

    it('treats an empty value as different from an absent key', function () {
        // Doppler emits held-but-unset keys as empty strings, so this distinction is the
        // difference between "nothing changed" and "somebody cleared a secret".
        $diff = Diff::between(['A' => 'x'], ['A' => '']);

        expect($diff->changed)->toBe(['A']);
    });

    it('fingerprints a value without revealing it', function () {
        $fingerprint = Diff::fingerprint('hunter2');

        expect($fingerprint)->toContain('7 bytes');
        expect($fingerprint)->not->toContain('hunter2');
    });
});

describe('env:doctor', function () {
    it('reports the resolved token, redacted', function () {
        $this->artisan('env:doctor')
            ->expectsOutputToContain('dp.st.****mnop')
            ->assertExitCode(ExitCode::Success->value);
    });

    it('makes no network call at all', function () {
        Http::fake();

        $this->artisan('env:doctor');

        Http::assertNothingSent();
    });

    it('reports when no token can be found', function () {
        config()->set('doppler.token.files', [$this->path('missing.token')]);

        $this->artisan('env:doctor')->assertExitCode(ExitCode::ValidationFailed->value);
    });

    it('warns when a personal token has no project or config', function () {
        config()->set('doppler.token.files', [$this->file('.token', 'dp.pt.personaltokenbody')]);

        $this->artisan('env:doctor')
            ->expectsOutputToContain('--project and --config')
            ->assertExitCode(ExitCode::ValidationFailed->value);
    });

    it('warns when the token file is group or world readable', function () {
        chmod($this->tokenFile, 0644);

        $this->artisan('env:doctor')
            ->expectsOutputToContain('readable by group or others');
    })->skipOnWindows();

    it('warns when hooks are configured but unrecognised', function () {
        config()->set('doppler.hooks', ['config:clear']);

        $this->artisan('env:doctor')->expectsOutputToContain('UNRECOGNISED');
    });

    it('warns when no hooks are configured at all', function () {
        config()->set('doppler.hooks', []);

        $this->artisan('env:doctor')->expectsOutputToContain('will not pick up new values');
    });

    it('notes when the target file does not exist yet', function () {
        $this->artisan('env:doctor')
            ->expectsOutputToContain('Soft-fail cannot protect a first sync');
    });

    it('reports snapshot age when the fallback is enabled', function () {
        config()->set('doppler.fallback.enabled', true);
        config()->set('doppler.fallback.path', $this->path('snapshot.enc'));

        $this->artisan('env:doctor')->expectsOutputToContain('Run env:snapshot');
    });
});
