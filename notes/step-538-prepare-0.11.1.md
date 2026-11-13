# Step 538 — Prepare 0.11.1

*2026-11-13.* Maurice: "bereid verifier 0.11.1 voor". A patch: SPEC-075
amendment 4 (steps 536–537, `7aeada1`) changes crJSON output and nothing
in the verdict, the ordinary report or the contract, so a caller on
`^0.11`, the Drupal module among them, receives it without a change of
constraint. `provemark/content-credentials` 0.20.1 allows `<0.12`, so the
library needs no release for it.

Since `v0.11.0`:
- step 535 measured the corpus against the conformance tool's setup;
- steps 536–537 built SPEC-075 amendment 4.

## What changes

- **`CHANGELOG.md`.** The *Unreleased* section becomes **0.11.1 —
  2026-11-13**, with an introduction holding the comparison below.
- **The version.** `Verifier::VERSION` is `0.11.1`.
- **`README.md`.** The sentence on the current tag names 0.11.1 and what
  it changes.
- **`SECURITY.md`.** Unchanged: no verdict moves.

## Measured

- **CI on `7aeada1`**: run 43433002874, 8 of 8 jobs `success`.
- **Against `v0.11.0`:**
  - a worktree at `v0.11.0` with a copied `vendor/` and its own
    autoloader (checked: it loads its own `src/`; a symlinked `vendor/`
    does not, which spoiled the first comparison of step 537);
  - every file under `tests/Fixtures/` except `.md`, `.json`, `.pem` and
    `.php`: 1,227 files;
  - without settings and with the conformance tool's trust settings
    (step 535), validation time 2026-11-14T00:00:00Z;
  - the ordinary report with exit status, and `--crjson`, per side.

  Results:
  - **Report and exit status:** identical in all 2,454 runs.
  - **crJSON:** in each configuration 460 documents differ, all by the
    root `validationResults` alone (`manifests` `[]` on both sides, every
    other key equal); 767 are byte-identical. 460 rather than step 537's
    163 because this set includes the non-media fixtures (`.bin`, `.txt`,
    `.der` and so on), which are refused as unsupported with
    `general.error` and so fall under AC12 too.
  - No fatal error on either side.
- **`composer check`:** exit 0.
  - pest: 1,748 passed, 1 skipped;
  - api-check: 217 symbols;
  - spec-check: 91 specs;
  - phpstan clean.
- **`bin/package-check.php`:** 679 files, 6.4 MB, 2.3 MB as a zip.

## Left

Each on Maurice's word:
1. commit and push, then CI;
2. tag `v0.11.1`, then Packagist.
