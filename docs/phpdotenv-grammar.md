# Phase 0 findings: phpdotenv grammar, verified

Probed directly against `vlucas/phpdotenv` 5.6.4 on PHP 8.4.17, 2026-08-10.
Scripts: `probe-dotenv.php` (Parser only) and `probe-loader.php` (full load path).

These findings are what `LaravelRenderer` and `RoundTripGuard` are built on. Re-run the
probes before upgrading phpdotenv across a major version.

## 1. Interpolation happens at LOAD time, not PARSE time

This is the finding that shaped the guard, and it is not in the original plan.

`Dotenv\Parser\Parser` does **not** interpolate. Parsing `K=pre${BASE}post` returns the
literal string `pre${BASE}post`. Interpolation happens later, in `Dotenv\Loader\Loader`
via the resolver, which is what Laravel's `LoadEnvironmentVariables` bootstrapper runs.

| Document | Parser result | Load result |
|---|---|---|
| `BASE=secretbase` + `K=pre${BASE}post` | `pre${BASE}post` | `presecretbasepost` |
| `BASE=secretbase` + `K="pre${BASE}post"` | `pre${BASE}post` | `presecretbasepost` |
| `BASE=secretbase` + `K='pre${BASE}post'` | `pre${BASE}post` | `pre${BASE}post` |

**Consequence:** a round-trip guard built on `Parser` alone is worthless against the
interpolation class of bug. It would pass a double-quoted `${` value that then silently
corrupts at boot. `RoundTripGuard` therefore loads rendered content through the full
phpdotenv stack (`Dotenv::create` with a fresh isolated `ArrayAdapter`, immutable), which
is exactly what Laravel does.

Interpolation resolves against keys defined **earlier in the same file** only. A forward
reference stays literal, which makes it order-dependent and another reason to never emit
an unescaped `${`.

## 2. `\$` inside double quotes IS honoured

The plan listed this as unverified and as the thing that decides whether the writer needs
a third branch. It does not.

| Document | Load result |
|---|---|
| `K="pre\${BASE}post"` | `pre${BASE}post` |

The two-branch algorithm in plan section 4.4 stands as written. Escaping `\` then `"` then
`$` in the double-quoted branch is sufficient.

Note `K="pre$\{BASE}post"` is a hard parse error (`unexpected escape sequence`), so the
escape must go on the `$`, never on the brace.

## 3. Single quotes are fully literal

Every one of these round-trips exactly through the full load path when single-quoted:

`p#ss`, `p ss`, `pa$$word`, `pre${BASE}post`, `has"dquote`, `C:\path\to`, `tab\there`,
UTF-8, and the empty string.

Single quotes are the correct default. The only two values they cannot express:

- a value containing a single quote
- a value containing a newline (`'a<LF>b'` is a hard parse error, `missing closing quote`)

Both fall to the double-quoted branch.

## 4. Unquoted values are never safe to emit

- `K=has space` is a **hard parse error** that kills the entire file, not just the key.
- `K=has#hash` silently truncates to `has`, with no preceding whitespace required.
- `K=pre${BASE}post` interpolates at load.

The renderer never emits an unquoted value. This is the `env-no-quotes` bug class from
plan section 3.1, closed by construction.

## 5. Control characters

- `K="a\rb"` parses, and the escape produces a real CR.
- A real CR inside single quotes is a parse error, same as LF.

`NoControlCharsRule` rejects these at validation time rather than relying on the renderer,
because a CR riding inside a credential is a defect in the secret, not a rendering problem.

## Table corrections against the original plan

The plan's section 4.4 table described load-time behaviour but attributed it to parsing.
Everything in it is correct as a description of what Laravel ends up with. The single
material addition is finding 1: the guard has to use the load path, not the parser.
