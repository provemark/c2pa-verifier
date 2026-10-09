# Step 316 — A timestamp header holds one token (SPEC-017 amendment 9; fix F1)

*2026-10-09.*

Step 314 measured that a signer that had expired, with two valid tokens
from a trusted TSA in `sigTst2`'s `tstTokens`, was `Trusted` here and
`Invalid` in both `c2patool` versions. This verifier judged the first
token and used its time (SPEC-017 AC8). An older `c2pa-rs` did the same,
but 0.91.1 drops such a header. C2PA 2.4 §15.8.1.1 says it plainly: more
than one `tstToken` makes the validator issue `timeStamp.malformed`.

## What changed

- **`TimestampCheck::checkHeader()`** returns one `timeStamp.malformed`
  for a header with more than one token. It names the header and the
  count, with `present` true and no time, before any token is read. The
  signer is then judged at the current time, as with no timestamp.
- **SPEC-017 amendment 9:** the scope paragraph and AC8 rewritten.
- **SPEC-062 amendment 1:** the two probes as fixtures in `tsa-matrix` (31
  probes). `expired-signer-two-tokens` joins `SPEC062_CODES_DIFFER`, where
  0.28.1 adds `signingCredential.untrusted`, as for the other expired
  signers.
- **`bin/make-tsa-matrix.php`:** a `tokens: 2` option.
- The folder's README, the CHANGELOG, and `docs/reading-c2pa-2.4.md` (L3
  fixed).

## Measured

- **Tests first.** The SPEC-017 AC8 test and the timestamp matrix with 31
  probes: 3 failed (the drift alarm fired on the expired signer; AC8 still
  saw "1 of 2 tokens judged"). After the change: all 70 timestamp tests
  passed.
- **The corpus.** 846 files under no settings and 158 settings files,
  before and after: 318 runs moved, all of them the two new probes. Under
  every settings file only their report changed; under their own settings
  `expired-signer-two-tokens` went from `Trusted` to `Invalid`. No real
  file moved.
- **The fuzzer.** Seed 20261005 × 60 without settings: 0 faults, the same
  238 suspects. With `--trust` (238 pairs): 20261005 × 60 gave 531
  suspects and 20261009 × 200 gave 1,755. Each was judged by `c2patool`
  0.28.1 under the same settings, and none is more lenient here.
- `composer check`: 955 passed. PHPStan in Docker `php:8.3-cli`: no
  errors.
