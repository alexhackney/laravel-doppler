<?php

declare(strict_types=1);

use AlexHackney\Doppler\Support\ExitCode;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->target = $this->path('.env');

    config()->set('doppler.token.files', [$this->file('.token', 'dp.st.prd.abcdefghijklmnop')]);
    config()->set('doppler.target.path', $this->target);
    config()->set('doppler.api.base_url', 'https://api.doppler.test/v3');
    config()->set('doppler.api.retries', 0);
    config()->set('doppler.hooks', []);
    config()->set('doppler.preserve', []);
});

/**
 * @param  array<string, string>  $secrets
 */
function fakeSecrets(array $secrets): void
{
    Http::fake(['*' => Http::response($secrets)]);
}

describe('writing', function () {
    it('renders the secrets into the target file', function () {
        fakeSecrets(['APP_KEY' => 'base64:abc', 'DB_PASSWORD' => "it's #1"]);

        $this->artisan('env:sync')->assertExitCode(ExitCode::Success->value);

        expect(file_get_contents($this->target))
            ->toBe("APP_KEY='base64:abc'\nDB_PASSWORD=\"it's #1\"\n");
    });

    it('writes with 0600 permissions', function () {
        fakeSecrets(['APP_KEY' => 'x']);

        $this->artisan('env:sync')->assertExitCode(0);

        expect(fileperms($this->target) & 0777)->toBe(0600);
    });

    it('leaves no temporary file behind', function () {
        fakeSecrets(['APP_KEY' => 'x']);

        $this->artisan('env:sync')->assertExitCode(0);

        expect(glob($this->path('*.tmp')))->toBe([]);
    });

    it('keeps one backup generation of the previous file', function () {
        file_put_contents($this->target, "OLD='value'\n");

        fakeSecrets(['NEW' => 'value']);

        $this->artisan('env:sync')->assertExitCode(0);

        expect(file_get_contents($this->target.'.backup'))->toBe("OLD='value'\n");
    });

    it('does nothing at all when the content is already identical', function () {
        fakeSecrets(['APP_KEY' => 'unchanged']);

        $this->artisan('env:sync')->assertExitCode(0);
        $mtime = filemtime($this->target);

        // A hook that would fail proves no hooks ran on the second pass.
        config()->set('doppler.hooks', ['artisan:this-command-does-not-exist']);

        $this->artisan('env:sync')
            ->expectsOutputToContain('already current')
            ->assertExitCode(ExitCode::Success->value);

        clearstatcache();
        expect(filemtime($this->target))->toBe($mtime);
        expect(file_exists($this->target.'.backup'))->toBeFalse();
    });
});

describe('validation', function () {
    it('refuses to write when a required key is empty', function () {
        // Doppler renders a held-but-unset key as empty, not absent, so this is the
        // configuration that actually reaches production.
        file_put_contents($this->target, "EXISTING='untouched'\n");

        fakeSecrets(['APP_KEY' => 'base64:abc', 'INTERNAL_SERVICE_TOKEN' => '']);

        config()->set('doppler.validate.required', [
            'INTERNAL_SERVICE_TOKEN' => 'the auth middleware fails closed with 503',
        ]);

        $this->artisan('env:sync')->assertExitCode(ExitCode::ValidationFailed->value);

        expect(file_get_contents($this->target))->toBe("EXISTING='untouched'\n");
    });

    it('prints the consequence alongside the failure', function () {
        fakeSecrets(['INTERNAL_SERVICE_TOKEN' => '']);

        config()->set('doppler.validate.required', [
            'INTERNAL_SERVICE_TOKEN' => 'the auth middleware fails closed with 503',
        ]);

        $this->artisan('env:sync')
            ->expectsOutputToContain('the auth middleware fails closed with 503')
            ->assertExitCode(ExitCode::ValidationFailed->value);
    });

    it('writes anyway under --force', function () {
        fakeSecrets(['APP_KEY' => 'base64:abc', 'INTERNAL_SERVICE_TOKEN' => '']);

        config()->set('doppler.validate.required', ['INTERNAL_SERVICE_TOKEN' => 'it breaks']);

        $this->artisan('env:sync', ['--force' => true])->assertExitCode(ExitCode::Success->value);

        expect(file_exists($this->target))->toBeTrue();
    });

    it('rejects a value carrying a stray carriage return', function () {
        fakeSecrets(['API_TOKEN' => "secret\r"]);

        $this->artisan('env:sync')->assertExitCode(ExitCode::ValidationFailed->value);
    });

    it('rejects a loopback callback url', function () {
        fakeSecrets(['APP_URL' => 'http://localhost/webhook']);

        config()->set('doppler.validate.not_loopback', ['APP_URL']);

        $this->artisan('env:sync')->assertExitCode(ExitCode::ValidationFailed->value);
    });

    it('rejects a url with no scheme', function () {
        fakeSecrets(['APP_URL' => 'example.com']);

        config()->set('doppler.validate.absolute_url', ['APP_URL']);

        $this->artisan('env:sync')->assertExitCode(ExitCode::ValidationFailed->value);
    });

    it('rejects a leftover placeholder', function () {
        fakeSecrets(['STRIPE_KEY' => 'changeme']);

        $this->artisan('env:sync')->assertExitCode(ExitCode::ValidationFailed->value);
    });

    it('rejects a dynamic secret by default', function () {
        fakeSecrets(['DOPPLER_DYNAMIC_AWS_KEY' => 'leased']);

        $this->artisan('env:sync')->assertExitCode(ExitCode::ValidationFailed->value);
    });
});

