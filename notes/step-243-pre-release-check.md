# Step 243 — The check before 0.3.0, and a review that held it up

*2026-10-05. Maurice decided that 0.3.0 carries WAV, MP3, FLAC and AVI
(steps 203–242). This is the check before that release.*

## Measured

- **Specs**: `php bin/spec-check.php`, all 58 `implemented`; no amendment
  waiting.
- **Tests**: `composer check` exit 0; CI run 37304512472 on `6005188`
  green in all eight jobs.
- **Contract**: `php bin/api-check.php`: the recorded surface matches;
  every new class is `@internal`. `git diff v0.2.9` on
  `tests/Fixtures/api/public-surface.txt` and `src/Report/StatusCode.php`
  is empty. What changes for a caller is behaviour, not surface: new
  `format` values (`wav`, `avi`, `mp3`, `flac`), `has_manifest` after a
  container fault, the RIFF leniency, three new trailing `Verifier`
  constructor parameters. A minor version: 0.3.0.
- **Package**: `php bin/package-check.php`: 371 files, 3.7 MB (ceiling 4 MB).
- **Fuzzing, the release set with every format**:
  `php bin/fuzz.php 20261005 60 <out>` over the step-212 set plus WAV,
  MP3, FLAC, AVI and the files of other writers: 15,801 runs over 291
  files, 0 faults; the 117 that stayed `Valid` are `Valid` in both
  `c2patool` versions.
- **Fuzzing, 0.2.9 against 0.3.0**: the same seed over the formats 0.2.9
  read (11,118 runs over 193 files), once under this tree and once in a
  worktree at `v0.2.9` with its own autoloader. **The same 66 files stay
  `Valid` in both.** The first attempt at this comparison ran the new code
  twice: the worktree's linked `vendor/` loaded this tree's `src/`. It was
  redone with an autoloader for the worktree's `src/` alone, checked first
  to load the old `FormatDetector` and no WAV extractor.

## The review

A code review of `v0.2.9..HEAD` over `src/` as a whole found ten points,
none giving a wrong `Valid`. Checked:

1. **MP3/FLAC: a frame past the tag after the C2PA GEOB discarded the store
   already read** (`return null`). Measured: `has_manifest` false here;
   both `c2patool` versions read the manifest (`Invalid`, hash mismatch).
   Anyone could make a signed file look unsigned this way. A bug against
   SPEC-056's own rule (the walk stops; it does not forget).
2. ISOBMFF's `carriesC2paUuid()` read with raw `fread`, against SPEC-050.
3. **The RIFF walk had no chunk limit.** Measured: a 16 MB WAV of empty
   chunks, 1.5 s here (`c2patool` 3.3 s); about 90 s per GB.
4. A FLAC whose tag runs past the end of the file is reported as `mp3`.
5. A JPEG with a non-C2PA JUMBF in APP11 (JPEG 360, XT), then a broken
   segment, reports `has_manifest` true.
6. Extractors passed to `Verifier` with tighter limits do not apply to the
   new formats.
7. The ID3 reader kept the GEOB's text bytes in memory beside the store.
8–10. Three near-identical RIFF wrapper classes; five copies of the
   `storeReached` rewrap; the detector and the ID3 reader calling each
   other.

## Decided (Maurice van Loon)

- **Fix before the release**: 1, 2 and 7 as bug fixes within their specs;
  3 as SPEC-003 amendment 5 (4,096 top-level chunks, as ISOBMFF and ID3;
  the corpus holds at most 7).
- **Document**: 4, 5 and 6.
- **Later**: 8–10, a refactor without a change in behaviour.

Steps 244–245 do this; the check is repeated after them.
