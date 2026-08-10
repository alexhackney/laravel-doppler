<?php

declare(strict_types=1);

use AlexHackney\Doppler\Exceptions\RoundTripFailed;
use AlexHackney\Doppler\Rendering\LaravelRenderer;

/**
 * The adversarial alphabet.
 *
 * Every character and sequence here has broken a real .env somewhere. The property this
 * file asserts is the one the whole package rests on: for any value Doppler can hold,
 * parse(render($value)) === $value.
 */
function adversarialValues(): array
{
    return [
        'plain' => 'plain',
        'empty' => '',
        'single space' => ' ',
        'internal space' => 'has space',
        'leading and trailing space' => '  padded  ',
        'hash' => 'has#hash',
        'hash at start' => '#leading',
        'hash with space before' => 'has #hash',
        'single quote' => "it's",
        'double quote' => 'say "hi"',
        'both quotes' => 'it\'s a "test"',
        'backslash' => 'C:\path\to',
        'double backslash' => 'C:\\\\server',
        'trailing backslash' => 'ends\\',
        'bare dollar' => 'pass$word',
        'double dollar' => 'pa$$word',
        'dollar brace' => 'pre${BASE}post',
        'dollar brace only' => '${BASE}',
        'escaped dollar brace' => '\${BASE}',
        'dollar paren' => 'pre$(whoami)post',
        'backtick' => 'pre`whoami`post',
        'newline' => "line1\nline2",
        'crlf' => "line1\r\nline2",
        'carriage return' => "line1\rline2",
        'tab' => "a\tb",
        'literal backslash n' => 'not\na newline',
        'equals sign' => 'key=value',
        'multiple equals' => 'a=b=c',
        'utf8 accented' => 'café',
        'utf8 cjk' => '中文字符',
        'utf8 emoji' => 'ok',
        'json' => '{"key":"value","n":1}',
        'json with quotes' => '{"a":"it\'s"}',
        'url' => 'https://user:p%40ss@example.com:5432/db?ssl=true',
        'base64 key' => 'base64:sYqLmH9v8kQpTz3XwNbRcE2dFgHjKlMnOpQrStUvWxY=',
        'pem private key' => "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBg\nkqhkiG9w0BAQEF\n-----END PRIVATE KEY-----",
        'only whitespace' => "   \t  ",
        'semicolon' => 'a;b',
        'ampersand' => 'a&b',
        'pipe' => 'a|b',
        'null-ish text' => 'null',
        'boolean-ish text' => 'true',
        'numeric text' => '00123',
        'long 4kb' => str_repeat('aB3$#\'"\\', 512),
    ];
}

it('round-trips every value in the adversarial alphabet', function (string $label, string $value) {
    $renderer = new LaravelRenderer;

    $content = $renderer->render(['K' => $value]);
    $parsed = $renderer->parse($content);

    expect($parsed)->toHaveKey('K');
    expect($parsed['K'])->toBe($value, sprintf('value "%s" did not survive the round trip', $label));
})->with(array_map(
    fn (string $label, string $value): array => [$label, $value],
    array_keys(adversarialValues()),
    array_values(adversarialValues()),
));

it('round-trips the entire adversarial alphabet in a single document', function () {
    $renderer = new LaravelRenderer;

    $secrets = [];
    $i = 0;
    foreach (adversarialValues() as $value) {
        $secrets['KEY_'.$i++] = $value;
    }

    // The renderer sorts keys deliberately, so compare on content rather than order.
    // Ordering has its own test below.
    $parsed = $renderer->parse($renderer->render($secrets));
    ksort($parsed, SORT_STRING);
    ksort($secrets, SORT_STRING);

    expect($parsed)->toBe($secrets);
});

it('never emits an unquoted value, because unquoted is never safe', function () {
    $renderer = new LaravelRenderer;

    // K=has space is a hard parse error that kills the whole file.
    // K=has#hash silently truncates at the hash.
    $content = $renderer->render([
        'PLAIN' => 'plain',
        'SPACED' => 'has space',
        'HASHED' => 'has#hash',
    ]);

    foreach (explode("\n", trim($content)) as $line) {
        [, $rendered] = explode('=', $line, 2);
        expect($rendered)->toMatch("/^['\"]/", 'every non-empty value must be quoted');
    }
});

it('defaults to single quotes, the only fully literal form', function () {
    $renderer = new LaravelRenderer;

    expect($renderer->quote('has#hash'))->toBe("'has#hash'");
    expect($renderer->quote('pre${BASE}post'))->toBe("'pre\${BASE}post'");
    expect($renderer->quote('say "hi"'))->toBe('\'say "hi"\'');
});

it('falls back to double quotes only for single quotes and newlines', function () {
    $renderer = new LaravelRenderer;

    expect($renderer->quote("it's"))->toStartWith('"');
    expect($renderer->quote("a\nb"))->toStartWith('"');
    expect($renderer->quote("a\rb"))->toStartWith('"');
});

it('renders an empty value as a bare assignment', function () {
    $renderer = new LaravelRenderer;

    expect($renderer->render(['K' => '']))->toBe("K=\n");
    expect($renderer->parse("K=\n"))->toBe(['K' => '']);
});

it('escapes the dollar so a double-quoted value cannot interpolate', function () {
    $renderer = new LaravelRenderer;

    // The dangerous shape: a value needing double quotes that also contains ${.
    // Without the $ escape this resolves against BASE at load time.
    $content = $renderer->render([
        'BASE' => 'secretbase',
        'PASSWORD' => 'it\'s ${BASE} really',
    ]);

    expect($renderer->parse($content)['PASSWORD'])->toBe('it\'s ${BASE} really');
    expect($content)->toContain('\\$');
});

it('does not let one key interpolate into another', function () {
    $renderer = new LaravelRenderer;

    // Forward and backward references both matter: the loader resolves against keys
    // defined earlier in the file, so a naive renderer breaks in one direction only.
    $secrets = [
        'APP_KEY' => 'base64:abc123',
        'DB_PASSWORD' => 'literal${APP_KEY}text',
        'AAA_EARLIER' => 'sees${DB_PASSWORD}later',
    ];

    $parsed = $renderer->parse($renderer->render($secrets));
    ksort($parsed, SORT_STRING);
    ksort($secrets, SORT_STRING);

    expect($parsed)->toBe($secrets);
});

it('sorts keys so output is deterministic and the no-change check can fire', function () {
    $renderer = new LaravelRenderer;

    $a = $renderer->render(['ZEBRA' => '1', 'APPLE' => '2', 'MANGO' => '3']);
    $b = $renderer->render(['APPLE' => '2', 'MANGO' => '3', 'ZEBRA' => '1']);

    expect($a)->toBe($b);
    expect($a)->toBe("APPLE='2'\nMANGO='3'\nZEBRA='1'\n");
});

it('renders an empty map as empty content', function () {
    expect((new LaravelRenderer)->render([]))->toBe('');
});

it('throws RoundTripFailed when handed content that cannot parse', function () {
    (new LaravelRenderer)->parse("K='unterminated\n");
})->throws(RoundTripFailed::class);

it('reports its target name', function () {
    expect((new LaravelRenderer)->name())->toBe('laravel');
});
