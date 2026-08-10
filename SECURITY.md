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
  with permissions other than 0600.

If you find a way to make any of those happen, it is worth reporting even if it needs an
unusual configuration.

## What does not count

- Passing a token with `--token`, which is documented as visible in `ps` and in shell
  history, and warns on every use.
- A secret being readable by a user who can already read the target file or run artisan as
  the application user. That user has the secrets by definition.
- Doppler-side issues. Report those to Doppler.
