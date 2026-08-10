<?php

declare(strict_types=1);

use AlexHackney\Doppler\Credentials\TokenResolver;
use AlexHackney\Doppler\Credentials\TokenType;
use AlexHackney\Doppler\Exceptions\TokenNotFound;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/doppler-token-'.bin2hex(random_bytes(6));
    mkdir($this->dir);

    putenv('DOPPLER_TEST_TOKEN');
    unset($_SERVER['DOPPLER_TEST_TOKEN']);
});

afterEach(function () {
    // Token files are dotfiles (.token), which plain glob('*') does not match.
    foreach (glob($this->dir.'/{,.}[!.,]*', GLOB_BRACE) ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->dir);

    putenv('DOPPLER_TEST_TOKEN');
    unset($_SERVER['DOPPLER_TEST_TOKEN']);
});

function writeToken(string $dir, string $name, string $contents): string
{
    $path = $dir.'/'.$name;
    file_put_contents($path, $contents);

    return $path;
}

describe('resolution order', function () {
    it('prefers an explicit --token above everything', function () {
        $file = writeToken($this->dir, '.token', 'dp.st.from-file');

        $credential = (new TokenResolver([$file], 'DOPPLER_TEST_TOKEN'))
            ->resolve(explicitToken: 'dp.st.from-flag');

        expect($credential->reveal())->toBe('dp.st.from-flag');
        expect($credential->source)->toBe('--token');
    });

    it('consults only --token-file when one is given', function () {
        $configured = writeToken($this->dir, '.token', 'dp.st.configured');
        $explicit = writeToken($this->dir, 'other.token', 'dp.st.explicit');

        $credential = (new TokenResolver([$configured], 'DOPPLER_TEST_TOKEN'))
            ->resolve(explicitFile: $explicit);

        expect($credential->reveal())->toBe('dp.st.explicit');
    });

    it('fails rather than falling back when --token-file is missing', function () {
        // Falling through to the configured list would silently sync the wrong project.
        $configured = writeToken($this->dir, '.token', 'dp.st.configured');

        expect(fn () => (new TokenResolver([$configured]))->resolve(explicitFile: $this->dir.'/nope.token'))
            ->toThrow(TokenNotFound::class, 'nope.token');
    });

    it('reads the real process environment before any file', function () {
        putenv('DOPPLER_TEST_TOKEN=dp.st.from-environment');
        $file = writeToken($this->dir, '.token', 'dp.st.from-file');

        $credential = (new TokenResolver([$file], 'DOPPLER_TEST_TOKEN'))->resolve();

        expect($credential->reveal())->toBe('dp.st.from-environment');
        expect($credential->source)->toContain('DOPPLER_TEST_TOKEN');
    });

    it('falls back through the file list in order', function () {
        $second = writeToken($this->dir, 'second.token', 'dp.st.second');

        $credential = (new TokenResolver([$this->dir.'/first.token', $second], 'DOPPLER_TEST_TOKEN'))->resolve();

        expect($credential->reveal())->toBe('dp.st.second');
        expect($credential->source)->toBe($second);
    });
});

