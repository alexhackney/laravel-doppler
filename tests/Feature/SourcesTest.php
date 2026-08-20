<?php

declare(strict_types=1);

use AlexHackney\Doppler\Credentials\Credential;
use AlexHackney\Doppler\Exceptions\AuthenticationFailed;
use AlexHackney\Doppler\Exceptions\DopplerException;
use AlexHackney\Doppler\Exceptions\RateLimited;
use AlexHackney\Doppler\Exceptions\RequestRejected;
use AlexHackney\Doppler\Exceptions\SourceUnavailable;
use AlexHackney\Doppler\Sources\ApiSource;
use AlexHackney\Doppler\Sources\CliSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function apiSource(int $retries = 0): ApiSource
{
    return new ApiSource(
        http: app(HttpFactory::class),
        baseUrl: 'https://api.doppler.test/v3',
        timeout: 5,
        retries: $retries,
        retryDelayMs: 0,
    );
}

function serviceToken(): Credential
{
    return Credential::make('dp.st.prd.abcdefghijklmnop', '/etc/doppler/.token');
}

describe('api source', function () {
    it('returns the secrets on a 200', function () {
        Http::fake([
            '*' => Http::response(['APP_KEY' => 'base64:abc', 'DB_PASSWORD' => 'hunter2']),
        ]);

        expect(apiSource()->fetch(serviceToken()))
            ->toBe(['APP_KEY' => 'base64:abc', 'DB_PASSWORD' => 'hunter2']);
    });

    it('requests JSON, never the env format', function () {
        Http::fake(['*' => Http::response([])]);

        apiSource()->fetch(serviceToken());

        Http::assertSent(function ($request) {
            expect($request->url())->toContain('format=json');
            expect($request->url())->not->toContain('env-no-quotes');

            return true;
        });
    });

    it('sends the token as a bearer credential', function () {
        Http::fake(['*' => Http::response([])]);

        apiSource()->fetch(serviceToken());

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer dp.st.prd.abcdefghijklmnop'));
    });

    it('omits project and config for a pre-scoped service token', function () {
        Http::fake(['*' => Http::response([])]);

        apiSource()->fetch(serviceToken());

        Http::assertSent(function ($request) {
            expect($request->url())->not->toContain('project=');

            return true;
        });
    });

    it('sends project and config when scoped', function () {
        Http::fake(['*' => Http::response([])]);

        apiSource()->fetch(Credential::make('dp.pt.abcdefghijklmnop', 'test', 'tsp', 'prd'));

        Http::assertSent(function ($request) {
            expect($request->url())->toContain('project=tsp');
            expect($request->url())->toContain('config=prd');

            return true;
        });
    });

    it('refuses a personal token with no project or config, before making a request', function () {
        Http::fake();

        expect(fn () => apiSource()->fetch(Credential::make('dp.pt.abcdefghijklmnop', 'test')))
            ->toThrow(AuthenticationFailed::class, '--project and --config are required');

        Http::assertNothingSent();
    });

    it('treats an empty value as empty rather than absent', function () {
        // Doppler renders a key it holds but has not set as an empty string. This is why
        // the required rule tests for blankness rather than presence.
        Http::fake(['*' => Http::response(['SET' => 'x', 'HELD_BUT_UNSET' => ''])]);

        expect(apiSource()->fetch(serviceToken()))->toBe(['SET' => 'x', 'HELD_BUT_UNSET' => '']);
    });

    it('normalises null, numeric and boolean values to strings', function () {
        Http::fake(['*' => Http::response(['NULLED' => null, 'PORT' => 5432, 'FLAG' => true])]);

        // FLAG is "true", not "1". (string) false is "", which would read as a
        // held-but-unset key and trip the required rule on a legitimately false value —
        // and "true"/"false" are exactly what env() converts back into a real bool.
        expect(apiSource()->fetch(serviceToken()))
            ->toBe(['NULLED' => '', 'PORT' => '5432', 'FLAG' => 'true']);
    });

    it('does not collapse a false value into blankness', function () {
        Http::fake(['*' => Http::response(['FEATURE_X' => false])]);

        expect(apiSource()->fetch(serviceToken()))->toBe(['FEATURE_X' => 'false']);
    });

    it('identifies itself to Doppler, so unexpected traffic has a name', function () {
        Http::fake(['*' => Http::response([])]);

        apiSource()->fetch(serviceToken());

        Http::assertSent(fn ($request) => str_contains(
            $request->header('User-Agent')[0] ?? '',
            'alexhackney/laravel-doppler',
        ));
    });

    it('abandons a 429 whose Retry-After is longer than a deploy should wait', function () {
        Http::fake(['*' => Http::response([], 429, ['retry-after' => '600'])]);

        try {
            apiSource()->fetch(serviceToken());
            $this->fail('expected RateLimited');
        } catch (RateLimited $e) {
            // Reported in full on the exception, so a scheduler can act on it, rather than
            // slept through inside a deploy that would look hung.
            expect($e->retryAfter)->toBe(600);
        }
    });

    it('maps each failure status to its own exception and exit code', function (int $status, string $exception, int $exitCode) {
        Http::fake(['*' => Http::response(['messages' => ['nope']], $status)]);

        try {
            apiSource()->fetch(serviceToken());
            $this->fail('expected '.$exception);
        } catch (DopplerException $e) {
            // DopplerException, not Throwable: exitCode() is the contract under test, and
            // catching wider meant asserting a method the caught type does not have.
            expect($e::class)->toBe($exception);
            expect($e->exitCode()->value)->toBe($exitCode);
        }
    })->with([
        'unauthorized' => [401, AuthenticationFailed::class, 4],
        'forbidden' => [403, AuthenticationFailed::class, 4],
        // 404 and 422 are NOT exit 4. The credential was accepted; the request was not.
        // Reporting them as authentication failures sent an operator to rotate a token
        // that was fine, and told a monitor branching on exit codes the same lie.
        'not found' => [404, RequestRejected::class, 8],
        'unprocessable' => [422, RequestRejected::class, 8],
        'rate limited' => [429, RateLimited::class, 5],
        'server error' => [500, SourceUnavailable::class, 3],
        'bad gateway' => [502, SourceUnavailable::class, 3],
    ]);

    it('says the token was accepted when the request itself was rejected', function () {
        Http::fake(['*' => Http::response([], 404)]);

        try {
            apiSource()->fetch(serviceToken());
            $this->fail('expected RequestRejected');
        } catch (RequestRejected $e) {
            expect($e->getMessage())->toContain('The token itself was accepted')
                ->and($e->status)->toBe(404)
                ->and($e->isSoftFailable())->toBeFalse();
        }
    });

    it('reports retry-after when rate limited', function () {
        Http::fake(['*' => Http::response([], 429, ['retry-after' => '42'])]);

        try {
            apiSource()->fetch(serviceToken());
            $this->fail('expected RateLimited');
        } catch (RateLimited $e) {
            expect($e->retryAfter)->toBe(42);
            expect($e->getMessage())->toContain('42 seconds');
        }
    });

    it('marks a transient failure as soft-failable', function (int $status, bool $softFailable) {
        Http::fake(['*' => Http::response([], $status)]);

        try {
            apiSource()->fetch(serviceToken());
            $this->fail('expected a failure for HTTP '.$status);
        } catch (DopplerException $e) {
            expect($e->isSoftFailable())->toBe(
                $softFailable,
                sprintf('HTTP %d soft-failable should be %s', $status, $softFailable ? 'true' : 'false'),
            );
        }
    })->with([
        'server error is weather' => [500, true],
        'rate limit is weather' => [429, true],
        'a revoked token is a defect' => [401, false],
        'a forbidden token is a defect' => [403, false],
        'a bad project name is a defect' => [404, false],
    ]);

    it('retries a 500 and succeeds when the retry works', function () {
        Http::fakeSequence()
            ->push([], 500)
            ->push(['APP_KEY' => 'recovered'], 200);

        expect(apiSource(retries: 2)->fetch(serviceToken()))->toBe(['APP_KEY' => 'recovered']);
    });

    it('never retries a 401, because a revoked token will not fix itself', function () {
        Http::fake(['*' => Http::response([], 401)]);

        expect(fn () => apiSource(retries: 3)->fetch(serviceToken()))->toThrow(AuthenticationFailed::class);

        Http::assertSentCount(1);
    });

    it('retries a 429 up to the configured limit', function () {
        Http::fake(['*' => Http::response([], 429)]);

        expect(fn () => apiSource(retries: 2)->fetch(serviceToken()))->toThrow(RateLimited::class);

        Http::assertSentCount(3);
    });

    it('reports a connection failure as unavailable', function () {
        Http::fake(fn () => throw new ConnectionException('dns failure'));

        expect(fn () => apiSource()->fetch(serviceToken()))
            ->toThrow(SourceUnavailable::class, 'dns failure');
    });

    it('reports a non-object body as malformed', function () {
        Http::fake(['*' => Http::response('"just a string"', 200, ['Content-Type' => 'application/json'])]);

        expect(fn () => apiSource()->fetch(serviceToken()))
            ->toThrow(SourceUnavailable::class, 'key/value pairs');
    });

    it('never puts the raw token in an exception message', function () {
        Http::fake(['*' => Http::response([], 401)]);

        try {
            apiSource()->fetch(serviceToken());
            $this->fail('expected AuthenticationFailed');
        } catch (AuthenticationFailed $e) {
            expect($e->getMessage())->not->toContain('abcdefghijklmnop');
            expect($e->getMessage())->toContain('dp.st.****mnop');
        }
    });
});

