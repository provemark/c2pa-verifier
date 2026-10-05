# Step 228 — SPEC-056 built: MP3 is read and verified

*2026-10-05. `c2patool` 0.27.22 and 0.28.1.*

## What was built

- `src/Container/Id3ManifestStoreExtractor.php`: an ID3v2 tag at offset 0
  (v2.3 and v2.4, an extended header skipped, frames to padding), the C2PA
  GEOB found by its MIME type and its object taken after the text fields
  in the frame's encoding; strict about that frame, lenient about the
  rest; `storeReached` as the other extractors. A file that opens with
  MPEG audio has no tag and yields `null`.
- `FormatDetector`: `mp3` when MPEG audio (eleven sync bits, no reserved
  version, layer, bitrate or sample rate) opens the file or follows the
  tag; for an ID3 tag that takes one seek and four bytes, and the stream
  is rewound. `isMpegFrame()` is shared with the extractor.
- `Verifier`: the `mp3` arm and the extractor as the last constructor
  parameter; the unknown-format message names MP3.
- `bin/fuzz.php` reads MP3 too; SPEC-024 AC1's test checks the new bound.

## Found by the build, decided the same day

1. **A tagless MP3** was detected as `mp3` and then refused by the
   extractor for having no tag; AC13 says no manifest, no failure. A
   build error, fixed: the extractor returns `null` for a file that opens
   with MPEG audio.
2. **AC11 contradicted AC13** on `tag-size-plus-one.mp3`: AC11 had the
   verifier read it; AC13's detection makes it `unknown`, because the tag
   ends one byte into the audio. A spec error, written in step 226.
   Maurice chose option 1 of three: AC13 stands, and AC11 now says the
   extractor reads the store while the verified file is `unknown`
   (SPEC-056 amendment 1).

## Measured

- `vendor/bin/pest --group=SPEC-056`: 34 passed (step 227: 34 failed).
- `composer check`: exit 0, 724 tests.
- The corpus against step 224, the unknown-format message normalised:
  **no existing file moved**; the 27 new files are the MP3 fixtures.
- Every MP3 file against both `c2patool` versions: 20 equal or equal in
  effect, 7 different, each named in the spec as stricter (AC4, AC7, AC9,
  AC10, AC13 and amendment 1); **none `Valid` here where `c2patool` is
  not**.
- The fuzzer over the MP3 files (seed 20261005, 200 rounds): 4,075 runs,
  0 faults; the 42 that stayed `Valid` are `Valid` in both `c2patool`
  versions.

## Amendments awaiting confirmation

SPEC-013 amendment 19 (the `format` value `mp3`, the probe that reads past
twelve bytes for an ID3 tag, the message, the constructor) and SPEC-024
amendment 3 (MP3 in AC1's list).

## Text

README, `SECURITY.md`, `docs/comparison.md` (the formats row, two rows
under *differs by design*), CHANGELOG, `tests/Fixtures/mp3/README.md`.
