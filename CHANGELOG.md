# Changelog

All notable changes to `alexhackney/laravel-doppler` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0](https://github.com/alexhackney/laravel-doppler/compare/v0.2.0...v0.3.0) (2026-08-20)


### ⚠ BREAKING CHANGES

* bind a Doppler contract, rename the backup file, split request rejection from auth failure ([#3](https://github.com/alexhackney/laravel-doppler/issues/3))

### Added

* bind a Doppler contract, rename the backup file, split request rejection from auth failure ([#3](https://github.com/alexhackney/laravel-doppler/issues/3)) ([b21751a](https://github.com/alexhackney/laravel-doppler/commit/b21751affec791de922bec2b464ca5f883c21247))

## [0.2.0](https://github.com/alexhackney/laravel-doppler/compare/v0.1.0...v0.2.0) (2026-08-12)

### Added

* `doppler.validate.no_placeholder_ignore`, a list of keys exempt from the placeholder rule.
  `NoPlaceholderRule` already accepted an ignore list, but `Validator::fromConfig()`
  constructed it with no arguments and no config key fed it, so the only way to exempt a key
  was to register a subclass through `validate.rules`
  ([#1](https://github.com/alexhackney/laravel-doppler/issues/1))
  ([7819848](https://github.com/alexhackney/laravel-doppler/commit/781984847bece102191c223444e936f8c35af11a)).

### Changed

* `env:diff` prints every validation problem with its consequence text, instead of only the
  count. It is the command you schedule, so it is where the detail matters most: a count
  with no keys leaves an operator with nothing to act on but a rerun. The header is worded
  for a read-only command rather than reusing the exception message, which ends "Nothing was
  written", and there is no `--force` advice because `env:diff` has no such flag.
* `no_placeholder` no longer treats `null` or `none` as leftovers. phpdotenv resolves `null`
  to a real null and Laravel's own `.env.example` ships `MAIL_ENCRYPTION=null` and
  `REDIS_PASSWORD=null`; `SESSION_SAME_SITE=none` is a real setting. Both were refusing valid
  configuration. A key that must not be blank belongs in `required`, where the consequence
  text lives.


### Added

* surface validation detail in env:diff, add no_placeholder_ignore ([#1](https://github.com/alexhackney/laravel-doppler/issues/1)) ([7819848](https://github.com/alexhackney/laravel-doppler/commit/781984847bece102191c223444e936f8c35af11a))

## [0.1.0] - 2026-08-10

First tagged release. Deliberately 0.x rather than 1.0: the Doppler API response decoder is
built from the documented `format=json` contract and has not yet been exercised against the
real API. If it needs to change, doing that in 0.x costs a minor bump instead of a major one.
Tag 1.0 once a real adoption confirms the shape.

### Added

#### Core

- `env:sync` renders a Laravel `.env` from Doppler, with every flag from the design:
  `--token`, `--token-file`, `--project`, `--config`, `--profile`, `--path`, `--target`,
  `--driver`, `--only`, `--except`, `--dry-run`, `--stdout`, `--force`, `--soft-fail`,
  `--no-soft-fail`, `--no-hooks`, `--fallback`, `--fallback-only`, `--write-fallback`.
- `env:diff`, the read-only half. Exits 10 on drift, so it is safe to schedule.
- `env:doctor`, which diagnoses the setup with no network call and no secrets printed.
- `env:snapshot`, which writes the encrypted fallback without rendering.
- Stable exit codes 0 through 7 plus 10, so a deploy script, a systemd timer, and a monitor
  can tell an outage apart from a defect.

#### Rendering

- Round-trip guard. Rendered bytes are loaded back through the **full phpdotenv stack**
  before anything is written, and compared value by value. Any mismatch is a refusal naming
  keys only.
- Four target grammars: `laravel`, `systemd`, `docker`, `shell`. `systemd` and `docker`
  refuse a multi-line value rather than emitting something that half-works.
- Deterministic key ordering, so diffs are stable and the no-change short circuit fires.

#### Sources

- `api` driver (default), calling the Doppler HTTP API with `format=json` so this package
  owns its own escaping. No binary to provision.
- `cli` driver, for boxes where only the Doppler CLI has egress.
- `snapshot` driver, reading an encrypted local copy with no network access.
- 429 and 5xx retried with linear backoff. A 401 is never retried.

#### Safety

- Atomic writer: `flock`, temp file **in the target's directory**, `chmod 0600`, ownership
  resolution, one `.bak` generation, `fsync`, then `rename`. Every failure path leaves the
  previous file byte-identical.
- Soft fail, conditional on the failure being transient **and** the existing file being
  present, non-empty, and passing validation. Logs at error level and prints a banner.
- Validation rules with consequence text: `required` (tests blankness, not presence),
  `absolute_url`, `not_loopback`, `no_control_chars`, `no_placeholder`, `matches`,
  `dynamic_secrets`. Custom rules supported.
- Post-sync hooks with enforced ordering: `config:clear` first, and a failing `config:cache`
  aborts everything after it.
- `preserve` for keys carried forward from the existing file, `overlay` for host-local
  values merged on top.
- Named profiles for boxes serving more than one app or config.
- Encrypted fallback snapshot, AES-256-GCM with a PBKDF2-derived key.

#### Developer experience

- `Doppler` facade over a `DopplerManager` service, with `sync()`, `dryRun()`, `secrets()`
  and `profile()`.
- `Doppler::fake()`, `fakeUnavailable()`, `fakeUnauthenticated()`, `assertSynced()` and
  `assertSyncCount()` so consuming apps never stub HTTP by hand.
- Tokens redacted to `dp.st.****abcd` in every rendering, including `__toString`,
  `__debugInfo`, and exception traces.

### Verified

- phpdotenv 5.6 grammar probed directly rather than assumed. Findings recorded in
  [docs/phpdotenv-grammar.md](docs/phpdotenv-grammar.md). The material one: **interpolation happens in
  the loader, not the parser**, so a guard built on `Dotenv\Parser\Parser` alone would pass
  a `${...}` value that then corrupts silently at boot. The guard uses the full load path.
- `\$` inside double quotes is honoured by phpdotenv, which settles the open question from
  the design and confirms the two-branch quoting algorithm needs no third case.

### Notes

- Requires PHP 8.2+ and Laravel 12 or 13. Laravel 11 is deliberately unsupported: every
  11.x release carries unpatched advisories and Composer blocks them by default.
- PHPStan level 8 with no baseline. Pint with the Laravel preset and strict types.

[unreleased]: https://github.com/alexhackney/laravel-doppler/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/alexhackney/laravel-doppler/releases/tag/v0.1.0
