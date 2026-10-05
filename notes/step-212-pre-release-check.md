# Step 212 — The check before 0.3.0 (part one: local)

*2026-10-05. `c2patool` 0.27.22 and 0.28.1.*

## What was checked

- **Specs.** `php bin/spec-check.php`: all 55 specifications are
  `implemented`; no amendment waits for confirmation or approval.
- **`composer check`**: exit 0, 665 tests.
- **The fuzzer learned WAV.** `bin/fuzz.php` globbed only
  `jpg`/`jpeg`/`png`/`webp` in a directory, and read every RIFF file with
  the WebP extractor, so a WAV's store was never found and the
  store-targeted mutations (`store8`, `store64`, `storecut`) skipped every
  WAV. It now globs `wav` and reads a `WAVE` form with the WAV extractor.
  Three lines; tooling, not product code.
- **Fuzzing,** as in step 194, with the WAV files added:
  `php bin/fuzz.php 20261005 60 <out>` in `tests/Fixtures`, over
  `public-testfiles`, `c2pa-rs`, `writers`, `binding`, the signed JPEG,
  PNG, WebP, MP4 and **WAV** fixtures, `ingredient-manifest`, `bmff`,
  `actions-rules`, `assertion-rules`, `redacted-action`, `redactions`,
  `outside-manifest`, `hard-binding-redacted`, `first-piece-z`,
  `redaction-scope`, `tsa-signer`, `hostile-2`, `chain-constraints`,
  `ai-history`, `spec052`, **`wav`** and **`wav-writers`**.
  - **12,258 runs over 219 files, 0 faults.** Slowest run 0.05 s, peak
    memory 38 MiB, 18.8 s in all.
  - 67 mutated files stayed `Valid`, 9 of them WAV (eight from the own
    fixture, one from the c2pa-python file). **All 67 are `Valid` in
    `c2patool` 0.27.22 and in 0.28.1** (without settings). No wrong
    `Valid`.
  - The same seed under `v0.2.9` was not run: `v0.2.9` reads no WAV, so it
    would compare nothing for the WAV files and repeat step 200 for the rest
    (`src/` outside WAV is unchanged since then, apart from step 206's move,
    which the corpus showed to be identical).
- **The contract.** `php bin/api-check.php`: the recorded surface
  matches. `git diff v0.2.9 -- tests/Fixtures/api/public-surface.txt
  src/Report/StatusCode.php` is empty. New classes
  (`RiffManifestStoreExtractor`, `WavManifestStoreExtractor`) are
  `@internal`. What changes for a caller: the report's `format` can be
  `wav`, one more constructor parameter (last, with a default), and a file
  that was `unknown` can now get a verdict. That is new behaviour, not a
  fix, so the proposal is a minor version: **0.3.0**.
- **The package.** `php bin/package-check.php`: every top-level path
  classified; 344 files, 3.5 MB (SPEC-023 amendment 2 took the fixture
  builders out).
- **Text.** README and `docs/comparison.md` (the fuzzing lines), and
  CHANGELOG `Unreleased` (WAV, step 210–211's measurements, the smaller
  dist).

## Part two: CI

Pushed on Maurice's word (`b0f2148..840d992`, twelve commits, steps
203–212; no commit message names Claude or Anthropic, checked before the
push). CI run 37279625810 on `840d992`: **all eight jobs green** —
`composer check` on PHP 8.3, 8.4 and 8.5, `requirements.php` on PHP 7.4,
8.0, 8.1 and 8.2, and `all green`.

No tag yet: that needs Maurice's word.
