# Contributing

## Getting set up

```bash
composer install
composer test      # pest
composer analyse   # phpstan level 8 over src AND tests, no baseline
composer format    # pint
```

All three must pass before a pull request is ready.

`composer analyse` needs **PHP 8.4**, because `pestphp/pest-plugin-phpstan` does and without
it the test suite cannot be analysed at all. That is a constraint on contributing, not on
using: the package itself supports 8.2 up, and CI proves it across the whole matrix. CI runs them across PHP 8.2 to 8.5 and
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

**The decoder gate is closed.** On 2026-08-20 a real `dp.st.` service token ran against real
Doppler on a production box: 175 keys decoded, rendered, and passed `RoundTripGuard`, which
runs on the `--dry-run` path too. `format=json` returns the flat `{KEY: value}` object
`ApiSource::decode()` assumed — no envelope, no per-key structure. Recorded in
`.claude/docs/real-api-confirmation.md`.

Invariant 1 was confirmed on the same box the same day, by counting quoted and empty lines
rather than reading values: 173 keys, 168 single-quoted, 5 legitimately empty, none unquoted.

Note what that leaves untested against real data: no value in a 173-key production config
contained a newline or a single quote, so the renderer's double-quote branch has still only
ever been exercised synthetically.

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
   empty strings — and under the `laravel` target, "blank" includes the four literals
   `Illuminate\Support\Env` converts to nothing (`null`, `(null)`, `empty`, `(empty)`).
   That conversion is Laravel's, not phpdotenv's, so it is grammar-scoped.
8. **A rule that cannot enforce its contract says so.** `'required' => 'env.example'` with an
   unreadable example file is a refusal, not an empty rule set. A silent no-op in a
   validation rule is worse than no rule at all, because it reports a clean pass.
9. **`Fake` and `DopplerManager` implement the same contract, and the container binds the
   contract.** `Facade::swap()` rebinds the facade accessor, so if the accessor is ever moved
   back to the concrete class, every type-hinted resolution breaks the moment a consuming app
   calls `Doppler::fake()`. There is a test for this; it is not decoration.

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
