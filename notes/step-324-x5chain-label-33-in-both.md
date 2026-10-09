# Step 324 — A chain under label 33 in both headers is refused (C1, SPEC-047 amendment 2)

*2026-10-09.*

C2PA 2.4 §14.5: *"if this header appears in both the protected and
unprotected buckets with the same label, a validator shall reject the
claim signature as malformed due to the presence of multiple
credentials."* SPEC-047 AC2 refused a protected chain beside an
unprotected `"x5chain"`. Label 33 is never read from the unprotected
header (AC1), so a protected chain beside an unprotected label 33 was
`Trusted`. Step 318 measured it with `manifest-probes/x5chain-unprotected-too.png`:
`Trusted` in both `c2patool` versions and here. Maurice chose to follow
the specification.

## What changed

- **`CoseSign1::findChain()`** treats either label in the unprotected
  header, beside a protected chain, as a second credential. It uses AC2's
  code (`signingCredential.invalid`) and reason. Label 33 is still never
  read from the unprotected header.
- SPEC-047 amendment 2 (AC6). The fixtures' README, the CHANGELOG, and
  `docs/reading-c2pa-2.4.md` (C1 adopted; the §14.2 row covered).

## Measured

- **Tests first.** AC6 in `tests/Unit/Cose/X5chainPlacementTest.php`:
  red (`Trusted` where `Invalid` was expected), then green. The status
  appears three times in the report, once wherever the COSE is read.
  `x5chain-both.png` (AC2) does the same, so that is not new.
- **The corpus.** 875 files under no settings and 160 settings files,
  before and after: 161 runs moved, all of them the probe. No real file
  moved.
- **The fuzzer.** Seed 20261005 × 60 without settings: 0 faults, 238
  suspects. With `--trust` (254 pairs): 20261005 × 60 gave 534 suspects
  and 20261009 × 200 gave 1,765. Each was judged by `c2patool` 0.28.1,
  and none is more lenient here.
- `composer check`: 972 passed.
