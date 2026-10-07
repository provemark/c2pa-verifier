# Step 268 — plain text built (SPEC-060)

*2026-10-07. SPEC-060 as approved with amendment 1; the tests of step 267
turned green.*

## What was built

- `src/Container/PlainTextManifestStoreExtractor.php`: `isText()` checks
  the whole stream is UTF-8, in pieces of 64 KiB, a sequence split over two
  pieces judged whole; `extract()` finds every `U+FEFF`, decodes the
  selectors after it, takes a run of the magic and version 1 as a wrapper
  and skips any other, refuses a second wrapper, a store longer than its
  run, a length field over the bound, padding past the bound and an LBox
  that differs from the length field. The range runs from the marker to the
  end of the run.
- `src/Container/SelectorReader.php`: the piecewise reader. Selectors are
  decoded straight from their UTF-8 bytes (`EF B8 8x`, `F3 A0 84..87 xx`);
  a marker split over two pieces is found.
- `Verifier`: a last constructor parameter `?PlainTextManifestStoreExtractor
  $text = null`. With it, a stream `FormatDetector` does not claim and that
  is UTF-8 is `text` (SPEC-013 amendment 23).
- `Cli\Command`: `--text`, with the text verifier as an optional second
  constructor argument that the shim passes (SPEC-019 amendment 3; the
  `Cli` layer still depends only on Verifier, Trust, Report and Support).
- SPEC-024 amendment 6 (the bound list), the README, `docs/comparison.md`,
  `SECURITY.md`, `composer.json`'s description and the CHANGELOG under
  *Unreleased*.
- `bin/fuzz.php` sends a `.txt` file to a verifier with text on and finds
  its store with the text reader; every other file goes as before.

## Measured

- `composer check`: 899 tests (63 SPEC-060, 3 SPEC-019 amendment 3, one
  SPEC-024 constant), PHPStan level max, Pint, Deptrac, spec-check,
  api-check. SPEC-019's three new tests were seen red with the command's
  change set aside, then green.
- The corpus against the baseline of step 260/261: 0 of 1,446 measurements
  moved (text off by default; the corpus script does not read `.txt`).
- Fuzzing, the default set with seed 20261005 (10,704 runs over 249 files,
  the text fixtures included): 0 faults, 118 `Valid`, none of them text,
  each `Valid` in `c2patool` 0.27.22 and 0.28.1. Three text-only seeds of
  400 rounds over 23 files (23,100 runs): 0 faults, 23 `Valid`, each a bit
  flipped in the padding, and each `Valid` in the oracle.

## What it does not do (as SPEC-060's Scope)

NFC normalisation, §A.7 (HTML) and §A.9 (structured text), text in another
encoding than UTF-8, and recognising text by a file name.
