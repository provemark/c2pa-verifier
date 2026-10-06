# Step 262 — The check before 0.4.0, and the release commit

*2026-10-06. Maurice decided that 0.4.0 carries GIF (steps 256–261) and
that TIFF waits until someone asks for it: on the web and in WordPress it
is rare.*

## Measured

- **Specs**: `php bin/spec-check.php`, all 59 `implemented`; no amendment
  waiting.
- **Tests**: `composer check` exit 0, 833 tests; CI run 37438432594 on
  `c8595bd` green in all eight jobs.
- **Contract**: `php bin/api-check.php`: the recorded surface matches.
  `git diff v0.3.0` on `tests/Fixtures/api/public-surface.txt` and
  `src/Report/StatusCode.php` is empty. What changes for a caller: a new
  `format` value (`gif`), the unknown-format message names GIF, and a new
  last `Verifier` constructor parameter. A minor version: 0.4.0.
- **Package**: `php bin/package-check.php`: 385 files, 3.9 MB (ceiling
  16 MB); as a zip 1.4 MB (signal 5 MB).
- **Fuzzing with GIF** (step 261): the release set, 17,109 runs over 317
  files, 0 faults, the 127 that stayed `Valid` `Valid` in both `c2patool`
  versions; five GIF-focused seeds, 47,340 runs, 0 faults, every `Valid`
  confirmed.
- **Fuzzing, 0.3.0 against 0.4.0**: the same seed (20261005, 60 rounds)
  over the formats 0.3.0 read, 16,041 runs over 295 files, once under this
  tree and once in a worktree at `v0.3.0` with its own `composer install`
  (checked first: it loads its own `FormatDetector` and has no GIF
  reader), both with this tree's `bin/fuzz.php` (since 0.3.0 it changed
  only for GIF). **The same 118 files stay `Valid` in both**, 0 faults in
  both, and all 118 are `Valid` in both `c2patool` versions. A first
  attempt fuzzed no file at all: the shell did not split the list of
  paths; it was redone.

## The release commit

- CHANGELOG: `Unreleased` → `0.4.0 — 2026-10-06`, with an introduction;
  the GIF entry names amendment 1's rules (extensions as sub-blocks, an
  empty block counts, the 4,096-block bound) and the new constructor
  parameter.
- README: the current tag `v0.4.0`, what changes coming from `0.3.x`, the
  fuzzing figure.
- `docs/comparison.md`: the fuzzing figures for 0.4.0.

The tag, the GitHub release and Packagist wait for Maurice's word.
