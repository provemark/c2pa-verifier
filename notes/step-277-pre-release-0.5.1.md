# Step 277 — The check before 0.5.1, and the release commit

*2026-10-08. 0.5.1 is a security release: SPEC-014 amendments 5 and 6
(steps 273, 275, 276).*

## Measured

- **Specs**: `php bin/spec-check.php`, all 60 `implemented`; no amendment
  waiting.
- **Tests**: `composer check` exit 0, 919 tests. CI waits for the push.
- **Contract**: `php bin/api-check.php`: the recorded surface (126
  symbols) matches. `git diff v0.5.0` on
  `tests/Fixtures/api/public-surface.txt` and `src/Report/StatusCode.php`
  is empty. Two files in `src/` changed since `v0.5.0`:
  `Trust/ChainCheck.php` and `Report/ValidationResult.php`. A patch
  version: 0.5.1.
- **Package**: `php bin/package-check.php`: 398 files, 4.0 MB (ceiling
  16 MB); as a zip 1.4 MB (signal 5 MB).
- **Fuzzing, 0.5.0 against 0.5.1**: seed 20261005, 60 rounds, the default
  files, once in a worktree at `v0.5.0` with its own `composer install`
  and its own `bin/fuzz.php`, once in this tree. 12,903 runs over 249
  files, 0 faults in both, **the same 122 suspect files `Valid` in both**.
  120 are `Valid` in `c2patool` 0.27.22 and 0.28.1; the 2 texts, which
  stock `c2patool` cannot read, are `Valid` in 0.28.1 built with
  `unstable_plain_text`.
- **The corpus** (steps 275, 276): 729 files under no settings and 63
  settings files; the only verdicts that moved since `v0.5.0` are SPEC-014
  AC12's five probes, `Trusted` → `Valid`.

## The release commit

- CHANGELOG: `0.5.1 — 2026-10-08`, a *Security* entry (amendment 5), a
  *Changed* entry (amendment 6) and two notes for users: the DigiCert
  cross-certificate against the root, and Adobe's Lightroom signer that
  expired on 2026-10-07.
- README: the current tag `v0.5.1`, one sentence on what it fixes; the
  count of wrong verdicts found in the project, which said seventeen while
  `SECURITY.md` said eighteen, is nineteen in both; the fuzzing figure.
- `SECURITY.md`: the nineteenth case, with how it was found.
- `docs/comparison.md`: the fuzzing figures for 0.5.1.

The push, the tag, Packagist and the advisory wait for Maurice's word.
