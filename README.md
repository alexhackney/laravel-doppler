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
php artisan env:doctor --fix
```

**No environment variables are required, and publishing the config is optional.** Every key in
`config/doppler.php` has a working default and the provider merges it whether you publish or
not, so installing the package and writing a service token to `.token` or
`/etc/doppler/.token` is a complete setup. Publish when you want to add validation rules,
hooks, or profiles:

```bash
php artisan vendor:publish --tag=doppler-config
```

Requires PHP 8.2+ and Laravel 12 or 13.

That third line is worth running once. This package writes files that must never be
committed — a token if you keep one in the repo, and `<target>.backup`, which holds every
secret from the previous render — and `--fix` appends the missing `.gitignore` entries for
whichever of them apply to your setup. It only ever appends, only entries it just proved are
missing, so running it again does nothing.

There is no install-time hook doing this for you, and that is deliberate rather than an
omission: Composer runs `scripts` only for the **root** package, so a library cannot hook its
own installation at all without shipping a `composer-plugin` — and since Composer 2.2 a
plugin does nothing unless the consuming application separately allow-lists it in
`config.allow-plugins`. The "automatic" version would silently not run for most people, and
silently editing a tracked file during `composer require` is not a thing this package should
do anyway.

## The token

Use a Doppler **service token** (`dp.st.`). It is read-only and already scoped to one project
and config, so nothing else needs configuring. Personal (`dp.pt.`) and service account
(`dp.sa.`) tokens address the whole workspace and need `--project` and `--config` alongside
them; `env:doctor` says so up front rather than letting the failure arrive as a Doppler error
about a missing config.

### Where it is looked for

First usable hit wins. An empty or whitespace-only file counts as **absent** rather than as a
token, because it is a half-finished provisioning step, and treating it as a credential turns
"you never wrote the file" into a 401 that reads as "your token is bad".

| # | Location | |
|---|---|---|
| 1 | `--token=` | Visible in `ps` to every user on the box, and in shell history. Warns on every use |
| 2 | `--token-file=` | When given, **nothing else is searched** |
| 3 | `DOPPLER_TOKEN` in the real process environment | Read through `getenv()`, never `env()` |
| 4 | `base_path('.token')` | Per application, inside the repository checkout |
| 5 | `/etc/doppler/.token` | Per box, outside the release directory |

4 and 5 are `token.files` in `config/doppler.php`, and are yours to reorder or replace.

3 is deliberately the *real* environment. `env('DOPPLER_TOKEN')` resolves through phpdotenv,
which has already loaded the very `.env` this package is about to replace, so a stale
`DOPPLER_TOKEN` line in that file is indistinguishable from a genuinely exported variable —
and it vanishes the moment the first sync succeeds. That is "it worked yesterday and nothing
changed".

Whichever position wins, `env:doctor` names it and prints the token redacted:

```
Token ........................................... dp.st.****mnop
  from .......................................... /etc/doppler/.token
  type .......................................... service (dp.st.)
  permissions ................................... 0600
```

### Option A — `/etc/doppler/.token`, the box credential

The default recommendation: outside the release directory, so a switch to atomic deploys does
not wipe it, and one file per box rather than one per checkout.

```bash
# once, as root, at provision time
install -d -m 0700 -o deploy -g deploy /etc/doppler
install -m 0600 -o deploy -g deploy /dev/null /etc/doppler/.token

