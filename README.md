# Laravel Doppler

Render a Laravel `.env` from [Doppler](https://www.doppler.com) secrets, safely, atomically,
and with a guarantee that what gets written loads back to exactly what came down.

```bash
php artisan env:sync
```

Zero arguments is the common case. The token is found automatically, the file is written
through a temp-and-rename so it is never half-written, and nothing is written at all unless
the rendered bytes parse back to the exact values Doppler sent.

> Not affiliated with, endorsed by, or sponsored by Doppler Inc.
> Doppler is a trademark of its respective owner.

## Why this exists

Rendering an env file looks like a one-liner. It is not. The three lines below are all real
and all wrong:

```bash
doppler secrets download --no-file --format env-no-quotes > .env
```

- `env-no-quotes` **silently truncates** any value containing `#`, at the `#`, with no
  preceding whitespace required. Random-generated passwords contain `#` routinely.
- A value containing a space is a **hard parse error** that kills the entire file, not just
  that key.
- `> .env` **truncates the target before `doppler` even runs**. An outage leaves a zero-byte
  file and the box does not boot.

```bash
doppler secrets download ... > /tmp/.env.new && mv /tmp/.env.new /app/.env
```

- `/tmp` and `/app` are usually different filesystems, so this `mv` is **not atomic**. It
  degrades to copy-then-unlink, with a window where `.env` is half-written.

```php
file_put_contents(base_path('.env'), $rendered);   // running as root
```

- Produces a file **php-fpm cannot read**. Every config value silently becomes empty, the
  app boots, and every request fails in a way that looks nothing like a permissions problem.

This package closes all of those by construction, and refuses rather than writing whenever
it cannot be certain.

## Installation

```bash
composer require alexhackney/laravel-doppler
php artisan vendor:publish --tag=doppler-config
```

Requires PHP 8.2+ and Laravel 12 or 13.

## Getting a token onto the box

Provision a Doppler **service token** (`dp.st.`), which is already scoped to one config:

```bash
install -m 600 /dev/null /etc/doppler/.token
echo -n 'dp.st.prd.xxxxx' > /etc/doppler/.token
```

`/etc/doppler/.token` is the recommended location because it survives a switch to atomic
deploys, which would wipe anything inside the release directory. `base_path('.token')` also
works and is what you want when one box serves two sites on different Doppler projects.

Then:

```bash
php artisan env:doctor    # no network, no secrets printed
php artisan env:sync
```

## The guarantee

Before anything is written, the rendered bytes are loaded back through the **full phpdotenv
stack**, the same one Laravel boots with, and compared value by value against what Doppler
sent. Any mismatch is a refusal naming the offending keys and nothing else:

```
Rendered output does not round-trip: DB_PASSWORD, FIREBASE_PRIVATE_KEY.
The value contains characters this renderer cannot safely express. Nothing was written.
```

This matters more than it sounds. Interpolation in phpdotenv happens in the **loader**, not
the parser, so a guard built on the parser alone would pass a `${...}` value that then
corrupts silently at boot. See `.claude/docs/phase-0-grammar-findings.md` for the probe
output this is built on.

Values that round-trip correctly, verified by test: `#`, spaces, `'`, `"`, `\`, `$`, `${}`,
tabs, newlines, CRLF, UTF-8, PEM private keys, JSON, and 4KB values made entirely of the above.

## Commands

| Command | Does |
|---|---|
| `env:sync` | Render and write the env file |
| `env:diff` | Report key-level drift. Exits 10 on drift. Safe to schedule |
| `env:doctor` | Diagnose the setup. No network, no secrets printed |
| `env:snapshot` | Write an encrypted local fallback |

### `env:sync`

```
--token=            Doppler token. Discouraged: visible in ps and shell history
--token-file=       Path to a token file. When given, no other location is searched
--project=          Required for dp.pt. and dp.sa. tokens
--config=           Required for dp.pt. and dp.sa. tokens
--profile=          Named profile from config/doppler.php
--path=             Target file
--target=           Grammar: laravel | systemd | docker | shell
--driver=           api | cli | snapshot
--only=             Comma-separated allow-list of keys
--except=           Comma-separated deny-list of keys
--dry-run           Report the key-level diff. Write nothing
--stdout            Print rendered content. Write nothing. Implies --no-hooks
--force             Write despite validation problems
--soft-fail         An unreachable source exits 0 if the existing file is valid
--no-soft-fail      Override a config-level soft_fail => true
--no-hooks          Skip every post-sync hook
--fallback=         Snapshot path to use when the source is unreachable
--fallback-only     Render from the snapshot without contacting the source
--write-fallback    Refresh the snapshot after a successful render
```

### Exit codes

A deploy script, a systemd timer, and a monitor can all branch on these.

| Code | Meaning | Soft-failable |
|---|---|---|
| 0 | Wrote, was already identical, or kept a valid existing file | |
| 1 | Validation refused the write | no |
| 2 | No token could be resolved | no |
| 3 | Source unreachable, no usable fallback | **yes** |
| 4 | Authentication failed. Rotate, do not retry | no |
| 5 | Rate limited | **yes** |
| 6 | Round-trip verification failed | no |
| 7 | Written, but a post-sync hook failed | no |
| 10 | `env:diff` / `--dry-run`: drift detected | |

## Soft fail

The problem: adding `php artisan env:sync` to a deploy script gives every deploy a hard
dependency on Doppler being reachable, **including a deploy made during an incident**. The
usual fix is `env:sync || true`, which also swallows every failure that must not be
swallowed.

Soft fail expresses the tolerable case precisely, in config, once:

```php
'soft_fail' => true,
```

Now an unreachable source keeps the existing file, logs at **error** level, and exits 0 so
the deploy continues on config that is stale by minutes and almost certainly identical.

It is deliberately conditional. It applies **only** when:

- the failure is an unreachable source or a rate limit, and
- the existing file is present, non-empty, **and passes validation**.

Validating the kept file is what makes this safe rather than merely convenient. It covers
the one case where "it probably has not changed" is wrong: the deploy that introduces a new
key. Add the key to `validate.required` in the same commit that introduces the code reading
it, and a box whose file predates it refuses at deploy time instead of failing quietly hours
later.

It **never** applies to an authentication failure, a validation failure on a successful
download, a round-trip failure, or a missing or empty existing file. Those are defects, and
hiding them is how they reach production. On a fresh box with no `.env`, `env:sync || true`
lets `config:cache` bake a blank configuration, auth middleware fails closed, and every
request 503s while the configuration still looks correct. That is the exact outcome this
design refuses to produce.

A soft fail is invisible by construction: the deploy is green and the app is up. So it
prints a banner, logs at error level, and `env:diff` on a schedule is the backstop for a box
that has been quietly stale for a week.

## Validation

Rules live in config, with the consequence text alongside each key:

```php
'validate' => [
    'required' => [
        'INTERNAL_SERVICE_TOKEN' => 'the auth middleware fails closed with 503',
        'STRIPE_SECRET' => 'checkout throws on first payment',
    ],
    'absolute_url' => ['APP_URL', 'CALLBACK_URL'],
    'not_loopback' => ['CALLBACK_URL'],
    'matches' => ['APP_KEY' => '/^base64:/'],
],
```

The consequence is printed with the failure, which is the difference between an operator
knowing what happened and knowing what it costs.

| Rule | Catches |
|---|---|
| `required` | Key absent **or empty**. Doppler emits held-but-unset keys as empty, so this tests blankness |
| `absolute_url` | Missing scheme or host. Guzzle and the S3 SDK both fail late and unclearly |
| `not_loopback` | A public callback aimed at localhost. A third party bills you for work whose result never arrives |
| `no_control_chars` | A stray `\r` from a paste, riding inside a credential |
| `no_placeholder` | Values still reading `changeme`, `TODO`, `your-key-here` |
| `matches` | Regex, for values with a known shape |
| `dynamic_secrets` | Doppler dynamic secrets, which carry a lease and expire |

`--force` downgrades everything to a warning and writes anyway.

Set `'required' => 'env.example'` to derive the list from your committed `.env.example`
instead, keeping the contract where developers already maintain it.

## Post-sync hooks

Rendering the file changes nothing by itself. A running php-fpm with cached config keeps
serving the old values.

```php
'hooks' => [
    'artisan:config:clear',
    'artisan:config:cache',
    'artisan:queue:restart',
    'artisan:horizon:terminate',
    'shell:sudo -n service php8.4-fpm reload',
],
```

Two orderings the package enforces regardless of how you list them:

- **`config:clear` always runs first.** A later hook that boots the app would otherwise read
  a stale cached config.
- **If `config:cache` fails, every remaining hook is skipped and the command exits 7.** A
  cache built from a bad file bakes in blank secrets while the configuration still looks
  correct. Restarting workers on top of that spreads the damage rather than containing it.

`config.php` is opcached, so `config:cache` does not reach web requests until the fpm pool
reloads. If you cache config, you must also reload fpm. `env:doctor` warns when you have not.

## Per-box values

Two mechanisms, because shared configs stop being shared the moment a per-box value gets
into one.

**`preserve`** carries a key forward from the existing file when Doppler supplies none:

```php
'preserve' => ['APP_KEY'],
```

`APP_KEY` is the default for a reason: it encrypts queued job payloads sitting in the local
database, so replacing it orphans every one of them.

**`overlay`** merges a host-local file on top of the render:

```php
'overlay' => base_path('.env.local'),
```

For a server identity, or queue topology that differs between two workers pointed at the
same Doppler config. Overlaid keys are always reported by name, so nobody debugs a value
that is not in Doppler while staring at Doppler.

## Render targets

The same key/value map has to serve consumers whose parsers disagree in ways that silently
corrupt values.

| Target | Consumer | Critical difference |
|---|---|---|
| `laravel` | phpdotenv | Quotes processed; `${` interpolates outside single quotes |
| `systemd` | `EnvironmentFile=` | **No multi-line values exist.** Refuses rather than half-expressing |
| `docker` | `--env-file` | **No quote processing at all.** Quotes would become literal characters |
| `shell` | `source`d by bash | `export` plus POSIX `'\''` escaping |

Handing a `laravel`-rendered file to systemd works right up until somebody puts a private
key in the config. For `systemd` and `docker`, a multi-line value is a refusal naming the
keys, not a best effort.

## Programmatic use

```php
use AlexHackney\Doppler\Facades\Doppler;

$result  = Doppler::sync();          // SyncResult
$result  = Doppler::dryRun();
$secrets = Doppler::secrets();       // array<string,string>, no file touched

Doppler::profile('qw')->sync();
```

`SyncResult` holds no secret values, so it is safe to log, queue, or return from a health
check.

## Testing your app

```php
use AlexHackney\Doppler\Facades\Doppler;

Doppler::fake(['APP_KEY' => 'base64:test', 'DB_PASSWORD' => 'x']);
Doppler::fakeUnavailable();          // exercise your soft-fail path
Doppler::fakeUnauthenticated();

Doppler::assertSynced();
Doppler::assertSyncCount(1);
```

Faking at the package boundary means your tests keep passing when this package changes how
it talks to Doppler.

## Output discipline

No command ever prints a secret value or a full token. Diffs and errors name keys. Tokens
render as `dp.st.****abcd` everywhere, including exception traces, `__toString`, and
`var_dump`. `--stdout` is the deliberate exception and prints the file, never the token.

There is a dedicated test suite asserting this across every command, against a fake loaded
with canary values.

## Gotchas worth knowing

1. **Doppler emits held-but-unset keys as empty strings, not absences.** Presence checks are
   worthless; test for blank.
2. **`.env.{APP_ENV}` shadows `.env`** when `APP_ENV` is set in the server environment before
   dotenv loads. A stale `.env.production` silently wins over everything written here.
   `env:doctor` reports it.
3. **`env('DOPPLER_TOKEN')` reads the file being replaced.** This package reads the real
   process environment explicitly, and reports which source a token came from.
4. **A token on the command line is in `ps` and in shell history.** Supported, warned about
   every time.
5. **`${` interpolates in unquoted and double-quoted values.** A bare `$` is safe. The
   renderer escapes `$` in the only branch where it matters.
6. **The rendered file is a build artifact, not a document.** Comments, grouping, and
   ordering are gone the first time this runs. Keys are sorted so diffs are stable and the
   no-change short circuit fires. `.env.example` becomes your only documentation.
7. **Doppler's rate limit is roughly 240 requests/minute.** A per-minute timer across a
   large fleet will find it. 429 and 5xx are retried with backoff; 401 never is.
8. **Rendering is not propagating.** Sync is near-simultaneous across a fleet, never atomic.
   A dangerous flip still needs a deliberate window.
9. **`VITE_*` values are baked at CI build time.** Changing one in Doppler changes nothing on
   any box until the next asset build.

## Encrypted fallback

Optional, off by default. Covers the one case soft fail cannot: there is no previous file to
keep, such as a brand new box provisioned during an outage.

```php
'fallback' => ['enabled' => true, 'path' => '/etc/doppler/snapshot.enc'],
```

AES-256-GCM, key derived from the token by default via PBKDF2, matching the Doppler CLI's
model and needing no extra secret provisioned. Rotating the token invalidates every snapshot
on the fleet, so set `fallback.passphrase` if you would rather provision one thing more.

Order when the source is unreachable: try Doppler, then the snapshot, then soft-fail to the
existing file, then hard fail. Every snapshot use logs its age.

## Supported versions

| Laravel | PHP | Testbench |
|---|---|---|
| 13.x | 8.3, 8.4, 8.5 | 11.x |
| 12.x | 8.2, 8.3, 8.4, 8.5 | 10.x |

Every combination above is exercised in CI, together with a `--prefer-lowest` run so an
under-constrained dependency fails here rather than in your application.

**Laravel 11 is deliberately not supported.** Its security window closed in March 2026, and
every published 11.x release carries unpatched advisories that will never be fixed. Composer
2.10 blocks advisory-affected packages by default, so a Laravel 11 install of this package
would not resolve for most people anyway.

## Contributing

```bash
composer install
composer test
composer analyse    # PHPStan level 8
composer format     # Pint
```

## License

MIT. See [LICENSE](LICENSE).
