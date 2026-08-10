<?php

declare(strict_types=1);

use AlexHackney\Doppler\Credentials\Credential;
use AlexHackney\Doppler\Exceptions\SourceUnavailable;
use AlexHackney\Doppler\Snapshot\SnapshotStore;
use AlexHackney\Doppler\Support\ExitCode;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->target = $this->path('.env');
    $this->snapshot = $this->path('snapshot.enc');

    config()->set('doppler.token.files', [$this->file('.token', 'dp.st.prd.abcdefghijklmnop')]);
    config()->set('doppler.target.path', $this->target);
    config()->set('doppler.api.base_url', 'https://api.doppler.test/v3');
    config()->set('doppler.api.retries', 0);
    config()->set('doppler.hooks', []);
    config()->set('doppler.preserve', []);
    config()->set('doppler.fallback.path', $this->snapshot);
});

function credential(): Credential
{
    return Credential::make('dp.st.prd.abcdefghijklmnop', 'test');
}

describe('snapshot store', function () {
    it('round-trips secrets through encryption', function () {
        $store = new SnapshotStore($this->snapshot);
        $secrets = ['APP_KEY' => 'base64:abc', 'PEM' => "line1\nline2", 'QUOTED' => "it's #1"];

        $store->write($secrets, credential());

        expect($store->read(credential()))->toBe($secrets);
    });

    it('writes ciphertext, not readable plaintext', function () {
        (new SnapshotStore($this->snapshot))->write(['SECRET' => 'zzPLAINTEXTzz'], credential());

        expect(file_get_contents($this->snapshot))->not->toContain('zzPLAINTEXTzz');
    });

    it('writes with 0600 permissions', function () {
        (new SnapshotStore($this->snapshot))->write(['A' => 'b'], credential());

        expect(fileperms($this->snapshot) & 0777)->toBe(0600);
    })->skipOnWindows();

    it('cannot be decrypted with a different token', function () {
        // The tradeoff of deriving the key from the token: rotating it invalidates every
        // snapshot on the fleet. This test documents that behaviour rather than hiding it.
        (new SnapshotStore($this->snapshot))->write(['A' => 'b'], credential());

        $rotated = Credential::make('dp.st.prd.zzzzzzzzzzzzzzzz', 'test');

        expect(fn () => (new SnapshotStore($this->snapshot))->read($rotated))
            ->toThrow(SourceUnavailable::class, 'may have been rotated');
    });

    it('survives a token rotation when an explicit passphrase is set', function () {
        $store = new SnapshotStore($this->snapshot, 'a-provisioned-passphrase');
        $store->write(['A' => 'b'], credential());

        $rotated = Credential::make('dp.st.prd.zzzzzzzzzzzzzzzz', 'test');

        expect((new SnapshotStore($this->snapshot, 'a-provisioned-passphrase'))->read($rotated))
            ->toBe(['A' => 'b']);
    });

    it('produces different ciphertext for the same input, because the salt is random', function () {
        $first = $this->path('first.enc');
        $second = $this->path('second.enc');

        (new SnapshotStore($first))->write(['A' => 'b'], credential());
        (new SnapshotStore($second))->write(['A' => 'b'], credential());

        expect(file_get_contents($first))->not->toBe(file_get_contents($second));
    });

    it('refuses a truncated or foreign file rather than returning nonsense', function () {
        file_put_contents($this->snapshot, 'not a snapshot');

        expect(fn () => (new SnapshotStore($this->snapshot))->read(credential()))
            ->toThrow(SourceUnavailable::class, 'not a snapshot written by this package');
    });

    it('detects tampering, because GCM is authenticated', function () {
        $store = new SnapshotStore($this->snapshot);
        $store->write(['A' => 'b'], credential());

        $payload = file_get_contents($this->snapshot);
        // Flip a byte deep in the ciphertext.
        $payload[strlen($payload) - 1] = chr(ord($payload[strlen($payload) - 1]) ^ 0xFF);
        file_put_contents($this->snapshot, $payload);

        expect(fn () => (new SnapshotStore($this->snapshot))->read(credential()))
            ->toThrow(SourceUnavailable::class);
    });

    it('reports when no snapshot exists', function () {
        expect(fn () => (new SnapshotStore($this->snapshot))->read(credential()))
            ->toThrow(SourceUnavailable::class, 'no snapshot exists');
    });

    it('reports its age', function () {
        $store = new SnapshotStore($this->snapshot);

        expect($store->ageInDays())->toBeNull();

        $store->write(['A' => 'b'], credential());

        expect($store->ageInDays())->toBe(0);
        // Forty days on from now, the same file reads as forty days old.
        expect($store->ageInDays(time() + (40 * 86400)))->toBe(40);
    });

    it('leaves no temporary file behind', function () {
        (new SnapshotStore($this->snapshot))->write(['A' => 'b'], credential());

        expect(glob($this->path('*.tmp')))->toBe([]);
    });
});

