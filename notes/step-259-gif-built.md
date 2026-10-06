# Step 259 — GIF built (SPEC-059)

*2026-10-06. The tests of step 258, red, made green.*

## Built

- `src/Container/GifManifestStoreExtractor.php`: the head (header, screen
  descriptor, global colour table) read, then the blocks before the first
  image walked — each extension's block skipped by its stated size and its
  sub-blocks to their `00` — until the first image descriptor, the trailer
  or the end of the file. An Application Extension must have block size 11;
  the one with identifier `C2PA_GIF` and version `01 00 00` has its
  sub-blocks joined into the store, within the 16 MiB bound and the memory
  budget, sub-block by sub-block; a second one is a fault, an empty one is
  no store. The store's LBox must equal its length. The range is the whole
  block. The store counts as reached once such a block's header is read
  (`withStoreReached()`).
- `FormatDetector`: `GIF87a` and `GIF89a` are `gif`.
- `Verifier`: the `gif` route; the GIF extractor as the last constructor
  parameter; the unknown-format message names GIF (SPEC-013 amendment 22).
- SPEC-024 amendment 5: the GIF bound in AC1's test.
- `bin/fuzz.php` knows GIF (the files, and where its store is).
- README, `docs/comparison.md` (two blocks and a malformed block, named),
  `docs/milestones.md`, CHANGELOG `Unreleased`.

Two corrections on the way, both in the tests of step 258: `GifTest` used
`AviTest`'s helpers, which are not loaded when the file runs alone (it has
its own now); and AC1 and AC8 expected ranges as `[start, length]` where
`ManifestStoreBytes` holds `['start' => …, 'length' => …]`.

## Measured

- `tests/Unit/Verifier/GifTest.php`: 25 passed (red in step 258).
- `composer check`: exit 0, 830 tests; `bin/api-check.php`: the recorded
  surface matches; Deptrac: no violation.
- The corpus against step 255: 0 of 1,400 measurements moved; 40 new rows,
  all GIF. (A first comparison showed 356 moved: the raw `.bin` stores are
  `unknown` and their message now names GIF; the comparison script's
  normalisation was extended, and its first extension dropped "PNG, " and
  was corrected before this count.)
- Every GIF file against `c2patool` 0.27.22 and 0.28.1: the signed fixture
  `Valid` in all three; every difference is a named one (a malformed block
  `Invalid` here, an error there; no claim alike); none `Valid` here where
  `c2patool` is not.
- The release set fuzzed with GIF, seed 20261005: 16,971 runs over 314
  files, 0 faults, 120 `Valid`, each `Valid` in both versions. The same
  seed over the set without GIF gives exactly step 255's 118: the new files
  shift the random sequence, nothing else.
