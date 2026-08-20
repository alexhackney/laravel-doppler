<?php

declare(strict_types=1);

use AlexHackney\Doppler\Contracts\Doppler as DopplerContract;
use AlexHackney\Doppler\Exceptions\RateLimited;
use AlexHackney\Doppler\Exceptions\SourceUnavailable;
use AlexHackney\Doppler\Support\ExitCode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The soft-fail matrix from plan section 4.10.
 *
 * The negative cases matter more than the positive one. Soft-fail exists so a deploy
 * script can be a bare `php artisan env:sync` rather than `env:sync || true`, and the
 * entire value of that is in what it refuses to swallow. Every row below that ends in a
 * non-zero exit is a row where `|| true` would have taken production down quietly.
 */
beforeEach(function () {
    $this->target = $this->path('.env');

    config()->set('doppler.token.files', [$this->file('.token', 'dp.st.prd.abcdefghijklmnop')]);
    config()->set('doppler.target.path', $this->target);
    config()->set('doppler.api.base_url', 'https://api.doppler.test/v3');
    config()->set('doppler.api.retries', 0);
    config()->set('doppler.hooks', []);
    config()->set('doppler.soft_fail', true);
});

function unreachable(): void
{
    Http::fake(['*' => Http::response([], 500)]);
}

describe('soft-fail applies', function () {
    it('keeps a valid existing file and exits 0 when the source is unreachable', function () {
        file_put_contents($this->target, "APP_KEY='base64:existing'\nDB_PASSWORD='hunter2'\n");
        $before = file_get_contents($this->target);

        unreachable();

        $this->artisan('env:sync')->assertExitCode(ExitCode::Success->value);

        // The kept file must be byte-identical. A soft-fail that rewrites anything is not
        // a soft-fail.
        expect(file_get_contents($this->target))->toBe($before);
    });

    it('says so loudly rather than exiting quietly', function () {
        file_put_contents($this->target, "APP_KEY='base64:existing'\n");
        unreachable();

        $this->artisan('env:sync')
            ->expectsOutputToContain('DOPPLER UNREACHABLE')
            ->expectsOutputToContain('THE EXISTING ENVIRONMENT FILE WAS KEPT')
            ->assertExitCode(ExitCode::Success->value);
    });

    it('logs at error level so it reaches Sentry, not only the deploy output', function () {
        file_put_contents($this->target, "APP_KEY='base64:existing'\n");
        unreachable();

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                expect($message)->toContain('kept the existing environment file');
                expect($context)->toHaveKey('file_age_seconds');
                expect($context)->toHaveKey('reason');

                return true;
            });

        $this->artisan('env:sync')->assertExitCode(ExitCode::Success->value);
    });

    it('applies to a rate limit as well as an outage', function () {
        file_put_contents($this->target, "APP_KEY='base64:existing'\n");
        Http::fake(['*' => Http::response([], 429)]);

        $this->artisan('env:sync')->assertExitCode(ExitCode::Success->value);
    });

    it('runs no hooks, because nothing changed', function () {
        file_put_contents($this->target, "APP_KEY='base64:existing'\n");
        config()->set('doppler.hooks', ['artisan:this-command-does-not-exist']);

        unreachable();

        // A hook failure would surface as exit 7. Exit 0 proves none ran.
        $this->artisan('env:sync')->assertExitCode(ExitCode::Success->value);
    });
});

