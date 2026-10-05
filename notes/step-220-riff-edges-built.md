# Step 220 — Built: the edges of the RIFF leniency

*2026-10-05. SPEC-003 amendment 4 (with an addendum), SPEC-055 amendment
4 (the same addendum), SPEC-013 amendment 17.*

## What changed in `src/`

`RiffManifestStoreExtractor`:

- a header size below 4 is refused before any chunk is read;
- a header size larger than the file is still refused, after
  `reachesStore()` has scanned the chunk headers (never a chunk's data)
  for a `C2PA` chunk header; `storeReached` says what it found;
- the walk stops where fewer than 8 bytes remain in the RIFF chunk, and
  where a chunk other than `C2PA` runs past its end; a `C2PA` chunk that
  runs past its end is still a fault;
- the docblock says so.

## Found by the build, decided the same day

Four existing tests failed: SPEC-003 AC16 and three cases of SPEC-055
AC4 required the stream to stand at offset 12 or before after a header
size larger than the file, *"before any chunk header is read"*. The scan
of amendment 4 reads chunk headers, so the two contradicted each other;
step 218 had missed it. Measured: the scan stops at the end of the `C2PA`
chunk header (320 in the WebP fixture, 16,086 in the WAV fixture) or after
one header when there is none (20 and 78). Proposed and approved by
Maurice van Loon: an addendum to amendment 4, under which the criteria
read *"without any chunk's data being read"*, with those offsets as the
bound. Rewinding the stream to 12 after the scan, so that the old tests
would pass, was rejected: the tests would then assert something no
longer true.

## Measured

- Pest per group: SPEC-003 31 passed, SPEC-055 55 passed, SPEC-013 21
  passed (step 219: 4, 3 and 1 failed).
- `composer check`: exit 0, 687 tests.
- The corpus against step 217: **exactly the four predicted files moved**
  (`riff-size-plus-one` and `truncated-in-c2pa`, WebP and WAV: now
  `hasManifest` true). The reviewer's seven files and step 218's two each
  give step 218's predicted report.
- The fuzzer over the RIFF files again (seed 20261005, 120 rounds): 3,765
  runs, 0 faults; the 19 that stayed `Valid` are `Valid` in both
  `c2patool` versions.

## Text

`docs/comparison.md` (two rows under *differs by design*, a paragraph on
the store that can be hidden), CHANGELOG `Unreleased`.
