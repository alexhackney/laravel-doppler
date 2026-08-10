# Contributing

## Getting set up

```bash
composer install
composer test      # pest
composer analyse   # phpstan level 8, no baseline
composer format    # pint
```

All three must pass before a pull request is ready. CI runs them across PHP 8.2 to 8.5 and
Laravel 12 and 13, plus a `--prefer-lowest` run so an under-constrained dependency fails
here rather than in somebody's application.

## Commit messages decide the version

Releases are automated. The version is computed from commit subjects since the last tag, so
the subject line is not cosmetic:

| Subject | Effect |
|---|---|
| `feat: ...` | minor bump (stays within 0.x while below 1.0) |
| `fix: ...` | patch bump |
| `feat!: ...` or a `BREAKING CHANGE:` footer | major bump, or minor while below 1.0 |
| `docs:`, `refactor:`, `perf:`, `test:`, `build:`, `ci:`, `chore:`, `style:`, `revert:` | no release |

On a squash merge the pull request title becomes the commit subject, so **the title is what
matters**. CI checks it.

```
feat: add a vault secret source
fix(renderer): escape a dollar inside a double-quoted value
feat(sources)!: rename the driver config key
```

## How a release happens

1. Merge a `feat` or `fix` pull request into `main`.
2. release-please opens or updates a standing `chore: release X.Y.Z` pull request, with the
   CHANGELOG entry written for you.
3. Merge that pull request. The tag and the GitHub release are created automatically.
4. Packagist picks up the tag through its webhook. Nothing else to do.

Nobody tags by hand, and nobody picks a version by hand.

### Going to 1.0

Deliberate, not automatic. `bump-minor-pre-major` keeps `feat` at 0.x, so 1.0 only happens
when someone asks for it: put a `Release-As: 1.0.0` footer in a commit body.

Do not do this until the Doppler API response decoder has been confirmed against the real
API. Every test uses `Http::fake()`, so the decoder is built from the documented
`format=json` contract. If it turns out to be wrong, fixing it in 0.x costs a minor bump
instead of a major one.

## Design invariants

These are load-bearing, each has a test, and breaking one is a correctness regression rather
than a style change. If a change requires breaking one, say so explicitly in the pull
request.

1. **Never emit an unquoted value.** `K=has space` is a hard parse error that kills the whole
   file; `K=has#hash` truncates silently at the hash.
2. **Single quotes by default.** The only fully literal form in the phpdotenv grammar.
3. **Nothing is written unless it round-trips.** The guard runs on every path including
   `--dry-run`, and it uses the full phpdotenv load path rather than the parser, because
   interpolation happens in the loader. See [docs/phpdotenv-grammar.md](docs/phpdotenv-grammar.md).
4. **Every failure path leaves the previous file byte-identical.**
5. **Soft fail stays conditional.** Only a transient failure, and only when the existing file
   is present, non-empty, and passes validation.
6. **No command prints a secret value or a full token.** `tests/Feature/SecretLeakageTest.php`
   enforces this with canary values across every command.
7. **`required` tests blankness, not presence**, because Doppler emits held-but-unset keys as
   empty strings.

## Testing notes

Two traps worth knowing before you write a test here:

- **Use `Artisan::call()` plus `Artisan::output()` when asserting on whole output**, not
  `$this->artisan()`. The latter returns a `PendingCommand` with its own buffer that
  `Artisan::output()` cannot see, so reading it yields an empty string and every
  `not->toContain` assertion passes vacuously.
- `$this->artisan()` with `expectsOutputToContain` consumes the whole matched output block,
  so two assertions against the same emitted block will not both match. One per run.

`Http::preventStrayRequests()` is on in `TestCase`. A stray request is a failure, not a
warning. No test may reach the network or require the Doppler CLI binary.

## Reporting a security issue

See [SECURITY.md](SECURITY.md). Do not open a public issue.