# paste the token, then Ctrl-D. Nothing lands in shell history, nothing shows in ps.
cat > /etc/doppler/.token
```

Substitute the user that runs `php artisan env:sync` for `deploy` — see
[Which user should own the token](#which-user-should-own-the-token). A trailing newline is
fine: the resolver trims, because a token carrying a stray `\n` produces a 401 that reads as
a bad credential rather than as a bad file, and that is an expensive half hour.

### Option B — `.token` inside the repository

**Supported, and sometimes the right answer.** `base_path('.token')` is searched by default,
one position ahead of the box credential. Reach for it when:

- one box serves two sites on two different Doppler projects, so a single box-level
  credential cannot address both;
- the deploy user has no root, so `/etc/doppler` is not writable;
- you want the credential to be per application rather than per box.

It carries three obligations. `env:doctor` checks the first two, and the third is on you.

**1. Ignore it, in the same commit that starts using it.**

```gitignore
# .gitignore
.token
```

A token file in a work tree is one `git add .` away from a repository, and from there from a
fork, a mirror, and a CI log. `env:doctor` reads both `.gitignore` and `.git/info/exclude`,
and warns when neither covers it:

```
  /var/www/app/.token is inside a git work tree and is not ignored by
  /var/www/app/.gitignore or /var/www/app/.git/info/exclude. It is one `git add .` from
  being committed. Add it to .gitignore in the same commit that starts using it.
```

It cannot resolve a global excludes file, so that warning errs in the safe direction: a
spurious warning on a setup that is fine, never silence on a file that really is committable.

**2. Mode `0600`, owned by the user that runs the sync.**

```bash
install -m 0600 -o deploy -g deploy /dev/null .token
cat > .token          # paste, then Ctrl-D
```

**3. It does not survive an atomic deploy.** `base_path()` is the release directory. Under
Envoyer, Deployer, or any other `current -> releases/N` scheme, a `.token` written into one
release is gone at the next, and the sync exits 2 on the deploy *after* the one that worked.
Add it to the shared or linked-files list, or use Option A. A default Forge deploy pulls in
place and is unaffected.

### Option C — `DOPPLER_TOKEN` in the process environment

For containers and CI, where there is no persistent filesystem to provision and the
orchestrator already has a secret mechanism: a Kubernetes secret, a Docker `-e`, a CI masked
variable.

It has to be a **real** environment variable, not a line in the `.env` file, which is
deliberately not consulted for this.

On a shared box, prefer Option A over systemd's `Environment=`: a unit file is world-readable
by default and `systemctl show` prints its environment to anyone who can read the unit, while
a `0600` token file is readable only by the user that has to read it. Point the timer at the
file instead.

### Which user should own the token

Two files, two different questions. Conflating them is how a working setup gets loosened for
no reason.

| File | Must be readable by | Read when |
|---|---|---|
| the token file | whoever runs `php artisan env:sync` | only at sync time |
| the rendered `.env` | the php-fpm pool user, and the queue worker user | on every boot |

Often the same user — on Forge, `forge` runs both — and then there is nothing to decide. When
they differ, give the token only the narrower of the two. **The php-fpm user does not need to
read the token**, and granting it that buys nothing.

The strongest available shape is to sync from a systemd timer as root with the token
`0600 root:root`, so the application user cannot read the credential at all — only the file
rendered from it. That works because ownership of the rendered file is resolved separately:
the existing `.env`'s owner first, then `composer.json`'s, then the base path's, and the new
file is chowned to match. A sync run as root therefore does not leave behind the root-owned
`0600` `.env` that php-fpm cannot open, which is one of the failures this package exists to
prevent. `env:doctor` confirms the target is readable by the current process, and names the
owning uid when it is not the one running the command.

Never `chmod 644` a token file to make a permission problem go away. If the sync cannot read
it, the fix is `chown` — name the right owner — not widening the audience to every user on the
box. `env:doctor` warns on any mode with group or other bits set.

### Rotating

Overwrite the file and run `env:sync`. Nothing caches the token.

One catch: if the encrypted fallback is enabled and `fallback.passphrase` is null, the
snapshot key is derived from the token, so rotating invalidates every snapshot on the fleet.
Run `env:snapshot` after rotating, or provision an explicit passphrase. See
[Encrypted fallback](#encrypted-fallback).

### Checking it

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
corrupts silently at boot. See [docs/phpdotenv-grammar.md](https://github.com/alexhackney/laravel-doppler/blob/main/docs/phpdotenv-grammar.md)
for the probe output this is built on. (`docs/` is `export-ignore`d, so that file is on GitHub
rather than in your `vendor/` directory.)

Values that round-trip correctly, verified by test: `#`, spaces, `'`, `"`, `\`, `$`, `${}`,
tabs, newlines, CRLF, UTF-8, PEM private keys, JSON, and 4KB values made entirely of the above.