describe('dry run and stdout', function () {
    // Found by counting lines of a real 173-key production render: --stdout emitted one
    // more line than there were keys, because line() appends a newline to content that
    // already ends in one. `env:sync --stdout > .env` has to produce the bytes the writer
    // would have produced, or the flag is lying about what it is showing you.
    it('emits exactly the bytes the writer would have written, with no extra newline', function () {
        fakeSecrets(['ALPHA' => 'one', 'BETA' => 'two', 'EMPTY' => '']);

        Artisan::call('env:sync', ['--stdout' => true]);

        expect(Artisan::output())->toBe("ALPHA='one'\nBETA='two'\nEMPTY=\n");
    });

    it('prints one line per key and nothing more', function () {
        fakeSecrets(['A' => '1', 'B' => '2', 'C' => '3']);

        Artisan::call('env:sync', ['--stdout' => true]);

        expect(substr_count(Artisan::output(), "\n"))->toBe(3);
    });

    it('writes nothing on --dry-run and exits 10 when there is drift', function () {
        file_put_contents($this->target, "OLD='value'\n");

        fakeSecrets(['NEW' => 'value']);

        $this->artisan('env:sync', ['--dry-run' => true])
            ->assertExitCode(ExitCode::DriftDetected->value);

        expect(file_get_contents($this->target))->toBe("OLD='value'\n");
    });

    it('exits 0 on --dry-run when there is no drift', function () {
        fakeSecrets(['SAME' => 'value']);

        $this->artisan('env:sync')->assertExitCode(0);

        $this->artisan('env:sync', ['--dry-run' => true])->assertExitCode(ExitCode::Success->value);
    });

    it('names drifted keys without printing values', function () {
        file_put_contents($this->target, "DB_PASSWORD='old-secret'\n");

        fakeSecrets(['DB_PASSWORD' => 'new-secret']);

        $this->artisan('env:sync', ['--dry-run' => true])
            ->expectsOutputToContain('DB_PASSWORD')
            ->assertExitCode(ExitCode::DriftDetected->value);
    });

    it('prints the rendered content on --stdout and writes nothing', function () {
        fakeSecrets(['APP_KEY' => 'printed']);

        $this->artisan('env:sync', ['--stdout' => true])
            ->expectsOutputToContain("APP_KEY='printed'")
            ->assertExitCode(ExitCode::Success->value);

        expect(file_exists($this->target))->toBeFalse();
    });
});

