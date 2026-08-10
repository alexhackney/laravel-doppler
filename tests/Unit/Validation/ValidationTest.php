<?php

declare(strict_types=1);

use AlexHackney\Doppler\Validation\Problem;
use AlexHackney\Doppler\Validation\Rules\AbsoluteUrlRule;
use AlexHackney\Doppler\Validation\Rules\DynamicSecretsRule;
use AlexHackney\Doppler\Validation\Rules\MatchesRule;
use AlexHackney\Doppler\Validation\Rules\NoControlCharsRule;
use AlexHackney\Doppler\Validation\Rules\NoPlaceholderRule;
use AlexHackney\Doppler\Validation\Rules\NotLoopbackRule;
use AlexHackney\Doppler\Validation\Rules\RequiredRule;
use AlexHackney\Doppler\Validation\Validator;

describe('required', function () {
    it('fails an empty value, not only an absent key', function () {
        // This is the whole point. Doppler renders a key it holds but has not set as an
        // empty string, so a presence check passes on exactly the configuration that
        // breaks production.
        $rule = RequiredRule::fromConfig(['API_KEY' => 'billing fails']);

        expect($rule->check(['API_KEY' => '']))->toHaveCount(1);
        expect($rule->check(['API_KEY' => '   ']))->toHaveCount(1);
        expect($rule->check([]))->toHaveCount(1);
        expect($rule->check(['API_KEY' => 'set']))->toBe([]);
    });

    it('distinguishes absent from empty in its message', function () {
        $rule = RequiredRule::fromConfig(['API_KEY' => 'billing fails']);

        expect($rule->check([])[0]->message)->toContain('not supplied at all');
        expect($rule->check(['API_KEY' => ''])[0]->message)->toContain('holds this key without a value');
    });

    it('carries the consequence through to the problem', function () {
        $problems = RequiredRule::fromConfig([
            'INTERNAL_SERVICE_TOKEN' => 'the auth middleware fails closed with 503',
        ])->check([]);

        expect($problems[0]->consequence)->toBe('the auth middleware fails closed with 503');
        expect($problems[0]->describe())->toContain('the auth middleware fails closed with 503');
    });

    it('accepts a plain list of keys as well as a keyed map', function () {
        expect(RequiredRule::fromConfig(['API_KEY', 'DB_PASSWORD'])->check([]))->toHaveCount(2);
    });

    it('derives the list from a .env.example', function () {
        $dir = sys_get_temp_dir().'/doppler-example-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/.env.example', <<<'ENV'
        # a comment
        APP_KEY=base64:something

        DB_PASSWORD=secret
        OPTIONAL_ONE=
        ENV);

        $rule = RequiredRule::fromExampleFile($dir.'/.env.example');
        $problems = $rule->check([]);

        // A key with an empty value in the example is conventionally optional.
        expect(array_map(fn (Problem $p): string => $p->key, $problems))
            ->toBe(['APP_KEY', 'DB_PASSWORD']);

        unlink($dir.'/.env.example');
        rmdir($dir);
    });

    it('returns no rules when the example file is missing', function () {
        expect(RequiredRule::fromExampleFile('/nonexistent/.env.example')->check([]))->toBe([]);
    });
});

describe('absolute url', function () {
    it('rejects a bare host', function () {
        expect((new AbsoluteUrlRule(['APP_URL']))->check(['APP_URL' => 'example.com']))->toHaveCount(1);
    });

    it('accepts http and https', function () {
        $rule = new AbsoluteUrlRule(['APP_URL']);

        expect($rule->check(['APP_URL' => 'https://example.com']))->toBe([]);
        expect($rule->check(['APP_URL' => 'http://example.com:8080/path']))->toBe([]);
    });

    it('rejects a non-http scheme', function () {
        expect((new AbsoluteUrlRule(['APP_URL']))->check(['APP_URL' => 'ftp://example.com']))
            ->toHaveCount(1);
    });

    it('leaves absence and blankness to the required rule', function () {
        $rule = new AbsoluteUrlRule(['APP_URL']);

        expect($rule->check([]))->toBe([]);
        expect($rule->check(['APP_URL' => '']))->toBe([]);
    });
});

describe('not loopback', function () {
    it('rejects every form of localhost', function (string $url) {
        expect((new NotLoopbackRule(['APP_URL']))->check(['APP_URL' => $url]))->toHaveCount(1);
    })->with([
        'http://localhost/callback',
        'http://127.0.0.1/callback',
        'http://127.0.0.53/callback',
        'https://app.localhost/callback',
        'http://0.0.0.0:8000',
        'http://host.docker.internal/hook',
        'localhost',
    ]);

    it('accepts a real host', function () {
        expect((new NotLoopbackRule(['APP_URL']))->check(['APP_URL' => 'https://api.example.com/hook']))
            ->toBe([]);
    });

    it('explains the cost, which is a third party billing for lost work', function () {
        $problems = (new NotLoopbackRule(['APP_URL']))->check(['APP_URL' => 'http://localhost']);

        expect($problems[0]->consequence)->toContain('billed for work whose callback never arrives');
    });
});

