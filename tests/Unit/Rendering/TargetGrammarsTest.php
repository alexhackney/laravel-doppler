<?php

declare(strict_types=1);

use AlexHackney\Doppler\Exceptions\RoundTripFailed;
use AlexHackney\Doppler\Exceptions\UnsupportedValue;
use AlexHackney\Doppler\Rendering\DockerRenderer;
use AlexHackney\Doppler\Rendering\LaravelRenderer;
use AlexHackney\Doppler\Rendering\RendererFactory;
use AlexHackney\Doppler\Rendering\RoundTripGuard;
use AlexHackney\Doppler\Rendering\ShellRenderer;
use AlexHackney\Doppler\Rendering\SystemdRenderer;

/**
 * Values every single-line grammar must carry without corruption.
 */
/**
 * @return array<string, string> Label to value.
 */
function singleLineValues(): array
{
    return [
        'plain' => 'plain',
        'empty' => '',
        'space' => 'has space',
        'hash' => 'has#hash',
        'single quote' => "it's",
        'double quote' => 'say "hi"',
        'backslash' => 'C:\path',
        'dollar' => 'pa$$word',
        'dollar brace' => 'pre${BASE}post',
        'tab' => "a\tb",
        'utf8' => 'café-中文',
        'equals' => 'a=b',
        'json' => '{"a":"b"}',
    ];
}

describe('systemd target', function () {
    it('round-trips single-line values', function (string $label, string $value) {
        $renderer = new SystemdRenderer;

        expect($renderer->parse($renderer->render(['K' => $value])))
            ->toBe(['K' => $value], $label);
    })->with(array_map(
        fn (string $l, string $v): array => [$l, $v],
        array_keys(singleLineValues()),
        array_values(singleLineValues()),
    ));

    it('refuses a multi-line value rather than half-expressing it', function () {
        (new SystemdRenderer)->render(['PRIVATE_KEY' => "-----BEGIN-----\nabc\n-----END-----"]);
    })->throws(UnsupportedValue::class, 'cannot express a multi-line value');

    it('names every offending key when refusing', function () {
        try {
            (new SystemdRenderer)->render([
                'A_KEY' => "one\ntwo",
                'FINE' => 'ok',
                'B_KEY' => "three\rfour",
            ]);
            $this->fail('expected UnsupportedValue');
        } catch (UnsupportedValue $e) {
            expect($e->keys)->toBe(['A_KEY', 'B_KEY']);
            expect($e->getMessage())->not->toContain('one');
        }
    });
});

describe('docker target', function () {
    it('round-trips single-line values', function (string $label, string $value) {
        $renderer = new DockerRenderer;

        expect($renderer->parse($renderer->render(['K' => $value])))
            ->toBe(['K' => $value], $label);
    })->with(array_map(
        fn (string $l, string $v): array => [$l, $v],
        array_keys(singleLineValues()),
        array_values(singleLineValues()),
    ));

    it('emits values raw, because docker performs no quote processing', function () {
        // The bug this prevents: a phpdotenv-quoted file handed to docker gives every
        // value a pair of literal quote characters wrapped around it.
        expect((new DockerRenderer)->render(['K' => 'has space']))->toBe("K=has space\n");
        expect((new DockerRenderer)->render(['K' => "it's"]))->toBe("K=it's\n");
    });

    it('refuses a multi-line value', function () {
        (new DockerRenderer)->render(['PRIVATE_KEY' => "a\nb"]);
    })->throws(UnsupportedValue::class);
});

describe('shell target', function () {
    it('round-trips single-line values', function (string $label, string $value) {
        $renderer = new ShellRenderer;

        expect($renderer->parse($renderer->render(['K' => $value])))
            ->toBe(['K' => $value], $label);
    })->with(array_map(
        fn (string $l, string $v): array => [$l, $v],
        array_keys(singleLineValues()),
        array_values(singleLineValues()),
    ));

    it('supports multi-line values, unlike systemd and docker', function () {
        $renderer = new ShellRenderer;
        $pem = "-----BEGIN-----\nabc\n-----END-----";

        expect($renderer->parse($renderer->render(['PRIVATE_KEY' => $pem])))
            ->toBe(['PRIVATE_KEY' => $pem]);
    });

    it('uses the POSIX escape for an embedded single quote', function () {
        expect((new ShellRenderer)->quote("it's"))->toBe("'it'\\''s'");
    });

    it('emits export so a sourced file reaches child processes', function () {
        expect((new ShellRenderer)->render(['K' => 'v']))->toBe("export K='v'\n");
    });

    it('round-trips a value made only of quotes', function () {
        $renderer = new ShellRenderer;

        expect($renderer->parse($renderer->render(['K' => "'''"])))->toBe(['K' => "'''"]);
    });
});

describe('round-trip guard', function () {
    it('passes content that loads back identically', function () {
        $renderer = new LaravelRenderer;
        $secrets = ['A' => 'one', 'B' => "it's #2"];

        (new RoundTripGuard)->verify($renderer, $renderer->render($secrets), $secrets);
    })->throwsNoExceptions();

    it('refuses when a value comes back changed', function () {
        $renderer = new LaravelRenderer;

        // Hand-built content simulating a renderer that forgot to quote.
        (new RoundTripGuard)->verify($renderer, "K=has#hash\n", ['K' => 'has#hash']);
    })->throws(RoundTripFailed::class, 'K');

    it('refuses when the render invents a key', function () {
        $renderer = new LaravelRenderer;

        // A value that broke out of its quoting and was read back as a new assignment.
        (new RoundTripGuard)->verify($renderer, "A='one'\nB='two'\n", ['A' => 'one']);
    })->throws(RoundTripFailed::class, 'B');

    it('refuses when a key goes missing', function () {
        $renderer = new LaravelRenderer;

        (new RoundTripGuard)->verify($renderer, "A='one'\n", ['A' => 'one', 'B' => 'two']);
    })->throws(RoundTripFailed::class, 'B');

    it('never names a value in its failure message', function () {
        $renderer = new LaravelRenderer;

        try {
            (new RoundTripGuard)->verify($renderer, "SECRET=hunter2#tail\n", ['SECRET' => 'hunter2#tail']);
            $this->fail('expected RoundTripFailed');
        } catch (RoundTripFailed $e) {
            expect($e->getMessage())->toContain('SECRET');
            expect($e->getMessage())->not->toContain('hunter2');
        }
    });
});

describe('renderer factory', function () {
    it('resolves every documented target', function (string $target) {
        expect((new RendererFactory)->make($target)->name())->toBe($target);
    })->with(['laravel', 'systemd', 'docker', 'shell']);

    it('rejects an unknown target and lists the real ones', function () {
        (new RendererFactory)->make('yaml');
    })->throws(InvalidArgumentException::class, 'laravel, systemd, docker, shell');
});
