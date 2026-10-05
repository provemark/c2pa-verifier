# Step 208 — SPEC-055 built: WAV is read and verified

*2026-10-05. `c2patool` 0.27.22 and 0.28.1 as the oracles.*

## What was built

- `src/Container/WavManifestStoreExtractor.php`: SPEC-003's RIFF walk
  (`RiffManifestStoreExtractor`, step 206) with the form type `WAVE` and
  the name `WAV`. Twenty-five lines; no new rule.
- `FormatDetector`: `RIFF` + `WAVE` is `wav`. `RF64` and every other RIFF
  form stay `null`.
- `Verifier`: a `wav` arm, the WAV extractor as the **last** constructor
  parameter (a caller passing the others by position since 0.2 is not
  moved), and the unknown-format message naming WAV.
- SPEC-024 AC1's test checks the WAV extractor's bound as well.

## Measured

- `vendor/bin/pest --group=SPEC-055`: 37 passed (step 207: 37 failed).
- `composer check`: exit 0, **658 tests** (621 + 37). `bin/api-check.php`:
  85 public classes, 11 in the contract, 74 internal; the recorded
  contract surface is unchanged. `bin/package-check.php`: 388 files in the
  dist.
- **The corpus**, against step 206's baseline (1,212 runs over 606 files):
  185 files changed hash. All but 22 of them differed only in the
  unknown-format message, which now names WAV. With that one phrase
  normalised, **22 files moved, and all are expected**: the two WAV
  fixtures, 19 of the 21 WAV variants (`riff-form-xxxx` and `rf64` stay
  `unknown`), and `webp/riff-not-webp.webp`.
- The new verdicts, by file, are in `tests/Fixtures/wav/README.md` (the
  SPEC-055 column). In short: the signed fixture is `Valid` without
  settings and `Trusted` with them, code for code as both `c2patool`
  versions say. The three files whose `C2PA` chunk is not last, and the
  odd chunk before it, are `Invalid` with `assertion.dataHash.mismatch`,
  as `c2patool` says. The malformed containers are `Invalid` with
  `general.error`, naming the fault. `c2pa-in-list` is no manifest, as in
  `c2patool`. `fixture-unsigned.wav` reports like `fixture-unsigned.webp`.

## Amendments, all awaiting confirmation

- **SPEC-013 amendment 15** (weight B): the report's `format` can be
  `wav`; the unknown-format message names WAV; the constructor's new last
  parameter; `webp/riff-not-webp.webp` moves from `unknown` to `wav`,
  `Invalid` with `assertion.dataHash.mismatch`, equal to `c2patool`.
- **SPEC-024 amendment 2**: AC1's list gains WAV.
- **SPEC-055 amendment 1**: the SPEC-025 amendment its open question
  expected is not needed (`@internal` is what SPEC-025 asks).

## Public text

README (the two format lists), `docs/comparison.md` (the formats row, and
one new row under *differs by design*: an LBox that disagrees with the
RIFF chunk length is refused here and `Valid` in both `c2patool` versions,
for WebP and WAV alike), CHANGELOG `Unreleased`.

## A correction

Step 204's note said `docs/comparison.md` still called WebP's
`length-differs` "stricter than the oracle". It did not: steps 107–118
had already recorded 0.28.0 refusing it. The note now says so. No separate
step is needed.

## Not done

No release. No fuzzing yet; that belongs to the check before a release,
as in steps 194 and 200.
