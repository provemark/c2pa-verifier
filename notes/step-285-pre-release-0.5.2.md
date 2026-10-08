# Step 285 — The check before 0.5.2, and the release commit

*2026-10-08. 0.5.2 carries SPEC-014 amendment 7 (step 284): a certificate
authority without keyUsage no longer issues.*

## Measured

- **Specs**: `php bin/spec-check.php`, all 60 `implemented`, no
  amendment waiting, every open question with a status.
- **Tests**: `composer check` exit 0, 927 tests. CI waits for the push.
- **Contract**: `php bin/api-check.php`: the recorded surface matches.
  `git diff v0.5.1` on `tests/Fixtures/api/public-surface.txt` and
  `src/Report/StatusCode.php` is empty; one file of `src/` changed
  (`Trust/ChainCheck.php`). A patch version.
- **Package**: `php bin/package-check.php`: 403 files, 4.0 MB; as a zip
  1.4 MB.
- **Fuzzing, 0.5.1 against 0.5.2**: seed 20261005, 60 rounds, in a
  worktree at `v0.5.1` with its own `composer install` and in this tree:
  12,903 runs over 249 files, 0 faults in both, the same 122 suspect
  files. 120 are `Valid` in `c2patool` 0.27.22 and 0.28.1; the 2 texts
  are `Valid` in 0.28.1 built with `unstable_plain_text`.
- **The corpus and the matrix** (step 284): only the two key-usage probes
  move; the matrix differs from 0.28.1 only where stricter by design.

## The release commit

- CHANGELOG: *Unreleased* → `0.5.2 — 2026-10-08` with an introduction.
- README: `v0.5.2`, both security releases named; the fuzzing figure.
- `SECURITY.md`: the twentieth case fixed in `0.5.2`.
- `docs/comparison.md`: the fuzzing figures for 0.5.2, and the trust
  matrix.

The push, the tag and Packagist wait for Maurice's word.