## Commands

| Command | Does |
|---|---|
| `env:sync` | Render and write the env file |
| `env:diff` | Report key-level drift. Exits 10 on drift. Safe to schedule. Exits 1 and names every offending key if Doppler's current secrets would not pass validation |
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

### `env:diff`

```
--token=  --token-file=  --project=  --config=  --profile=
--path=   --target=      --driver=   --only=    --except=
```

Read-only, so it has no `--force`, no hooks, and no write flags at all.

### `env:snapshot`

```
--token=  --token-file=  --project=  --config=  --profile=
--driver=      api or cli. Pointing it at `snapshot` re-encrypts the snapshot from itself,
               which is legal and useless
--fallback=    Where to write the snapshot
```

### `env:doctor`

```
--profile=     Named profile from config/doppler.php
--fix          Append the missing .gitignore entries and exit as normal
```

Checks, in one pass, with no network call and no secret printed: which token position won and
its type; the token file's mode and whether anything gitignores it; the target's directory,
mode, owner and readability; `<target>.backup` and `<target>.lock`, the same way; a cached
config with no `config:clear`/`config:cache` hook; a `.env.{APP_ENV}` file shadowing the
target; unrecognised hooks; and the snapshot's age against `fallback.max_age_days`.

### Exit codes

A deploy script, a systemd timer, and a monitor can all branch on these.

| Code | Meaning | Soft-failable |
|---|---|---|
| 0 | Wrote, was already identical, or kept a valid existing file | |
| 1 | Validation refused the write, or the write itself failed (full disk, read-only mount). Also `env:doctor`'s unhealthy exit | no |
| 2 | No token could be resolved | no |
| 3 | Source unreachable, no usable fallback | **yes** |
| 4 | Authentication failed. Rotate, do not retry | no |
| 5 | Rate limited | **yes** |
| 6 | Round-trip verification failed, or the target grammar cannot express a value | no |
| 7 | Written, but a post-sync hook failed | no |
| 8 | Doppler rejected the request: a bad project or config name, a malformed request. The token was accepted | no |
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
| `required` | Key absent, **empty**, or a literal `env()` turns into nothing. Doppler emits held-but-unset keys as empty, so this tests blankness |
| `absolute_url` | Missing scheme or host. Guzzle and the S3 SDK both fail late and unclearly |
| `not_loopback` | A public callback aimed at localhost. A third party bills you for work whose result never arrives |
| `no_control_chars` | A stray `\r` from a paste, riding inside a credential |
| `no_placeholder` | Values still reading `changeme`, `TODO`, `your-key-here` |
| `no_placeholder_ignore` | Not a rule: the list of keys exempt from `no_placeholder`, for a legitimate value that happens to be one of those words |
| `matches` | Regex, for values with a known shape |
| `dynamic_secrets` | Doppler dynamic secrets, which carry a lease and expire. Enabled by `allow_dynamic_secrets => false`, which is the default |

`no_placeholder` is an exact-match list (`changeme`, `todo`, `xxx`, `secret`, `test`, `foo` and
friends) plus five patterns unambiguous enough to catch inside a longer value: `<...>`,
`{{...}}`, `[...]`, `your-anything-here`, and a leading `insert-`/`enter-`/`add-`. It holds no
value that means something in a Laravel `.env`. `null` is not treated as a leftover: Laravel's `env()` helper resolves it to a real
null (phpdotenv itself returns the string; the conversion is in `Illuminate\Support\Env`,
alongside `(null)`, `true`, `false`, `empty` and `(empty)`), and Laravel's own `.env.example`
ships `MAIL_ENCRYPTION=null` and `REDIS_PASSWORD=null`. Nor is `none`, on
`SESSION_SAME_SITE=none`. A key that must not be blank belongs in `required`,
where the consequence text lives.

