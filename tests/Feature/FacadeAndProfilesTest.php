<?php

declare(strict_types=1);

use AlexHackney\Doppler\Contracts\Doppler as DopplerContract;
use AlexHackney\Doppler\DopplerManager;
use AlexHackney\Doppler\DopplerServiceProvider;
use AlexHackney\Doppler\Exceptions\AuthenticationFailed;
use AlexHackney\Doppler\Exceptions\SourceUnavailable;
use AlexHackney\Doppler\Facades\Doppler;
use AlexHackney\Doppler\Support\ExitCode;
use AlexHackney\Doppler\SyncOptions;
use AlexHackney\Doppler\Testing\Fake;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

beforeEach(function () {
    $this->target = $this->path('.env');

    config()->set('doppler.token.files', [$this->file('.token', 'dp.st.prd.abcdefghijklmnop')]);
    config()->set('doppler.target.path', $this->target);
    config()->set('doppler.api.base_url', 'https://api.doppler.test/v3');
    config()->set('doppler.api.retries', 0);
    config()->set('doppler.hooks', []);
    config()->set('doppler.preserve', []);
});

describe('programmatic api', function () {
    it('fetches secrets without touching any file', function () {
        Http::fake(['*' => Http::response(['APP_KEY' => 'value'])]);

        expect(Doppler::secrets())->toBe(['APP_KEY' => 'value']);
        expect(file_exists($this->target))->toBeFalse();
    });

    it('syncs and reports what changed', function () {
        file_put_contents($this->target, "OLD='1'\n");

        Http::fake(['*' => Http::response(['NEW' => '2'])]);

        $result = Doppler::sync(new SyncOptions(runHooks: false));

        expect($result->written)->toBeTrue();
        expect($result->successful())->toBeTrue();
        expect($result->diff)->not->toBeNull();
        expect($result->diff?->added)->toBe(['NEW']);
        expect($result->diff?->removed)->toBe(['OLD']);
    });

    it('dry runs without writing', function () {
        Http::fake(['*' => Http::response(['APP_KEY' => 'value'])]);

        $result = Doppler::dryRun();

        expect($result->written)->toBeFalse();
        expect(file_exists($this->target))->toBeFalse();
    });

    it('summarises a result in one line for logging', function () {
        Http::fake(['*' => Http::response(['A' => '1', 'B' => '2'])]);

        expect(Doppler::sync(new SyncOptions(runHooks: false))->summary())
            ->toContain('2 secrets');
    });
});

describe('the shipped fake', function () {
    it('lets a consuming app avoid stubbing HTTP by hand', function () {
        Doppler::fake(['APP_KEY' => 'faked']);

        expect(Doppler::secrets())->toBe(['APP_KEY' => 'faked']);
    });

    it('records syncs for assertion', function () {
        Doppler::fake(['APP_KEY' => 'faked']);

        Doppler::assertNotSynced();

        Doppler::sync();

        Doppler::assertSynced();
        Doppler::assertSyncCount(1);
    });

    it('can simulate an outage for exercising soft-fail paths', function () {
        Doppler::fakeUnavailable('the network is down');

        expect(fn () => Doppler::sync())->toThrow(SourceUnavailable::class, 'the network is down');
    });

    it('can simulate a rejected credential', function () {
        Doppler::fakeUnauthenticated();

        expect(fn () => Doppler::sync())
            ->toThrow(AuthenticationFailed::class);
    });

    it('makes no HTTP call at all', function () {
        Http::fake();

        Doppler::fake(['APP_KEY' => 'faked']);
        Doppler::sync();

        Http::assertNothingSent();
    });

    it('refuses to assert before fake() is called', function () {
        Doppler::assertSynced();
    })->throws(RuntimeException::class, 'must be called before asserting');

    // The regression this contract exists for. swap() rebinds the facade accessor, so an
    // accessor of DopplerManager made every container resolution of that type a TypeError
    // the moment a test faked — including this package's own commands, which take it as a
    // handle() argument. Testing the deploy path is the whole reason to ship a fake.
    it('survives an artisan command, which resolves the contract from the container', function () {
        config()->set('doppler.target.path', $this->path('.env'));
        config()->set('doppler.hooks', []);

        Doppler::fake(['APP_KEY' => 'base64:faked']);

        expect(Artisan::call('env:sync'))->toBe(ExitCode::Success->value);

        Doppler::assertSyncCount(1);
    });

    it('is injectable as the contract, which is what a consuming app should type-hint', function () {
        Doppler::fake(['APP_KEY' => 'base64:faked']);

        expect(app(DopplerContract::class))->toBeInstanceOf(Fake::class);
    });

    it('leaves the concrete manager resolvable, so an existing injection keeps working', function () {
        // Resolved through the container by name, so this proves the alias is wired rather
        // than restating a type PHPStan already knows.
        expect(app()->make(DopplerManager::class)::class)->toBe(DopplerManager::class);
    });

    it('reports drift-free diagnostics under a fake instead of crashing', function () {
        config()->set('doppler.target.path', $this->path('.env'));

        Doppler::fake(['APP_KEY' => 'base64:faked']);

        expect(Artisan::call('env:doctor'))->toBeIn([
            ExitCode::Success->value,
            ExitCode::ValidationFailed->value,
        ]);
    });
});

describe('profiles', function () {
    it('merges a profile over the base configuration', function () {
        config()->set('doppler.profiles.qw', [
            'hooks' => ['artisan:horizon:terminate'],
        ]);

        $config = app(DopplerManager::class)->resolveConfig(new SyncOptions(profile: 'qw'));

        expect($config['hooks'])->toBe(['artisan:horizon:terminate']);
        // Untouched keys survive the merge.
        expect($config['target']['path'])->toBe($this->target);
    });

    it('merges nested keys without discarding siblings', function () {
        config()->set('doppler.profiles.qw', [
            'token' => ['files' => ['/etc/doppler/qw.token']],
        ]);

        $config = app(DopplerManager::class)->resolveConfig(new SyncOptions(profile: 'qw'));

        expect($config['token']['files'])->toBe(['/etc/doppler/qw.token']);
        expect($config['token']['env_var'])->toBe('DOPPLER_TOKEN');
    });

    it('uses a profile token file end to end', function () {
        $profileToken = $this->file('qw.token', 'dp.st.prd.zzzzzzzzzzzzzzzz');

        config()->set('doppler.profiles.qw', [
            'token' => ['files' => [$profileToken]],
        ]);

        Http::fake(['*' => Http::response(['APP_KEY' => 'value'])]);

        $this->artisan('env:sync', ['--profile' => 'qw'])->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer dp.st.prd.zzzzzzzzzzzzzzzz'));
    });

    it('names the defined profiles when given an unknown one', function () {
        config()->set('doppler.profiles.qw', []);

        expect(fn () => app(DopplerManager::class)->resolveConfig(new SyncOptions(profile: 'web')))
            ->toThrow(InvalidArgumentException::class, 'Defined profiles: qw');
    });
});

describe('service registration', function () {
    it('binds the manager as a singleton', function () {
        expect(app(DopplerManager::class))->toBe(app(DopplerManager::class));
    });

    it('registers all four commands', function () {
        $commands = array_keys(Artisan::all());

        expect($commands)->toContain('env:sync', 'env:diff', 'env:doctor', 'env:snapshot');
    });

    it('publishes its config under the doppler-config tag', function () {
        $paths = ServiceProvider::pathsToPublish(
            DopplerServiceProvider::class,
            'doppler-config',
        );

        expect($paths)->not->toBeEmpty();
    });
});