describe('soft-fail must not apply', function () {
    it('hard fails when there is no existing file to keep', function () {
        // The worst outcome available. On a fresh box, `env:sync || true` swallows this,
        // config:cache then bakes a blank configuration, auth middleware fails closed, and
        // every request 503s while the configuration still looks correct.
        expect(file_exists($this->target))->toBeFalse();

        unreachable();

        $this->artisan('env:sync')
            ->expectsOutputToContain('there is nothing to keep')
            ->assertExitCode(ExitCode::SourceUnavailable->value);
    });

    it('hard fails when the existing file is empty', function () {
        file_put_contents($this->target, '');

        unreachable();

        $this->artisan('env:sync')->assertExitCode(ExitCode::SourceUnavailable->value);
    });

    it('hard fails when the existing file is only whitespace', function () {
        file_put_contents($this->target, "\n\n   \n");

        unreachable();

        $this->artisan('env:sync')->assertExitCode(ExitCode::SourceUnavailable->value);
    });

    it('hard fails when the existing file is missing a newly required key', function () {
        // This is the case where "it probably has not changed" is wrong, and it is the
        // reason validating the kept file is what makes soft-fail safe rather than merely
        // convenient. Code reading NEW_API_KEY, deployed against a file that predates it,
        // boots fine and fails later, somewhere else, quietly.
        file_put_contents($this->target, "APP_KEY='base64:existing'\n");

        config()->set('doppler.validate.required', [
            'NEW_API_KEY' => 'the billing client throws on first use',
        ]);

        unreachable();

        // One assertion per run: Laravel consumes the whole matched output block, and the
        // refusal message is emitted as a single multi-line block.
        $this->artisan('env:sync')
            ->expectsOutputToContain('NEW_API_KEY')
            ->assertExitCode(ExitCode::SourceUnavailable->value);
    });

    it('explains why keeping the file was not safe', function () {
        file_put_contents($this->target, "APP_KEY='base64:existing'\n");

        config()->set('doppler.validate.required', [
            'NEW_API_KEY' => 'the billing client throws on first use',
        ]);

        unreachable();

        $this->artisan('env:sync')
            ->expectsOutputToContain('does not pass validation')
            ->assertExitCode(ExitCode::SourceUnavailable->value);
    });

    it('surfaces the consequence text when refusing to keep a file', function () {
        file_put_contents($this->target, "APP_KEY='base64:existing'\n");

        config()->set('doppler.validate.required', [
            'INTERNAL_SERVICE_TOKEN' => 'the auth middleware fails closed with 503',
        ]);

        unreachable();

        $this->artisan('env:sync')
            ->expectsOutputToContain('the auth middleware fails closed with 503')
            ->assertExitCode(ExitCode::SourceUnavailable->value);
    });

    it('never soft-fails an authentication failure', function () {
        // A revoked token is a provisioning problem that will not fix itself. Soft-failing
        // it means every subsequent deploy silently runs on an ever-staler file.
        file_put_contents($this->target, "APP_KEY='base64:existing'\n");

        Http::fake(['*' => Http::response([], 401)]);

        $this->artisan('env:sync')->assertExitCode(ExitCode::AuthenticationFailed->value);
    });

    it('never soft-fails a validation failure on a successful download', function () {
        // Doppler answered. The answer was wrong. That is a defect somebody introduced.
        file_put_contents($this->target, "APP_KEY='base64:existing'\n");

        Http::fake(['*' => Http::response(['APP_KEY' => 'base64:fresh', 'BROKEN' => ''])]);

        config()->set('doppler.validate.required', ['BROKEN' => 'something depends on it']);

        $this->artisan('env:sync')->assertExitCode(ExitCode::ValidationFailed->value);
    });

    it('never soft-fails when no token can be resolved', function () {
        config()->set('doppler.token.files', [$this->path('nonexistent.token')]);

        Http::fake();

        $this->artisan('env:sync')->assertExitCode(ExitCode::NoToken->value);
    });
});

describe('soft-fail configuration', function () {
    it('is off by default, so a general-purpose install never exits 0 after failing', function () {
        config()->set('doppler.soft_fail', false);

        file_put_contents($this->target, "APP_KEY='base64:existing'\n");
        unreachable();

        $this->artisan('env:sync')->assertExitCode(ExitCode::SourceUnavailable->value);
    });

    it('can be turned on for one invocation with --soft-fail', function () {
        config()->set('doppler.soft_fail', false);

        file_put_contents($this->target, "APP_KEY='base64:existing'\n");
        unreachable();

        $this->artisan('env:sync', ['--soft-fail' => true])->assertExitCode(ExitCode::Success->value);
    });

    it('can be overridden off with --no-soft-fail', function () {
        config()->set('doppler.soft_fail', true);

        file_put_contents($this->target, "APP_KEY='base64:existing'\n");
        unreachable();

        $this->artisan('env:sync', ['--no-soft-fail' => true])
            ->assertExitCode(ExitCode::SourceUnavailable->value);
    });
});

describe('a refusal keeps everything the original failure carried', function () {
    it('preserves the exception type, its typed data and the previous chain', function () {
        config()->set('doppler.soft_fail', true);

        Http::fake(['*' => Http::response([], 503)]);

        try {
            app(DopplerContract::class)->sync();
            $this->fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            // Rebuilding the exception to append the explanation used to null all of this.
            expect($e->status)->toBe(503)
                ->and($e->explanation())->toContain('nothing to keep')
                ->and($e->fullMessage())
                ->toContain($e->getMessage())
                ->toContain('nothing to keep');
        }
    });

    it('preserves retry-after through a rate-limited refusal', function () {
        config()->set('doppler.soft_fail', true);

        Http::fake(['*' => Http::response([], 429, ['retry-after' => '17'])]);

        try {
            app(DopplerContract::class)->sync();
            $this->fail('expected RateLimited');
        } catch (RateLimited $e) {
            expect($e->retryAfter)->toBe(17)
                ->and($e->explanation())->not->toBeNull();
        }
    });
});
