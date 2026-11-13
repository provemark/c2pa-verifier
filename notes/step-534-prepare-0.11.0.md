# Step 534 — Prepare 0.11.0

*2026-11-13.* Maurice: "bereid release 0.11.0 van de verifier voor".
Open question 3 of SPEC-090 had already decided a minor: five
`StatusCode` cases, and a verdict that moves from `Invalid` to `Valid` or
`Trusted` for box-hash JPEGs, which a caller should opt into.

Since `v0.10.1`:
- step 528 corrected the changelog;
- steps 529–531 built SPEC-042 (slash-less JUMBF URIs);
- steps 532–533 built SPEC-090 (the general box hash for JPEG).

## What changes

- **`CHANGELOG.md`.** A new **0.11.0 — 2026-11-13** entry, holding:
  - an introduction with the corpus comparison below;
  - why it is a minor;
  - *Added* (SPEC-090);
  - *Changed* (SPEC-042);
  - the six differences from `c2patool` this release adds.
- **The version.** `Verifier::VERSION` is `0.11.0`.
- **`README.md`.** The sentence on the current tag names 0.11.0, and the
  list of what is verified names the box hash in a JPEG.
- **`SECURITY.md`.** Unchanged. Neither spec fixes a wrong verdict: both
  accept files that were refused.

## Measured

- **CI on `b895950`** (steps 532–533): `ci` completed, `success`.
- **The corpus against `v0.10.1`**, as step 526 did against `v0.10.0`:
  - a worktree at `v0.10.1` with a copied `vendor/` (each side loaded its
    own `src/`);
  - the current `tests/Fixtures/` read by both: 1,097 files;
  - three trust configurations, each without and with a revocation
    source that answers null;
  - validation time 2026-11-14T00:00:00Z, after every probe's
    certificates;
  - the version string removed before hashing.

  6,582 runs a side, 0 exceptions.
  - **Verdicts:** five files move, `Invalid` → `Valid` in all six
    configurations: `slashless/all-slashless.png` and
    `box-hash/written.jpg`, `-exif-exclusion.jpg`, `-excluded.jpg`,
    `-no-pad.jpg`.
  - **Output:** 150 runs differ, in 25 files: the 22 SPEC-042 and
    SPEC-090 probes, the Bing file, `c2pa-rs/prerelease.jpg` and
    `binding/hard-binding-boxes.png`.
- **`composer check`:** exit 0.
  - pest: 1,746 passed, 1 skipped;
  - api-check: 217 symbols;
  - spec-check: 91 specs;
  - phpstan, pint and deptrac clean.
- **`bin/package-check.php`:** 677 files, 6.4 MB, 2.2 MB as a zip.

## Left

Each on Maurice's word:
1. commit and push, then CI;
2. tag `v0.11.0`, then Packagist;
3. then `provemark/content-credentials` 0.20.1, widening its conflict to
   `>=0.12`, so that the Drupal module can take 0.11 without a change of
   its own.
