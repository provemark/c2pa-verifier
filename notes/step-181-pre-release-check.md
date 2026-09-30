# Step 181 — The check before 0.2.7

*2026-09-30. The release that carries SPEC-050. Pushing and tagging follow
in the release step, with the maintainer's permission given the same day.*

## What was checked, and what it found

- **Specs.** `php bin/spec-check.php`: all 50 specifications are
  `implemented`; `grep -rn "Awaiting confirmation" specs` finds nothing.
- **`composer check`**: exit 0, 583 tests (step 180).
- **Fuzzing,** as in steps 162, 171 and 176, with `ai-history` added:
  `php bin/fuzz.php 20260930 60 <out>` in `tests/Fixtures`, over
  `public-testfiles`, `c2pa-rs`, `writers`, `binding`, the signed JPEG,
  PNG, WebP and MP4 fixtures, `ingredient-manifest`, `bmff`,
  `actions-rules`, `assertion-rules`, `redacted-action`, `redactions`,
  `outside-manifest`, `hard-binding-redacted`, `first-piece-z`,
  `redaction-scope`, `tsa-signer`, `hostile-2`, `chain-constraints` and
  `ai-history`.
  - **10,878 runs over 189 files, 0 faults.** Slowest run 0.05 s, peak
    memory 38 MiB, 18.5 s in all.
  - 42 mutated files stayed `Valid`. **All 42 are `Valid` in `c2patool`
    0.27.22** (without settings). `c2patool` 0.28.0 was not at hand this
    time, so the second oracle of earlier checks is missing.
  - **The same seed under `v0.2.6`** (a worktree with its own autoloader,
    checked to load the old `StreamReader` and no `Read`; the same
    fixtures): 10,878 runs, 0 faults, the same 42 files `Valid`. SPEC-050
    moved no verdict on a local file, as step 180's 31,749 corpus runs
    already showed.
- **The package.** `php bin/package-check.php`: every top-level path
  classified; the count is in the release step. `php bin/api-check.php`:
  the recorded surface matches, every public class is in the contract or
  `@internal`. `git diff v0.2.6 -- tests/Fixtures/api/public-surface.txt
  src/Report/StatusCode.php` is empty. The new class `Read` is
  `@internal`.
- **Texts brought up to date:** the CHANGELOG (0.2.7, *Fixed*); the
  README's current tag and fuzzing line; the fuzzing line in
  `docs/comparison.md`. SECURITY.md is unchanged: the fault never gave a
  wrong `Valid` or `Trusted`. `docs/conformance.md` is unchanged: reading a
  stream is no predicate of the specification.

## 0.2.7, not 0.3.0

A patch by step 138's rule: no class, method, member, status code or
settings shape changes. The only verdicts that move are those on streams
that return short reads, from a wrong `Invalid` to the verdict of the same
file from disk.

## What waits

Pushing `main` (steps 179–181), CI green on the last commit, the tag
`v0.2.7` on it, the GitHub release, the archive against
`bin/package-check.php`, Packagist. Then the WordPress plugin.
