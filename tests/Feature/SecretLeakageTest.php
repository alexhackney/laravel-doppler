<?php

declare(strict_types=1);

use AlexHackney\Doppler\Credentials\Credential;
use AlexHackney\Doppler\DopplerManager;
use AlexHackney\Doppler\SyncOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * No command may ever print a secret value or a full token.
 *
 * This gets its own file because the failure mode is silent and permanent: a secret in a
 * CI log is a secret in the CI provider's storage, in anybody's scrollback, and in any
 * log shipper downstream of it. Rotation is the only remedy.
 *
 * Every command runs against a fake loaded with distinctive canary values, and the whole
 * output stream is searched for each one.
 */
const CANARIES = [
    'CANARY_PASSWORD' => 'zzTOPSECRETpasswordzz',
    'CANARY_API_KEY' => 'sk_live_qqCANARYqq',
    'CANARY_PEM' => "-----BEGIN PRIVATE KEY-----\nwwCANARYPEMww\n-----END PRIVATE KEY-----",
    'CANARY_QUOTED' => "it's ##CANARYQUOTED##",
];

const CANARY_TOKEN = 'dp.st.prd.SUPERSECRETTOKENBODY';

beforeEach(function () {
    $this->target = $this->path('.env');

    config()->set('doppler.token.files', [$this->file('.token', CANARY_TOKEN)]);
    config()->set('doppler.target.path', $this->target);
    config()->set('doppler.api.base_url', 'https://api.doppler.test/v3');
    config()->set('doppler.api.retries', 0);
    config()->set('doppler.hooks', []);
    config()->set('doppler.preserve', []);
});

/**
 * Run a command and capture its full output stream.
 *
 * Artisan::call is used rather than $this->artisan(), because the latter returns a
 * PendingCommand with its own buffer that Artisan::output() cannot see. Reading an empty
 * string would make every negative assertion below pass vacuously, which is the one way
 * this file could be worse than not existing.
 *
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function runCapturing(string $command, array $arguments = []): array
{
    $exitCode = Artisan::call($command, $arguments);

    return [$exitCode, Artisan::output()];
}

/**
 * Assert no canary value and no full token appears anywhere in the text.
 */
function assertNoLeak(string $output): void
{
    // Without this, every assertion below passes on an empty string and the suite proves
    // nothing at all.
    expect(trim($output))->not->toBe('', 'no output was captured, so the leak assertions would be vacuous');

    foreach (CANARIES as $key => $value) {
        // The PEM is multi-line, so check its distinctive middle rather than the whole.
        $needle = $key === 'CANARY_PEM' ? 'wwCANARYPEMww' : $value;

        expect($output)->not->toContain($needle, sprintf('%s leaked into output', $key));
    }

    expect($output)->not->toContain('SUPERSECRETTOKENBODY', 'the token leaked into output');
}

it('does not leak values from a successful sync', function () {
    Http::fake(['*' => Http::response(CANARIES)]);

    [$exit, $output] = runCapturing('env:sync');

    expect($exit)->toBe(0);
    assertNoLeak($output);
});

it('does not leak values from a dry run reporting drift', function () {
    file_put_contents($this->target, "CANARY_PASSWORD='an-older-secret'\n");

    Http::fake(['*' => Http::response(CANARIES)]);

    [, $output] = runCapturing('env:sync', ['--dry-run' => true]);

    assertNoLeak($output);
    // The key must still be named, or the diff is useless.
    expect($output)->toContain('CANARY_PASSWORD');
});

it('does not leak values from env:diff', function () {
    file_put_contents($this->target, "CANARY_PASSWORD='an-older-secret'\n");

    Http::fake(['*' => Http::response(CANARIES)]);

    [, $output] = runCapturing('env:diff');

    assertNoLeak($output);
});

it('does not leak values from a validation refusal', function () {
    Http::fake(['*' => Http::response(CANARIES + ['MISSING' => ''])]);

    config()->set('doppler.validate.required', ['MISSING' => 'something depends on it']);

    [$exit, $output] = runCapturing('env:sync');

    expect($exit)->toBe(1);
    assertNoLeak($output);
});

it('does not leak values from a round-trip refusal', function () {
    Http::fake(['*' => Http::response(CANARIES)]);

    [$exit, $output] = runCapturing('env:sync', ['--target' => 'systemd']);

    expect($exit)->toBe(6);
    assertNoLeak($output);
    expect($output)->toContain('CANARY_PEM');
});

it('does not leak the token from an authentication failure', function () {
    Http::fake(['*' => Http::response(['messages' => ['bad token']], 401)]);

    [$exit, $output] = runCapturing('env:sync');

    expect($exit)->toBe(4);
    assertNoLeak($output);
});

it('does not leak values or the token from env:snapshot', function () {
    config()->set('doppler.fallback.enabled', true);
    config()->set('doppler.fallback.path', $this->path('snapshot.enc'));

    Http::fake(['*' => Http::response(CANARIES)]);

    [$exit, $output] = runCapturing('env:snapshot');

    expect($exit)->toBe(0);
    assertNoLeak($output);
});

it('does not leak the token from env:doctor', function () {
    [, $output] = runCapturing('env:doctor');

    assertNoLeak($output);
});

it('does not leak from a soft-fail banner', function () {
    file_put_contents($this->target, "CANARY_PASSWORD='".CANARIES['CANARY_PASSWORD']."'\n");

    config()->set('doppler.soft_fail', true);
    Http::fake(['*' => Http::response([], 500)]);

    [$exit, $output] = runCapturing('env:sync');

    expect($exit)->toBe(0);
    assertNoLeak($output);
});

it('redacts the token in every credential rendering', function () {
    $credential = Credential::make(CANARY_TOKEN, '/etc/doppler/.token');

    expect($credential->redacted())->toBe('dp.st.****BODY');
    expect((string) $credential)->toBe('dp.st.****BODY');
    expect(print_r($credential, true))->not->toContain('SUPERSECRETTOKENBODY');
    expect(json_encode($credential->__debugInfo()))->not->toContain('SUPERSECRETTOKENBODY');
});

it('fully masks a short token rather than revealing a useful fraction of it', function () {
    expect(Credential::make('dp.st.abcd', 'test')->redacted())->toBe('dp.st.****');
});

it('keeps values out of a serialised sync result', function () {
    Http::fake(['*' => Http::response(CANARIES)]);

    $result = app(DopplerManager::class)->sync(new SyncOptions(path: $this->target, runHooks: false));

    assertNoLeak(json_encode($result->toArray(), JSON_THROW_ON_ERROR));
});

it('does not leak when a value is printed with --stdout, which is the deliberate exception', function () {
    // --stdout exists to print the file. It must still never print the TOKEN.
    Http::fake(['*' => Http::response(CANARIES)]);

    [$exit, $output] = runCapturing('env:sync', ['--stdout' => true]);

    expect($exit)->toBe(0);
    expect($output)->toContain('zzTOPSECRETpasswordzz');
    expect($output)->not->toContain('SUPERSECRETTOKENBODY');
});
