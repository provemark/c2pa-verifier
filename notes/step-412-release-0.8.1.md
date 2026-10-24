# Step 412 — The release commit for 0.8.1

*2026-10-24.* Maurice: "akkoord, bereid 0.8.1 voor".

0.8.1 carries steps 406–411: the graph fuzzer (`bin/graph-fuzz.php`, steps
406, 407 and 411) and the three changes it led to (SPEC-021 amendment 7,
SPEC-035 amendment 7, SPEC-070 amendment 4; steps 408–410).

What the release commit does:

- The CHANGELOG gains **0.8.1 — 2026-10-24** (Security, Changed). The
  README names the new tag. `Verifier::VERSION` is `0.8.1`.
- `SECURITY.md` records the two wrong `Trusted` verdicts, with the
  versions each affected, and names the cycle rule as a changed rule,
  not counted among them.
- **A patch release:** `bin/api-check.php` reports 142 contract symbols,
  as in 0.8.0. `ManifestGraph::$cyclic` is new, but the class is
  `@internal`.

Measured:

- The affected versions, from the tags: the first-reference-only check
  (`$byUrl[$urls[0]]`) is in `v0.1.0`; `scopedRedactions()` arrived in
  step 390, first tagged `v0.8.0`.
- `v0.8.0` (a worktree with a copied `vendor/`) on the five new fixtures
  that carry a fault, each with its own settings: all five `Trusted`. The
  current tree: all five `Invalid`.
- The corpus against `v0.8.0`: 0 of 1,854 runs move; 18 runs are new, the
  nine new fixtures.
- `bin/package-check.php`: 551 files, 5.3 MB in the dist, 1.9 MB as a
  zip; every top-level path classified. `bin/graph-fuzz.php` ships, like
  `bin/fuzz.php`.
- `composer check`: exit 0, 1,335 passed, 1 skipped.

Reasoned: that the second-reference fault was in `0.1.0`–`0.6.x` too.
The code is the same, but it was confirmed only on `0.7.1` and `0.8.0`.

Not done here: the push, the tag and Packagist wait for Maurice's word,
as does whether the two findings get an advisory (SECURITY.md: once the
package has users outside the project).
