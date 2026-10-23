# Step 369 — SPEC-073: a validation time given by the caller, built

*2026-10-23.* Approved by Maurice without changes, then built tests-first.

## What changed

- `Verifier::verify($stream, $settings, $validationTime)` and
  `FragmentedVerifier::verify(…, $validationTime)` take an optional
  `DateTimeInterface`. `getTimestamp()` makes it whole seconds, so a
  fraction is rounded down.
- Where no trusted timestamp gives a time, it stands in for the clock:
  `$at = $trusted ?? $now`, in `Verifier::check()` and in
  `IngredientManifestCheck::manifest()`. The checks that fell back to
  `time()` (`ChainCheck`, `OcspCheck`, `CertificateProfileCheck`) are
  unchanged: they receive the time as before and still use the clock
  when given none.
- `CertificateProfileCheck` takes an optional `$timeName`, so an expired
  signer's explanation says `checked at the given validation time`
  instead of `now`.
- `bin/c2pa-verify --at <RFC 3339>` (or `--at=`). Every field is
  range-checked, so `2026-02-30` is refused rather than rolled into March.
  A leap second (`:60`) is refused, since no epoch holds it. A refusal is
  exit 2 with the usage. The usage line itself is SPEC-019's and is
  unchanged; `--at` is described in the lines below it.

## Red first

```
vendor/bin/pest --group=SPEC-073
```

- First run: 7 failed, 7 passed. The five AC5 refusals passed for the
  wrong reason (`--at` was an unknown option, exit 2 already), so they
  were made to require "RFC 3339" in the message: 13 failed, 1 passed.
- AC3 (a trusted timestamp wins) could not be red: PHP ignores an extra
  argument, so the behaviour it pins already held.
- One bug on the way: the private `Verifier::check()` first did not
  declare the new parameter. PHP dropped the argument silently and every
  criterion stayed red until it did.

Green: 14 passed. Three mutations, each watched failing:

| mutation | fails |
|---|---|
| `$at = $now ?? $trusted` (the given time before the timestamp's) | AC3 |
| the fraction rounded up | AC2, "notAfter and nine tenths" |
| `IngredientManifestCheck::check()` not given the time | AC4 |

AC4 named its status when written, as the spec allowed: at
`2025-08-14T00:00:00Z` the stapled response of `c2pa-rs/ocsp.jpg` gives
`signingCredential.ocsp.notRevoked`.

## AC6: nothing moves without a time

Measured: the corpus script (every file under `tests/Fixtures`, without
settings and with `trust/full.settings.json`, the current date masked) on
a worktree at `2830320` with `vendor/` **copied**, and on this tree:
1,842 runs each, **0 differ**.

`composer check`: green, 1,131 passed, 1 skipped.

## Not done here

- The CHANGELOG and README entries belong to the release commit, as
  before. An optional parameter on a contract method makes it 0.8.0.
- The time used is not a report field yet; crJSON's `validationTime`
  is the place for it.
