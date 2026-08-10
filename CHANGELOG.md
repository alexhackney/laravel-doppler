# Changelog

All notable changes to `alexhackney/laravel-doppler` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
  `.claude/docs/phase-0-grammar-findings.md`. The material one: **interpolation happens in
  the loader, not the parser**, so a guard built on `Dotenv\Parser\Parser` alone would pass
  a `${...}` value that then corrupts silently at boot. The guard uses the full load path.
- `\$` inside double quotes is honoured by phpdotenv, which settles the open question from
  the design and confirms the two-branch quoting algorithm needs no third case.

### Notes

- Requires PHP 8.2+ and Laravel 11, 12, or 13.
- PHPStan level 8 with no baseline. Pint with the Laravel preset and strict types.
