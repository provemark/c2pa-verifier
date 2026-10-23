# Step 396 — SPEC-073 amendment 1: the clock is read once

*2026-10-23.* A low item of the review of 2026-10-23, under Maurice's
"doe 1 nog".

## The finding

Without a given validation time, `verify()` recorded `time()` for the
report, and the certificate profile, the chain and OCSP each read
`time()` again. A run that crossed a second could record one second and
judge at another, and the checks could disagree with each other.

## The fix

`verify()` reads the clock once and passes it through `inspect()` and
`check()` to the active manifest's checks and to every ingredient
manifest: `$at = $trusted ?? $now ?? $clock`. The certificate's
explanation names "now" itself; it used to infer it from a null time.

## Measured

- Red first: 2 of 3 new tests (`inspect()` given a fixed clock:
  `ocsp.jpg`'s signer and `video1.mp4`'s ingredient signer expired or not
  by that clock). The third, the recorded reading, held already within a
  second.
- The first fix said "checked at the timestamp's time" where it meant
  "now": the tests were sharpened to read the wording, then green.
- Mutations, each failing: the active manifest ignoring the clock, an
  ingredient ignoring it, "now" not named.
- The corpus before and after: 0 of 1,854 runs moved.
- `composer check`: 1,296 passed, 1 skipped.