describe('file handling', function () {
    it('treats an empty file as absent, not as a token', function () {
        // An empty file is a half-finished provisioning step. Using it produces a 401 that
        // reads as a bad credential rather than as a missing file.
        $empty = writeToken($this->dir, 'empty.token', '');
        $good = writeToken($this->dir, 'good.token', 'dp.st.real');

        expect((new TokenResolver([$empty, $good], 'DOPPLER_TEST_TOKEN'))->resolve()->reveal())
            ->toBe('dp.st.real');
    });

    it('treats a whitespace-only file as absent', function () {
        $blank = writeToken($this->dir, 'blank.token', "\n\n   \t\n");
        $good = writeToken($this->dir, 'good.token', 'dp.st.real');

        expect((new TokenResolver([$blank, $good], 'DOPPLER_TEST_TOKEN'))->resolve()->reveal())
            ->toBe('dp.st.real');
    });

    it('strips the trailing newline every editor adds', function () {
        // A token with a trailing \n produces a 401 that reads as a bad credential rather
        // than as a bad file, which is an expensive half hour.
        $file = writeToken($this->dir, '.token', "dp.st.trimmed\n");

        expect((new TokenResolver([$file], 'DOPPLER_TEST_TOKEN'))->resolve()->reveal())
            ->toBe('dp.st.trimmed');
    });

    it('strips a trailing carriage return from a Windows-edited file', function () {
        $file = writeToken($this->dir, '.token', "dp.st.trimmed\r\n");

        expect((new TokenResolver([$file], 'DOPPLER_TEST_TOKEN'))->resolve()->reveal())
            ->toBe('dp.st.trimmed');
    });

    it('ignores an empty environment variable', function () {
        putenv('DOPPLER_TEST_TOKEN=');
        $file = writeToken($this->dir, '.token', 'dp.st.from-file');

        expect((new TokenResolver([$file], 'DOPPLER_TEST_TOKEN'))->resolve()->reveal())
            ->toBe('dp.st.from-file');
    });
});

describe('token typing', function () {
    it('classifies each token type by prefix', function (string $token, TokenType $type) {
        expect(TokenType::fromToken($token))->toBe($type);
    })->with([
        'service' => ['dp.st.prd.abc', TokenType::Service],
        'personal' => ['dp.pt.abc', TokenType::Personal],
        'service account' => ['dp.sa.abc', TokenType::ServiceAccount],
        'unrecognised' => ['something-else', TokenType::Unknown],
    ]);

    it('knows which types need an explicit project and config', function () {
        expect(TokenType::Service->requiresScope())->toBeFalse();
        expect(TokenType::Personal->requiresScope())->toBeTrue();
        expect(TokenType::ServiceAccount->requiresScope())->toBeTrue();
        // An unknown prefix may be a future format; guessing would be worse than asking.
        expect(TokenType::Unknown->requiresScope())->toBeFalse();
    });

    it('reports a personal token as needing scope until given one', function () {
        $file = writeToken($this->dir, '.token', 'dp.pt.personal');
        $resolver = new TokenResolver([$file], 'DOPPLER_TEST_TOKEN');

        expect($resolver->resolve()->needsScope())->toBeTrue();
        expect($resolver->resolve(project: 'tsp', config: 'prd')->needsScope())->toBeFalse();
    });
});

describe('failure reporting', function () {
    it('lists every location it tried', function () {
        try {
            (new TokenResolver([$this->dir.'/a.token', $this->dir.'/b.token'], 'DOPPLER_TEST_TOKEN'))->resolve();
            expect(false)->toBeTrue('expected TokenNotFound');
        } catch (TokenNotFound $e) {
            expect($e->attempted)->toHaveCount(3);
            expect($e->getMessage())->toContain('DOPPLER_TEST_TOKEN');
            expect($e->getMessage())->toContain('a.token');
            expect($e->getMessage())->toContain('b.token');
        }
    });

    it('distinguishes a missing file from an empty one', function () {
        $empty = writeToken($this->dir, 'empty.token', '   ');

        try {
            (new TokenResolver([$this->dir.'/gone.token', $empty], 'DOPPLER_TEST_TOKEN'))->resolve();
            expect(false)->toBeTrue('expected TokenNotFound');
        } catch (TokenNotFound $e) {
            expect($e->getMessage())->toContain('does not exist');
            expect($e->getMessage())->toContain('empty or whitespace-only');
        }
    });

    it('exits with code 2', function () {
        try {
            (new TokenResolver([], 'DOPPLER_TEST_TOKEN'))->resolve();
            expect(false)->toBeTrue('expected TokenNotFound');
        } catch (TokenNotFound $e) {
            expect($e->exitCode()->value)->toBe(2);
            expect($e->isSoftFailable())->toBeFalse();
        }
    });
});
