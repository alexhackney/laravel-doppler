<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Secret source driver
    |--------------------------------------------------------------------------
    |
    | api      Call the Doppler HTTP API directly. The default, and the right choice
    |          almost always: no binary to provision on every box, container image and
    |          CI runner, and the package owns its own escaping rather than trusting
    |          Doppler's env rendering to match phpdotenv's grammar.
    |
    | cli      Shell out to the doppler binary. For boxes where egress to
    |          api.doppler.com is only permitted for the CLI, or where the CLI's own
    |          auth is already the established path.
    |
    | snapshot Read from a local encrypted snapshot. Never touches the network.
    |
    */

    'driver' => env('DOPPLER_DRIVER', 'api'),

    'api' => [
        'base_url' => env('DOPPLER_API_URL', 'https://api.doppler.com/v3'),

        'timeout' => (int) env('DOPPLER_TIMEOUT', 15),

        /*
         | Retries apply to 5xx and 429 only. A 401 is never retried: a revoked token
         | does not become valid on the second attempt, and retrying burns rate limit
         | that a healthy box on the same fleet may need.
         */
        'retries' => (int) env('DOPPLER_RETRIES', 2),

        'retry_delay_ms' => (int) env('DOPPLER_RETRY_DELAY_MS', 500),
    ],

    'cli' => [
        'binary' => env('DOPPLER_BINARY', 'doppler'),
        'timeout' => (int) env('DOPPLER_CLI_TIMEOUT', 30),
    ],

    'token' => [
        /*
         | Searched in order, first usable hit wins. An empty or whitespace-only file is
         | treated as absent rather than as a token, because it is a half-finished
         | provisioning step and a blank credential produces a 401 that reads as a bad
         | token rather than as a missing file.
         |
         | base_path('.token') is per-app, and is what a box serving two sites on two
         | different Doppler projects needs. It is supported deliberately, and it comes
         | with three obligations. env:doctor checks the first two:
         |
         |   1. Add `.token` to the application's .gitignore, in the same commit that
         |      starts using it. A token in a work tree is one `git add .` from a
         |      repository, and from there from a fork, a mirror, and a CI log.
         |   2. chmod 0600, owned by the user that runs `php artisan env:sync`. That is
         |      the deploy user, or root under a systemd timer — NOT necessarily the
         |      php-fpm user, which never needs to read the token at all.
         |   3. Know that base_path() is the release directory. Under an atomic deploy it
         |      does not survive the next release; link it in, or use /etc/doppler/.token.
         |
         | /etc/doppler/.token is the box credential: written once at provision time, and
         | outside the release directory so a switch to atomic deploys does not wipe it.
         | Same 0600-and-correct-owner rule, with no gitignore question to get wrong.
         |
         | --token-file overrides this list entirely.
         */
        'files' => [
            base_path('.token'),
            '/etc/doppler/.token',
        ],

        /*
         | Read from the REAL process environment, never through env(). The Laravel helper
         | resolves through phpdotenv, which has already loaded the .env this package is
         | about to replace, so a stale DOPPLER_TOKEN line in that file is
         | indistinguishable from a genuinely exported variable and disappears the moment
         | the sync succeeds.
         */
        'env_var' => 'DOPPLER_TOKEN',
    ],

    'target' => [
        'path' => env('DOPPLER_TARGET_PATH', base_path('.env')),

        /*
         | laravel  phpdotenv, what Laravel loads
         | systemd  EnvironmentFile= (no multi-line values exist in this format)
         | docker   docker run --env-file (no quote processing at all)
         | shell    sourced by bash (export plus POSIX quoting)
         */
        'grammar' => env('DOPPLER_TARGET_GRAMMAR', 'laravel'),

        /*
         | Keep one <target>.backup generation so a bad render can be rolled back by hand
         | mid-incident without going back to Doppler.
         |
         | The suffix is .backup rather than .bak on purpose: Laravel's application
         | .gitignore lists .env, .env.backup and .env.production with no wildcard, so a
         | .bak file holding every previous secret would be committable by default.
         |
         | A <target>.lock file is also kept beside the target while a write is in flight,
         | and is deliberately never removed. Neither is covered by .gitignore if you have
         | pointed target.path somewhere other than base_path('.env'); env:doctor checks.
         */
        'backup' => (bool) env('DOPPLER_BACKUP', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft fail
    |--------------------------------------------------------------------------
    |
    | When the source is unreachable or rate limited, AND the existing target file is
    | present, non-empty and passes validation: keep the file, log at error level, and
    | exit 0 so the deploy continues on config that is stale by minutes and almost
    | certainly identical.
    |
    | Never applies to an authentication failure, a validation failure on a successful
    | download, a round-trip failure, or a missing or invalid existing file. Those are
    | defects rather than weather, and hiding them is how they reach production.
    |
    | Off by default because a command that exits 0 after failing surprises people.
    | Recommended TRUE for any app that runs env:sync inside a deploy script: it means
    | the script stays a bare `php artisan env:sync` rather than gaining a `|| true`
    | that also swallows every failure that must not be swallowed.
    |
    */

    'soft_fail' => (bool) env('DOPPLER_SOFT_FAIL', false),

    /*
    |--------------------------------------------------------------------------
    | Preserved keys
    |--------------------------------------------------------------------------
    |
    | Carried forward from the existing file when the source supplies no value for them.
    |
    | APP_KEY is the default for a reason: it encrypts queued job payloads sitting in the
    | local database, so replacing it orphans every one of them.
    |
    */

    'preserve' => ['APP_KEY'],

    /*
    |--------------------------------------------------------------------------
    | Host-local overlay
    |--------------------------------------------------------------------------
    |
    | A file merged on top of the rendered secrets, for values that must stay per-box:
    | a server identity, or queue topology that differs between two workers sharing one
    | Doppler config. Null to disable.
    |
    | Overlaid keys are always reported by name so nobody debugs a value that is not in
    | Doppler while staring at Doppler.
    |
    */

    'overlay' => env('DOPPLER_OVERLAY'),

    'validate' => [
        /*
         | Key => why it matters. The consequence string is printed with the failure, and
         | it is the most valuable part of this config: it explains the blast radius at
         | the point of failure, to whoever is looking at a red deploy at 2am.
         |
         | Doppler renders a key it holds but has not set as an EMPTY value, not as an
         | absent one, so this tests for blankness rather than for presence.
         |
         | Under the laravel grammar it also refuses the four literals env() turns into
         | nothing — null, (null), empty, (empty) — because API_KEY=null passes a plain
         | trim() check while reaching the app as exactly the nothing this rule exists to
         | catch. That conversion is Laravel's (Illuminate\Support\Env), not phpdotenv's,
         | so it applies to the laravel target only: under systemd, docker and shell those
         | are ordinary strings and are left alone.
         |
         | Set to the string 'env.example' to derive the list from the committed
         | .env.example instead, keeping the contract where developers already maintain it.
         | If that file is missing or unreadable the run REFUSES rather than quietly
         | enforcing nothing.
         */
        'required' => [
            // 'INTERNAL_SERVICE_TOKEN' => 'the auth middleware fails closed with 503',
        ],

        /*
         | Keys that must carry a scheme and host. Guzzle and the S3 SDK both fail late
         | and unclearly on a bare hostname.
         */
        'absolute_url' => [
            // 'APP_URL',
        ],

        /*
         | Keys that must not point at localhost. A public callback URL aimed at a host
         | only the box itself can reach is the most expensive misconfiguration
         | available: a third party bills you for work whose result never arrives.
         */
        'not_loopback' => [
            // 'APP_URL',
        ],

        /*
         | Applies to every key. Catches a stray CR from a Windows paste into the Doppler
         | UI, or a trailing newline riding inside a credential. Silent and brutal.
         */
        'no_control_chars' => true,

        /*
         | Values still reading changeme, xxx, TODO, your-key-here.
         |
         | An exact-match list, plus five patterns unambiguous enough to catch inside a
         | longer value: <...>, {{...}}, [...], your-anything-here, and a leading
         | insert-/enter-/add-.
         |
         | No value that means something in a Laravel .env is on either list: `null` is how
         | env() spells an unset value (the conversion lives in Illuminate\Support\Env, not
         | in phpdotenv, which returns the literal string) and it appears in Laravel's own
         | .env.example, so it is not treated as a placeholder here. Use
         | no_placeholder_ignore below for a key whose real value collides with the list.
         */
        'no_placeholder' => true,

        /*
         | Keys exempt from the placeholder rule, for the case where a legitimate value
         | happens to be one of the words it looks for: a seed with a fixed known value, or
         | a service whose sandbox credential really is the string "test".
         */
        'no_placeholder_ignore' => [
            // 'STRIPE_TEST_SEED',
        ],

        /*
         | Key => regex, for values with a known shape.
         */
        'matches' => [
            // 'APP_KEY' => '/^base64:/',
        ],

        /*
         | Doppler dynamic secrets carry a lease TTL and expire. Rendered into a static
         | file they stop working hours later with no change having been made, which is a
         | genuinely awful thing to debug. Opt in deliberately.
         */
        'allow_dynamic_secrets' => false,

        /*
         | Additional rule class names implementing AlexHackney\Doppler\Validation\Rule.
         */
        'rules' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Post-sync hooks
    |--------------------------------------------------------------------------
    |
    | Rendering the file changes nothing by itself. A running php-fpm with cached config
    | keeps serving the old values, and queue workers read their environment at boot.
    |
    | Prefix with artisan: to run an artisan command, or shell: to run a shell command.
    |
    | Ordering the package enforces regardless of what is listed here:
    |
    |   - config:clear runs first, always.
    |   - The FIRST failing hook stops the rest and the command exits 7. config:cache is
    |     the case this exists for, and the one the error names explicitly: a cache built
    |     from a bad file bakes in blank secrets while the configuration still looks
    |     correct. The target file has already been written by this point.
    |
    | Note that config.php is opcached, so config:cache does not take effect for web
    | requests until the fpm pool reloads. If you cache config, you must also reload fpm.
    |
    */

    'hooks' => [
        'artisan:config:clear',
        // 'artisan:config:cache',
        // 'artisan:queue:restart',
        // 'artisan:horizon:terminate',
        // 'shell:sudo -n service php8.4-fpm reload',
    ],

    'fallback' => [
        'enabled' => (bool) env('DOPPLER_FALLBACK_ENABLED', false),

        /*
         | Outside the release directory by default, for the same reason
         | /etc/doppler/.token is preferred over base_path('.token').
         */
        'path' => env('DOPPLER_FALLBACK_PATH', '/etc/doppler/snapshot.enc'),

        /*
         | Warn when falling back to a snapshot older than this. Silently booting on a
         | six-week-old snapshot would be worse than failing.
         */
        'max_age_days' => (int) env('DOPPLER_FALLBACK_MAX_AGE_DAYS', 30),

        /*
         | Null derives the encryption key from the token, matching the Doppler CLI's own
         | model and requiring no extra secret to provision. The tradeoff is that rotating
         | the token invalidates every snapshot on the fleet, so set an explicit
         | passphrase if that matters more than the provisioning cost.
         */
        'passphrase' => env('DOPPLER_FALLBACK_PASSPHRASE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Named profiles
    |--------------------------------------------------------------------------
    |
    | For a box serving more than one app or config. Each profile is merged over the
    | top-level configuration, so it only needs to state its differences.
    |
    | Selected with --profile=qw.
    |
    */

    'profiles' => [
        // 'qw' => [
        //     'token' => ['files' => ['/etc/doppler/qw.token']],
        //     'hooks' => ['artisan:config:clear', 'artisan:horizon:terminate'],
        // ],
    ],
];
