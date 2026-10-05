# Step 231 — Built: SPEC-056 amendment 2

*2026-10-05.*

## What changed in `src/`

- `Id3ManifestStoreExtractor`: both MIME types `c2patool` accepts; a
  shared `header()` (version, flags, frame end, tag end with a v2.4
  footer, counted from the tag's own start) used by the detector too; one
  `frameHeader()` for the walk and the scan of AC5 (a frame id must be
  four capitals or digits; a v2.4 size with a byte above `7F` is a plain
  integer); unsynchronisation with `FF 00` inside the tag refused before
  the frames are walked; the C2PA GEOB's text fields read on in 64 KiB
  chunks until they end, bounded by the frame and the object limit; the
  guard against an empty GEOB body (the `ValueError`); grouping no longer
  refused in v2.3, so a grouped GEOB is not C2PA in either version.
- `FormatDetector`: an untagged file is `mp3` when two frame headers
  follow each other at the first frame's computed length
  (`mpegFrameLength()`: the MPEG 1, 2 and 2.5 tables, checked on the
  fixture's own frames, 288 bytes at 32 kbps and 8 kHz); after an ID3 tag,
  zero padding (up to 64 KiB) and further tags (up to 8) are skipped and
  one frame header is enough.

## Found by the build

1. The detector added a tag's relative end to nothing: the second of two
   tags was looked for at the wrong place. `header()`'s ends are relative
   to the tag; the detector now adds them to its position. Found by
   AC16's second-tag case.
2. The two-header rule was first applied after a tag as well, which made
   c2pa-rs's hostile one-frame file `unknown`; amendment 2 asks two headers
   only of an untagged file. Fixed, and that file now guards it in AC16.
3. PHPStan: the frame-length tables needed their own guard for the
   reserved indexes, and `fread` a length of at least one.

## Measured

- `vendor/bin/pest --group=SPEC-056`: 48 passed (step 230: 13 failed).
- `composer check`: exit 0, 738 tests.
- The corpus against step 228, the unknown-format message normalised:
  **no file present then moved**.
- Every MP3 (35 variants, the fixtures, the two other-writer files)
  against both `c2patool` versions: no file `Valid` here where `c2patool`
  is not; every difference named in the spec (AC4, AC7, AC9, AC10, AC13,
  AC15, AC18, AC19, amendment 1). The c2pa-ts MP3 is `Invalid` with
  `assertion.dataHash.mismatch`, as 0.28.1 says.
- The fuzzer: over the MP3 files (200 rounds) 6,350 runs, 0 faults, 124
  `Valid`; over the default set (60 rounds) 8,982 runs, 0 faults, 88
  `Valid`. Every one of the 212 is `Valid` in both `c2patool` versions.

## Text

`docs/comparison.md` (two more rows), CHANGELOG, the `mp3-writers`
README.
