# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 1.x | yes |
| 0.x | yes, until 1.0 lands |

## Reporting a vulnerability

Email **alex@alexhackney.com** with the details. Please do not open a public issue for a
security problem.

Include what you have: the affected version, what an attacker can do, and a reproduction if
you have one. You will get an acknowledgement within 72 hours and an assessment within a
week.

## What counts as a vulnerability here

This package handles production secrets, so the bar is lower than usual. All of these are
security problems, not bugs:

- **Any path that writes a secret value to stdout, stderr, a log, or an exception message.**
  The only intended exception is `env:sync --stdout`, which exists to print the rendered
  file and still never prints the token.
- **Any path that writes the token anywhere**, including a redacted-looking rendering that
  leaks more than the documented `dp.st.****abcd` form.
- **A rendered file that is world or group readable**, at any point, including the temporary
  file before the rename.
- **A round trip that silently changes a value**, since that can substitute one secret for
  another. The guard exists to make this impossible; a way around it is a vulnerability.
- **A snapshot that can be decrypted without the token or passphrase**, or that is written
  with permissions other than 0600, *including transiently* — creating the file at the umask
  default and chmod-ing it afterwards counts.
- **A file this package writes beside the target that holds secrets and is not 0600.** Today
  that is `<target>.backup`, which carries every secret from the previous render.

If you find a way to make any of those happen, it is worth reporting even if it needs an
unusual configuration.

## What does not count

- Passing a token with `--token`, which is documented as visible in `ps` and in shell
  history, and warns on every use.
- A secret being readable by a user who can already read the target file or run artisan as
  the application user. That user has the secrets by definition.
- A token file left group or world readable, or committed to a repository. Both are operator
  choices the package can only warn about, and `env:doctor` does. See
  [The token](README.md#the-token) for how to provision one properly.
- Doppler-side issues. Report those to Doppler.

## Hardening the token

The package never writes the token anywhere and never prints more than `dp.st.****abcd`, so
the remaining exposure is entirely in how the credential is provisioned. Three rules cover it:

1. **Mode `0600`, owned by the user that runs `php artisan env:sync`** — the deploy user, or
   root under a systemd timer. That is often *not* the php-fpm user, which never needs to read
   the token at all. If the sync cannot read the file, the fix is `chown`, never `chmod 644`.
2. **If it lives in the repository checkout, something must ignore it.** `base_path('.token')`
   is a supported and sometimes correct location, and it is one `git add .` from a public
   repository. Add `.token` to `.gitignore` in the same commit that starts using it — and
   `<target>.backup` and `<target>.lock` alongside it, unless your target is the default
   `.env`, in which case Laravel's own `.gitignore` already covers the backup.
3. **Prefer a service token (`dp.st.`)**, which is read-only and scoped to a single config. A
   personal token addresses the entire workspace, so a leak is worth far more.

`php artisan env:doctor` checks the first two and names the token type for the third. It makes
no network call and prints no secret value. The full reasoning, with provisioning commands, is
in [The token](README.md#the-token).