`--force` downgrades everything to a warning and writes anyway. `env:diff` has no `--force`,
because it never writes; it prints the same per-key detail and exits 1.

### `required` and the four literals that mean nothing

Under the `laravel` grammar a value does not reach your app as written — it goes through
`env()`, and `Illuminate\Support\Env` converts four literals into nothing at all:

| In Doppler | What `env()` returns |
|---|---|
| `null`, `(null)` | `null` |
| `empty`, `(empty)` | `''` |

So `API_KEY=null` used to satisfy `required` while delivering exactly the nothing that rule
exists to prevent. It is now a refusal, naming the literal:

```
API_KEY is required, but the value "null" is one Laravel's env() resolves to null,
so the application receives nothing.
  → the client throws on first call
```

This is grammar-aware. Under `systemd`, `docker` and `shell` nothing converts them, so there
they are ordinary four-character strings and are left alone.

Worth being precise about the mechanism, because it is easy to blame the wrong library:
`Dotenv::parse()` returns the string `'null'`. The conversion is **Laravel's**, not
phpdotenv's — which is why the round-trip guard is indifferent to these values and why
`required` cannot be.

### Deriving the list from `.env.example`

Set `'required' => 'env.example'` to derive the list from your committed `.env.example`
instead, keeping the contract where developers already maintain it.

If that file is missing or unreadable, the run **refuses** rather than passing. A rule
configured to read its contract from a file it cannot find enforces nothing while reporting
a clean validation pass, and the box where `.env.example` was never deployed is exactly the
box where the contract was load-bearing.

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
- **The first failing hook stops the rest, and the command exits 7.** `config:cache` is the
  case this exists for, and the one the error calls out by name: a cache built from a bad file
  bakes in blank secrets while the configuration still looks correct, and restarting workers on
  top of that spreads the damage rather than containing it. The file has already been written
  at this point — exit 7 means "written, then something after it failed", not "nothing
  happened".

`config.php` is opcached, so `config:cache` does not reach web requests until the fpm pool
reloads. If you cache config, you must also reload fpm. `env:doctor` raises this whenever it
finds a cached config and a `config:cache` hook — it cannot tell your reload hook from any
other `shell:` hook, so it says it every time rather than guessing.

## Files a sync leaves behind

Two, both beside the target, and it is worth knowing what they are because one of them holds
secrets.

| File | Contents | Mode |
|---|---|---|
| `<target>.backup` | Every secret from the **previous** render | `0600` |
| `<target>.lock` | Nothing. Held during a write so two deploys cannot interleave hooks | `0644` |

