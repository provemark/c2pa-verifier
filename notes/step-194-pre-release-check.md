# Step 194 — The check before 0.2.8

*2026-09-30. The release that carries SPEC-051, SPEC-052 and SPEC-053.
Pushing and tagging follow in the release step, and only with the
maintainer's permission.*

## What was checked, and what it found

- **Specs.** `php bin/spec-check.php`: all 53 specifications are
  `implemented`. No amendment waits for confirmation. SPEC-052 amendment 1
  and SPEC-053 amendment 1 were both confirmed on 2026-09-30.
- **`composer check`**: exit 0, 606 tests (step 191; step 193 added none).
- **Fuzzing,** as in step 181, with `spec052` added:
  `php bin/fuzz.php 20260930 60 <out>` in `tests/Fixtures`, over
  `public-testfiles`, `c2pa-rs`, `writers`, `binding`, the signed JPEG,
  PNG, WebP and MP4 fixtures, `ingredient-manifest`, `bmff`,
  `actions-rules`, `assertion-rules`, `redacted-action`, `redactions`,
  `outside-manifest`, `hard-binding-redacted`, `first-piece-z`,
  `redaction-scope`, `tsa-signer`, `hostile-2`, `chain-constraints`,
  `ai-history` and `spec052`.
  - **11,118 runs over 193 files, 0 faults.** Slowest run 0.05 s, peak
    memory 38 MiB, 18.5 s in all.
  - 47 mutated files stayed `Valid`. **All 47 are `Valid` in `c2patool`
    0.27.22 and in 0.28.1** (without settings). Both oracles were at hand
    this time.
  - **The same seed under `v0.2.7`**, in a worktree with its own
    autoloader (checked: it loads the old `BmffHashCheck` and has no
    `BmffLimitException`), on the same fixtures: 11,118 runs, 0 faults,
    **48** files `Valid`. The extra one is
    `crc32b-reference.jpg#20260930-44-block`, a mutation of SPEC-052's
    variant. It is `Invalid` in both `c2patool` versions
    (`ingredient.manifest.mismatch`) and `Invalid` in the code about to be
    released. The fuzzer found SPEC-052's wrong verdict again on its own,
    and the fix closes it.
- **The package.** `php bin/package-check.php`: every top-level path is
  classified; the dist holds 373 files. `php bin/api-check.php`: the
  recorded surface matches, and every public class is in the contract or
  `@internal`. `git diff v0.2.7 -- tests/Fixtures/api/public-surface.txt
  src/Report/StatusCode.php` is empty. The new class `BmffLimitException`
  is `@internal`.
- **Texts brought up to date:**
  - the CHANGELOG: `Unreleased` becomes 0.2.8, with a summary;
  - the README: the current tag, "seventeen" wrong verdicts found in
    itself (SPEC-052's is the seventeenth), and the fuzzing line;
  - the fuzzing line in `docs/comparison.md`;
  - `SECURITY.md`: "fixed in `0.2.8`".

## 0.2.8, not 0.3.0

It is a patch by step 138's rule: no class, method, member, status code or
settings shape changes. The verdicts that move are all toward `c2patool`'s
or from a crash to a report:

- the SPEC-052 variants go from `Trusted` to `Invalid`;
- SPEC-051's crash becomes `Invalid`;
- SPEC-053's stall becomes `Invalid` at once.

No corpus file moved (steps 185, 188 and 191).

## What waits

Pushing `main` (steps 183–194), CI green on the last commit, the tag
`v0.2.8` on it, the archive against `bin/package-check.php`, Packagist.
Then the WordPress plugin and the demo.
