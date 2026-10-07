# Step 270 — The check before 0.5.0, and the release commit

*2026-10-07. 0.5.0 carries plain text, opt-in (SPEC-060, steps 265–269).*

## Measured

- **Specs**: `php bin/spec-check.php`, all 60 `implemented`; no amendment
  waiting.
- **Tests**: `composer check` exit 0, 910 tests; CI run 37590850808 on
  `0dd7d46` green in all eight jobs.
- **Contract**: `php bin/api-check.php`: the recorded surface (126 symbols)
  matches. `git diff v0.4.0` on `tests/Fixtures/api/public-surface.txt` and
  `src/Report/StatusCode.php` is empty. What changes for a caller: a new
  last `Verifier` constructor parameter (`null`, text off, by default), a
  new optional `Cli\Command` constructor argument and the `--text` flag in
  its usage line; with text on, a new `format` value (`text`) and a longer
  unknown-format message. A minor version: 0.5.0.
- **Package**: `php bin/package-check.php`: 392 files, 3.9 MB (ceiling
  16 MB); as a zip 1.4 MB (signal 5 MB).
- **Fuzzing, 0.4.0 against 0.5.0**: the same seed (20261005, 60 rounds)
  over the files 0.4.0's fuzzer used, 11,733 runs over 226 files, once in
  this tree and once in a worktree at `v0.4.0` with its own `composer
  install` (checked: it has no text reader) and its own `bin/fuzz.php`.
  0 faults in both; **the same 128 files stay `Valid` in both**, and all
  128 are `Valid` in `c2patool` 0.27.22 and 0.28.1.
- **Fuzzing, text** (step 269): 23,100 runs, 0 faults, every `Valid`
  confirmed by the oracle.
- **The corpus** (step 269): 0 of 1,446 measurements moved since v0.4.0.

## The release commit

- CHANGELOG: `Unreleased` → `0.5.0 — 2026-10-07`, with an introduction;
  the plain-text entry names the refusals and the per-stretch decoding.
- README: the current tag `v0.5.0`, what changes coming from `0.4.x`, the
  fuzzing figure.
- `docs/comparison.md`: the fuzzing figures for 0.5.0.

The tag, the GitHub release and Packagist wait for Maurice's word.
