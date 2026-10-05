# Step 233 — Built: SPEC-056 amendment 3, and a test suite that had outgrown 128 MB

*2026-10-05.*

## What changed in `src/`

- `Id3ManifestStoreExtractor`: the grouping flag (v2.3 `0x20`, v2.4
  `0x40`) refused on a GEOB that still reads as C2PA (AC22); the text
  fields searched once, each search going on where the last stopped, and
  the two-byte NUL found with `strpos()` (AC20: 8 MB of unterminated
  UTF-16 was 14.8 s, is 0.01 s); the scan of AC5 skips an extended header
  as the walk does (AC23); the `FF 00` check reads through `Read::upTo()`
  and a short read is a fault; `header()` takes version 2 for detection.
- `FormatDetector`: a tag that runs past the end of the file is `mp3`
  (AC23); an ID3v2.2 tag before MPEG audio is `mp3` (AC24), so the
  extractor's reason reaches the report; zero padding is probed with 16
  bytes first, read further only while it is all zeros; the class
  docblock says what is read.

## The suite and 128 MB

Checking step 232 showed the full suite stopping with *Allowed memory size
of 134217728 bytes exhausted* in SPEC-024's `ResourceBoundsTest`, AC1,
depending on test order. Measured: at that test's start 34.8 MB was in
use; its helper `spec024StorePng()` built a 20 MB PNG as one string through
several 20 MB copies, which with that base passed 128 MB. Nothing in the
new tests holds memory afterwards (an 8 MB GEOB: 3.6 MB before, 3.7 MB
after). The suite had simply grown to the edge. The helper now writes the
file in 64 KiB pieces and computes the CRC as it goes; the file it writes
is byte-identical to before (SHA-256 compared at 300 kB). No criterion
changed. Two full runs in a row, the second with Pest's result cache:
747 passed both times.

The 8 MB test was also rewritten to fill a stream in pieces and keep only
the message, before the real cause was found; that change stays, as it
holds less memory.

## Measured

- `vendor/bin/pest --group=SPEC-056`: 57 passed (step 232: 7 failed).
- `composer check`: exit 0, 747 tests, twice.
- The corpus against step 228, the unknown-format message normalised:
  one file moved, `mp3/truncated-in-store.mp3`, from `unknown` to `mp3`
  with a manifest that failed, as AC23 says (`c2patool`: *invalid CBOR
  box*).
- Every MP3 against both `c2patool` versions: each difference named in
  the spec (AC4, AC7, AC9, AC10, AC13, AC15, AC18, AC19, AC22, AC24,
  amendment 1); none `Valid` here where `c2patool` is not.
- The fuzzer: MP3 files (200 rounds) 6,975 runs; the default set (60
  rounds) 9,177 runs; 0 faults; the 210 that stayed `Valid` are `Valid` in
  both `c2patool` versions.