describe('env:snapshot command', function () {
    it('writes a snapshot from the live source', function () {
        Http::fake(['*' => Http::response(['APP_KEY' => 'base64:abc'])]);

        $this->artisan('env:snapshot')->assertExitCode(ExitCode::Success->value);

        expect(file_exists($this->snapshot))->toBeTrue();
        expect((new SnapshotStore($this->snapshot))->read(credential()))->toBe(['APP_KEY' => 'base64:abc']);
    });

    it('does not touch the environment file', function () {
        Http::fake(['*' => Http::response(['APP_KEY' => 'base64:abc'])]);

        $this->artisan('env:snapshot')->assertExitCode(0);

        expect(file_exists($this->target))->toBeFalse();
    });

    it('propagates the source failure exit code', function () {
        Http::fake(['*' => Http::response([], 401)]);

        $this->artisan('env:snapshot')->assertExitCode(ExitCode::AuthenticationFailed->value);
    });
});

describe('snapshot as a fallback', function () {
    it('renders from the snapshot when the source is unreachable', function () {
        (new SnapshotStore($this->snapshot))->write(['APP_KEY' => 'from-snapshot'], credential());

        config()->set('doppler.fallback.enabled', true);

        Http::fake(['*' => Http::response([], 500)]);

        $this->artisan('env:sync')->assertExitCode(ExitCode::Success->value);

        expect(file_get_contents($this->target))->toBe("APP_KEY='from-snapshot'\n");
    });

    it('prefers the snapshot over keeping a stale file, because it is fresher', function () {
        file_put_contents($this->target, "APP_KEY='very-old'\n");

        (new SnapshotStore($this->snapshot))->write(['APP_KEY' => 'from-snapshot'], credential());

        config()->set('doppler.fallback.enabled', true);
        config()->set('doppler.soft_fail', true);

        Http::fake(['*' => Http::response([], 500)]);

        $this->artisan('env:sync')->assertExitCode(ExitCode::Success->value);

        expect(file_get_contents($this->target))->toBe("APP_KEY='from-snapshot'\n");
    });

    it('falls through to soft-fail when the snapshot is unusable', function () {
        file_put_contents($this->target, "APP_KEY='kept'\n");
        file_put_contents($this->snapshot, 'corrupt');

        config()->set('doppler.fallback.enabled', true);
        config()->set('doppler.soft_fail', true);

        Http::fake(['*' => Http::response([], 500)]);

        $this->artisan('env:sync')->assertExitCode(ExitCode::Success->value);

        expect(file_get_contents($this->target))->toBe("APP_KEY='kept'\n");
    });

    it('never contacts the source under --fallback-only', function () {
        (new SnapshotStore($this->snapshot))->write(['APP_KEY' => 'offline'], credential());

        Http::fake();

        $this->artisan('env:sync', ['--fallback-only' => true])->assertExitCode(ExitCode::Success->value);

        Http::assertNothingSent();
        expect(file_get_contents($this->target))->toBe("APP_KEY='offline'\n");
    });

    it('refreshes the snapshot on --write-fallback', function () {
        Http::fake(['*' => Http::response(['APP_KEY' => 'fresh'])]);

        $this->artisan('env:sync', ['--write-fallback' => true])->assertExitCode(0);

        expect((new SnapshotStore($this->snapshot))->read(credential()))->toBe(['APP_KEY' => 'fresh']);
    });

    it('is not consulted when the fallback is disabled', function () {
        (new SnapshotStore($this->snapshot))->write(['APP_KEY' => 'from-snapshot'], credential());

        config()->set('doppler.fallback.enabled', false);
        config()->set('doppler.soft_fail', false);

        Http::fake(['*' => Http::response([], 500)]);

        $this->artisan('env:sync')->assertExitCode(ExitCode::SourceUnavailable->value);
    });
});