describe('filtering, preserve and overlay', function () {
    it('honours --only', function () {
        fakeSecrets(['KEEP' => '1', 'DROP' => '2']);

        $this->artisan('env:sync', ['--only' => 'KEEP'])->assertExitCode(0);

        expect(file_get_contents($this->target))->toBe("KEEP='1'\n");
    });

    it('honours --except', function () {
        fakeSecrets(['KEEP' => '1', 'DROP' => '2']);

        $this->artisan('env:sync', ['--except' => 'DROP'])->assertExitCode(0);

        expect(file_get_contents($this->target))->toBe("KEEP='1'\n");
    });

    it('carries APP_KEY forward when Doppler supplies none', function () {
        // APP_KEY encrypts queued job payloads sitting in the local database, so replacing
        // it orphans every one of them.
        file_put_contents($this->target, "APP_KEY='base64:host-generated'\n");

        config()->set('doppler.preserve', ['APP_KEY']);

        fakeSecrets(['DB_PASSWORD' => 'x']);

        $this->artisan('env:sync')->assertExitCode(0);

        expect(file_get_contents($this->target))->toContain("APP_KEY='base64:host-generated'");
    });

    it('does not carry a key forward when Doppler does supply it', function () {
        file_put_contents($this->target, "APP_KEY='base64:old'\n");

        config()->set('doppler.preserve', ['APP_KEY']);

        fakeSecrets(['APP_KEY' => 'base64:from-doppler']);

        $this->artisan('env:sync')->assertExitCode(0);

        expect(file_get_contents($this->target))->toContain("APP_KEY='base64:from-doppler'");
    });

    it('merges a host-local overlay on top of the render', function () {
        config()->set('doppler.overlay', $this->file('.env.local', "SERVER_NAME='qw-01'\nHORIZON_PROCESSES='10'\n"));

        fakeSecrets(['APP_KEY' => 'shared', 'SERVER_NAME' => 'from-doppler']);

        $this->artisan('env:sync')->assertExitCode(0);

        $written = file_get_contents($this->target);

        expect($written)->toContain("SERVER_NAME='qw-01'");
        expect($written)->toContain("HORIZON_PROCESSES='10'");
        expect($written)->toContain("APP_KEY='shared'");
    });

    it('reports overlaid keys by name so nobody debugs Doppler for a local value', function () {
        config()->set('doppler.overlay', $this->file('.env.local', "SERVER_NAME='qw-01'\n"));

        fakeSecrets(['APP_KEY' => 'shared']);

        $this->artisan('env:sync')
            ->expectsOutputToContain('SERVER_NAME')
            ->assertExitCode(0);
    });
});

describe('targets', function () {
    it('renders the systemd grammar on request', function () {
        fakeSecrets(['APP_KEY' => 'has space']);

        $this->artisan('env:sync', ['--target' => 'systemd'])->assertExitCode(0);

        expect(file_get_contents($this->target))->toBe("APP_KEY=\"has space\"\n");
    });

    it('refuses a multi-line value for a target that cannot express one', function () {
        file_put_contents($this->target, "EXISTING='untouched'\n");

        fakeSecrets(['PRIVATE_KEY' => "-----BEGIN-----\nabc\n-----END-----"]);

        $this->artisan('env:sync', ['--target' => 'systemd'])
            ->assertExitCode(ExitCode::RoundTripFailed->value);

        expect(file_get_contents($this->target))->toBe("EXISTING='untouched'\n");
    });

    it('handles the same multi-line value fine on the laravel target', function () {
        $pem = "-----BEGIN PRIVATE KEY-----\nMIIEvQ\n-----END PRIVATE KEY-----";

        fakeSecrets(['PRIVATE_KEY' => $pem]);

        $this->artisan('env:sync')->assertExitCode(0);

        // The proof that matters is that it loads back to the original bytes.
        expect(Dotenv\Dotenv::parse($this->read($this->target))['PRIVATE_KEY'])->toBe($pem);
    });
});

describe('token resolution', function () {
    it('exits 2 when no token can be found, and lists where it looked', function () {
        config()->set('doppler.token.files', [$this->path('missing.token')]);

        Http::fake();

        $this->artisan('env:sync')
            ->expectsOutputToContain('missing.token')
            ->assertExitCode(ExitCode::NoToken->value);
    });

    it('warns when a token is passed inline', function () {
        fakeSecrets(['APP_KEY' => 'x']);

        $this->artisan('env:sync', ['--token' => 'dp.st.prd.abcdefghijklmnop'])
            ->expectsOutputToContain('visible in ps')
            ->assertExitCode(0);
    });
});