describe('cli source', function () {
    it('downloads JSON with --no-file', function () {
        Process::fake([
            '*' => Process::result(json_encode(['APP_KEY' => 'from-cli'], JSON_THROW_ON_ERROR)),
        ]);

        $source = new CliSource(app(ProcessFactory::class), 'doppler', 5);

        expect($source->fetch(serviceToken()))->toBe(['APP_KEY' => 'from-cli']);

        Process::assertRan(function ($process) {
            expect($process->command)->toContain('--no-file');
            expect($process->command)->toContain('json');

            return true;
        });
    });

    it('passes the token by environment, never as an argument visible in ps', function () {
        Process::fake(['*' => Process::result('{}')]);

        (new CliSource(app(ProcessFactory::class)))->fetch(serviceToken());

        Process::assertRan(function ($process) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            expect($command)->not->toContain('dp.st.prd.abcdefghijklmnop');

            return true;
        });
    });

    it('maps an auth error on stderr to AuthenticationFailed', function () {
        Process::fake(['*' => Process::result('', 'Invalid token provided', 1)]);

        expect(fn () => (new CliSource(app(ProcessFactory::class)))->fetch(serviceToken()))
            ->toThrow(AuthenticationFailed::class);
    });

    it('explains a missing binary and points at the api driver', function () {
        Process::fake(['*' => Process::result('', 'doppler: command not found', 127)]);

        expect(fn () => (new CliSource(app(ProcessFactory::class)))->fetch(serviceToken()))
            ->toThrow(SourceUnavailable::class, 'switch to the api driver');
    });

    it('reports unreadable output as malformed', function () {
        Process::fake(['*' => Process::result('not json at all')]);

        expect(fn () => (new CliSource(app(ProcessFactory::class)))->fetch(serviceToken()))
            ->toThrow(SourceUnavailable::class, 'did not return a JSON object');
    });
});
