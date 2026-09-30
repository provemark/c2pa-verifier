# Step 199 — SPEC-054 built: a requirements check older PHP can read

`requirements.php` at the package root. PHP 7.4 syntax, no declarations; it
returns a closure that answers `supported`, `missing` and `ed25519`. A host
that also runs older PHP requires it first and loads `src/` only on
`supported`.

## What was built

- `requirements.php`. The closure takes an optional version and extension
  list (defaults: this PHP). A version must start with `major.minor.patch`
  to be read at all; an extension name counts only as a string, compared
  in lower case. Missing items in fixed order: `php>=8.3`, `ext-openssl`,
  `ext-mbstring`.
- `bin/package-check.php`: `requirements.php` in `PACKAGE_SHIPPED`.
- `phpstan.neon`: the file in the analysed paths (level max).
- README: "Before loading, on a host that also runs older PHP", and a
  paragraph under "Public API" making the file and its shape part of the
  contract.

## Amendment 2

Built to the formula in the scope, the probe printed `"ed25519":true` on
7.4–8.2, where the images have sodium but the verifier cannot run. The
spec's own sentence ("can be verified here") wins: `ed25519` now requires
`supported`. One test was added for it (AC3, 8.2 with sodium). It was
written after the change; its red was seen in the probe output of the
version before (`"ed25519":true` on 8.2.34), not as a failing Pest test.

## Measured

- `vendor/bin/pest --group=SPEC-054` on PHP 8.5.8 and 8.3.33: 14 of 15
  green before the commit; AC7 red because `git archive` packs `HEAD`.
  After the commit: 15 of 15 on both.
- `php -l requirements.php` and the probe with `--assert-unsupported` in
  `php:7.4-cli` (7.4.33), `8.0` (8.0.30), `8.1` (8.1.34), `8.2` (8.2.34):
  each exit 0, `supported` false, `missing` `["php>=8.3"]`, `ed25519`
  false, `declared` empty, both copies the same answer.
- `composer check`: spec-check, api-check, Pint, PHPStan, Deptrac clean;
  Pest 620 passed, 1 failed (AC7, before the commit). After the commit,
  `composer check` and `composer test:parallel`: 621 passed.

The CI job `older-php` has not run yet; it runs on the next push.
