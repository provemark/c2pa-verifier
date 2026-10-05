# Step 216 — Built: strict about the `C2PA` chunk, lenient about the rest

*2026-10-05. SPEC-003 amendment 3, SPEC-055 amendment 3, SPEC-013
amendment 16.*

## What changed in `src/`

- `ContainerException` has `$storeReached` (default `true`, so JPEG, PNG
  and ISOBMFF report as before).
- `RiffManifestStoreExtractor`:
  - a header size larger than the file is refused before any chunk is
    read, as before, now with `storeReached` false;
  - a header size smaller than the file ends the walk where the RIFF
    chunk ends; the bytes after it are not read here;
  - a chunk header or chunk that runs past the RIFF chunk's end is an
    error naming that end ("the file" when the two coincide, so every
    earlier message is unchanged);
  - after a chunk that is not `C2PA`, an odd length's pad byte is skipped
    unchecked, and may be missing where the RIFF chunk ends;
  - the `C2PA` chunk's own pad byte must still be present inside the RIFF
    chunk and zero;
  - every fault says whether a `C2PA` chunk header had been read.
- `Verifier`: after a container fault, `hasManifest` is the exception's
  `storeReached`.

## Measured

- `vendor/bin/pest --group=SPEC-003`: 27 passed; `SPEC-055`: 50 passed;
  `SPEC-013`: 21 passed (step 215: 7, 9 and 1 failed).
- `composer check`: exit 0, 678 tests.
- **The corpus** against step 208's (1,212 runs over the files present
  then; no other change to `src/` since): **exactly the ten files step
  214 predicted moved**: `riff-size-excludes-c2pa`, `riff-size-plus-one`,
  `truncated-between-chunks` and `truncated-in-c2pa` for WebP and WAV,
  and WAV's `trailing-bytes` and `second-riff`. Each new report equals
  step 214's table, as does `wav-writers/c2pa-rs-sample3.invalid.wav`
  (not in step 208's corpus) and the eight built files of steps 213–215.
- **Fuzzing the RIFF files again**, since the walk is now more lenient:
  `php bin/fuzz.php 20261005 120 <out>` over the signed WebP and WAV,
  `webp/`, `wav/`, `wav-writers/` and `c2pa-rs/mars.webp`: 3,765 runs over
  43 files, 0 faults; the 19 mutations that stayed `Valid` are `Valid` in
  both `c2patool` versions. No wrong `Valid`.

## Text

CHANGELOG `Unreleased` (two entries), the SPEC-003 and SPEC-055 columns
of `tests/Fixtures/webp/README.md` and `tests/Fixtures/wav/README.md`,
SPEC-013's Traceability.

## Open, named

Whether JPEG, PNG and ISOBMFF also report `has_manifest: true` for an
unsigned file with a container fault. Not measured; SPEC-013 amendment 16
names it.