Modes are POSIX. On Windows `chmod()` only toggles the read-only attribute, so every mode
this package sets is a no-op and files report `0666`; access there is an NTFS ACL question
this package does not answer. `env:doctor` says so rather than letting you assume otherwise.
See [SECURITY.md](SECURITY.md#windows).

**Add both to your `.gitignore`.** Laravel's application skeleton ships:

```gitignore
.env
.env.backup
.env.production
```

There is no wildcard, so `.env.backup` is covered for a default target and `.env.lock` is
not. Point `target.path` anywhere else and neither is:

```gitignore
.env.lock

# only if target.path is not the default
config/app.env
config/app.env.backup
config/app.env.lock
```

`env:doctor` checks both, reports the backup's mode, and warns when either sits in a git work
tree that nothing ignores. `php artisan env:doctor --fix` appends the missing entries for you,
anchored to the repository root:

```gitignore
# alexhackney/laravel-doppler: never commit these
/.token
/.env.lock
```

It appends only, never reorders or removes, and only entries the check just proved missing —
so it is safe to re-run and safe on a `.gitignore` you have hand-tuned.

The backup suffix is `.backup` rather than `.bak` precisely so the default case is covered by
Laravel's own file. The lock is never removed: unlinking a lock another process is holding
open is how a mutex stops being one.

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

Injecting works too, and **the type to inject is the contract**:

```php
use AlexHackney\Doppler\Contracts\Doppler;

public function __construct(private readonly Doppler $doppler) {}
```

`Doppler::fake()` swaps the contract binding, so a class type-hinting
`AlexHackney\Doppler\DopplerManager` cannot be intercepted by a fake and will talk to the
real Doppler in your tests. The concrete class stays resolvable for anyone who already
injects it, but the contract is the supported type.

`SyncResult` holds no secret values, so it is safe to log, queue, or return from a health
check.

## Testing your app

```php
use AlexHackney\Doppler\Facades\Doppler;

Doppler::fake(['APP_KEY' => 'base64:test', 'DB_PASSWORD' => 'x']);
Doppler::fakeUnavailable();          // exercise your soft-fail path
Doppler::fakeUnauthenticated();

Doppler::assertSynced();
Doppler::assertNotSynced();
Doppler::assertSyncCount(1);
```

Faking at the package boundary means your tests keep passing when this package changes how
it talks to Doppler.

The fake implements the same contract the real manager does, so it survives the container:
`Artisan::call('env:sync')` under a fake runs the real command against the fake source,
which is the thing actually worth testing about a deploy path.

```php
Doppler::fake(['APP_KEY' => 'base64:test']);

$this->artisan('env:sync')->assertSuccessful();

Doppler::assertSyncCount(1);
```

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
   every time. A token file in the repository checkout is one `git add .` from being
   published; `env:doctor` checks that something ignores it, and that it is not group or
   world readable. See [The token](#the-token).
5. **`${` interpolates in unquoted and double-quoted values.** A bare `$` is safe. The
   renderer escapes `$` in the only branch where it matters.
6. **A sync leaves `<target>.backup` beside the target, holding the previous secrets.**
   `0600`, one generation. Laravel's default `.gitignore` covers `.env.backup`; it covers
   nothing if you moved `target.path`. See [Files a sync leaves behind](#files-a-sync-leaves-behind).
7. **The rendered file is a build artifact, not a document.** Comments, grouping, and
   ordering are gone the first time this runs. Keys are sorted so diffs are stable and the
   no-change short circuit fires. `.env.example` becomes your only documentation.
8. **Doppler's rate limit is roughly 240 requests/minute.** A per-minute timer across a
   large fleet will find it. 429 and 5xx are retried with backoff; 401 never is.
9. **Rendering is not propagating.** Sync is near-simultaneous across a fleet, never atomic.
   A dangerous flip still needs a deliberate window.
10. **`VITE_*` values are baked at CI build time.** Changing one in Doppler changes nothing on
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
existing file, then hard fail. Every snapshot use logs its age, and warns above
`fallback.max_age_days` (30 by default), because silently booting on a six-week-old snapshot
would be worse than failing.

## Configuration reference

Every key in `config/doppler.php`. The sections above cover the ones with something to say;
this is the complete list, so nothing is only discoverable by reading the published config.

| Key | Env override | Default |
|---|---|---|
| `driver` | `DOPPLER_DRIVER` | `api` |
| `api.base_url` | `DOPPLER_API_URL` | `https://api.doppler.com/v3` |
| `api.timeout` | `DOPPLER_TIMEOUT` | `15` |
| `api.retries` | `DOPPLER_RETRIES` | `2` (5xx and 429 only; a 401 is never retried) |
| `api.retry_delay_ms` | `DOPPLER_RETRY_DELAY_MS` | `500` |
| `cli.binary` | `DOPPLER_BINARY` | `doppler` |
| `cli.timeout` | `DOPPLER_CLI_TIMEOUT` | `30` |
| `token.files` | — | `[base_path('.token'), '/etc/doppler/.token']` |
| `token.env_var` | — | `DOPPLER_TOKEN` |
| `target.path` | `DOPPLER_TARGET_PATH` | `base_path('.env')` |
| `target.grammar` | `DOPPLER_TARGET_GRAMMAR` | `laravel` |
| `target.backup` | `DOPPLER_BACKUP` | `true` |
| `soft_fail` | `DOPPLER_SOFT_FAIL` | `false` |
| `preserve` | — | `['APP_KEY']` |
| `overlay` | `DOPPLER_OVERLAY` | `null` |
| `hooks` | — | `['artisan:config:clear']` |
| `validate.required` | — | `[]`, or the string `'env.example'` |
| `validate.absolute_url` | — | `[]` |
| `validate.not_loopback` | — | `[]` |
| `validate.no_control_chars` | — | `true` |
| `validate.no_placeholder` | — | `true` |
| `validate.no_placeholder_ignore` | — | `[]` |
| `validate.matches` | — | `[]` |
| `validate.allow_dynamic_secrets` | — | `false` |
| `validate.rules` | — | `[]` — class names implementing `AlexHackney\Doppler\Validation\Rule` |
| `fallback.enabled` | `DOPPLER_FALLBACK_ENABLED` | `false` |
| `fallback.path` | `DOPPLER_FALLBACK_PATH` | `/etc/doppler/snapshot.enc` |
| `fallback.max_age_days` | `DOPPLER_FALLBACK_MAX_AGE_DAYS` | `30` |
| `fallback.passphrase` | `DOPPLER_FALLBACK_PASSPHRASE` | `null` — derive from the token |
| `profiles` | — | `[]` |

Two are worth calling out because they are not mentioned anywhere else:

- **`target.backup`** keeps one `<target>.backup` generation next to the target, `0600`, so
  a bad render can be rolled back by hand mid-incident without going back to Doppler. One
  generation, not a history: the previous file, and nothing older. See
  [Files a sync leaves behind](#files-a-sync-leaves-behind).
- **`validate.rules`** takes your own rule classes. Implement
  `AlexHackney\Doppler\Validation\Rule` and it runs alongside the built-in ones, with the
  same per-key consequence reporting.

## Upgrading

### 0.2 → 0.3

Four behaviour changes. None needs a config change; two may need a `.gitignore` line or a
type-hint.

1. **The backup file is `<target>.backup`, was `<target>.bak`.** Laravel's own `.gitignore`
   lists `.env.backup`, so the default case is now covered instead of committable. Delete any
   stale `.env.bak` by hand — nothing cleans it up — and drop `.env.bak` from your
   `.gitignore` if you added it.
2. **Type-hint `AlexHackney\Doppler\Contracts\Doppler`, not `DopplerManager`.** The facade
   and the container now resolve the contract, which is what makes `Doppler::fake()` work
   inside artisan commands. Injecting the concrete class still resolves, but a fake cannot
   intercept it, so those tests silently hit the real Doppler.
3. **Exit code 8 is new.** A rejected request — bad project or config name, malformed request
   — used to exit 4, "authentication failed, rotate the token", for a problem that has
   nothing to do with the token. Anything branching on 4 should now also handle 8.
4. **`required` refuses `null`, `(null)`, `empty` and `(empty)` under the `laravel` target,**
   and refuses outright when `'required' => 'env.example'` points at a file it cannot read.
   Both were passing silently before. If a run starts failing here, it was already broken.

Run `php artisan env:doctor --fix` once after upgrading. It adds the `.gitignore` entries for
whatever this package writes into your repository, including the renamed backup file.

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
composer analyse    # PHPStan level 8, over src and tests, no baseline
composer format     # Pint
```

`composer analyse` needs PHP 8.4 locally (the Pest PHPStan plugin requires it). The package
itself supports 8.2 up, and the test matrix proves it.

## License

MIT. See [LICENSE](LICENSE).