describe('control characters', function () {
    it('rejects a trailing carriage return', function () {
        expect((new NoControlCharsRule)->check(['TOKEN' => "secret\r"]))->toHaveCount(1);
    });

    it('rejects a null byte', function () {
        expect((new NoControlCharsRule)->check(['TOKEN' => "sec\0ret"]))->toHaveCount(1);
    });

    it('allows newlines by default, because a PEM key is a legitimate secret', function () {
        expect((new NoControlCharsRule)->check(['PEM' => "-----BEGIN-----\nabc\n-----END-----"]))->toBe([]);
    });

    it('allows a tab, which is ordinary inside a value', function () {
        expect((new NoControlCharsRule)->check(['V' => "a\tb"]))->toBe([]);
    });

    it('can be told to reject newlines too', function () {
        expect((new NoControlCharsRule(allowNewlines: false))->check(['V' => "a\nb"]))->toHaveCount(1);
    });

    it('names the character it found without printing the value', function () {
        $problems = (new NoControlCharsRule)->check(['TOKEN' => "zzSECRETzz\r"]);

        expect($problems[0]->message)->toContain('a carriage return');
        expect($problems[0]->describe())->not->toContain('zzSECRETzz');
    });
});

describe('placeholders', function () {
    it('rejects obvious leftovers', function (string $value) {
        expect((new NoPlaceholderRule)->check(['K' => $value]))->toHaveCount(1);
    })->with(['changeme', 'CHANGEME', 'TODO', 'xxx', 'your-key-here', '<your-key>', '{{value}}', 'replace-me']);

    it('accepts a real secret that merely contains a suspicious substring', function (string $value) {
        expect((new NoPlaceholderRule)->check(['K' => $value]))->toBe([]);
    })->with(['sk_live_todoist_integration', 'my-example-key-9f8a', 'passwordManager123', 'base64:abc']);

    it('ignores keys it is told to skip', function () {
        expect((new NoPlaceholderRule(['SEED']))->check(['SEED' => 'changeme']))->toBe([]);
    });
});

describe('matches', function () {
    it('accepts a value of the right shape', function () {
        expect((new MatchesRule(['APP_KEY' => '/^base64:/']))->check(['APP_KEY' => 'base64:abc']))->toBe([]);
    });

    it('rejects a value of the wrong shape', function () {
        expect((new MatchesRule(['APP_KEY' => '/^base64:/']))->check(['APP_KEY' => 'plain']))->toHaveCount(1);
    });

    it('reports a broken pattern as a config mistake, not a bad secret', function () {
        $problems = (new MatchesRule(['K' => '/unterminated']))->check(['K' => 'v']);

        expect($problems)->toHaveCount(1);
        expect($problems[0]->message)->toContain('not a valid regular expression');
        expect($problems[0]->consequence)->toContain('mistake in config/doppler.php');
    });
});

describe('dynamic secrets', function () {
    it('rejects a leased secret by default', function () {
        expect((new DynamicSecretsRule)->check(['DOPPLER_DYNAMIC_AWS_KEY' => 'x']))->toHaveCount(1);
    });

    it('explains that it expires with no change having been made', function () {
        $problems = (new DynamicSecretsRule)->check(['DOPPLER_DYNAMIC_AWS_KEY' => 'x']);

        expect($problems[0]->consequence)->toContain('stops working hours later');
    });

    it('allows them when opted into deliberately', function () {
        expect((new DynamicSecretsRule(allowed: true))->check(['DOPPLER_DYNAMIC_AWS_KEY' => 'x']))->toBe([]);
    });

    it('leaves ordinary keys alone', function () {
        expect((new DynamicSecretsRule)->check(['AWS_ACCESS_KEY_ID' => 'static']))->toBe([]);
    });
});

describe('validator assembly', function () {
    it('builds the default rule set from config', function () {
        $validator = Validator::fromConfig([]);

        // no_control_chars, no_placeholder and dynamic_secrets are on by default.
        expect($validator->rules())->toHaveCount(3);
    });

    it('can turn the always-on rules off', function () {
        $validator = Validator::fromConfig([
            'no_control_chars' => false,
            'no_placeholder' => false,
        ]);

        expect($validator->rules())->toHaveCount(1);
    });

    it('collects problems from every rule', function () {
        $problems = Validator::fromConfig([
            'required' => ['MISSING' => 'it breaks'],
            'absolute_url' => ['URL'],
        ])->validate([
            'URL' => 'bare-host',
            'DIRTY' => "value\r",
        ]);

        expect(array_map(fn (Problem $p): string => $p->rule, $problems))
            ->toContain('required', 'absolute_url', 'no_control_chars');
    });

    it('rejects a custom rule that does not implement the interface', function () {
        Validator::fromConfig(['rules' => [stdClass::class]]);
    })->throws(InvalidArgumentException::class, 'does not implement');

    it('rejects a custom rule class that does not exist', function () {
        Validator::fromConfig(['rules' => ['\Not\A\Real\Rule']]);
    })->throws(InvalidArgumentException::class);
});
