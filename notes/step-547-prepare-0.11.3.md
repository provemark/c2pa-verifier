# Step 547 — Prepare 0.11.3

*2026-11-14.* Maurice: "bereid verifier 0.11.3 maar voor". A patch, as
SPEC-091's open question 3 settled. A failed ingredient assertion is now
also written in crJSON on the manifest that holds it (step 546,
`56659da`, with `c2fde5e` fixing a test helper). No verdict, report field
or contract symbol moves. A caller on `^0.11`, the library and the Drupal
module among them, receives it without a change of constraint. The
module's conformance evidence is run again on it afterwards.

## What changes

- **`CHANGELOG.md`.** *Unreleased* becomes **0.11.3 — 2026-11-14**, with
  an introduction holding the comparison below.
- **The version.** `Verifier::VERSION` is `0.11.3`.
- **`README.md`.** The sentence on the current tag names 0.11.3 and what
  it changes.
- **`SECURITY.md`.** Unchanged: no verdict moves.

## Measured

- **CI on `c2fde5e`:** run 43598540343, 8 of 8 jobs `success`. CI on
  `56659da` was red; step 546 records why.
- **Against `v0.11.2`:**
  - Setup: a worktree at `v0.11.2` with a copied `vendor/` and its own
    autoloader, checked to load its own `src/` (`VERSION` 0.11.2).
  - Files: the 1,221 under `tests/Fixtures/` except `.md`, `.json`,
    `.pem` and `.php`. Step 544 counted 1,227 with the same filter; the
    difference was not traced.
  - Configurations, all at 2026-11-14T00:00:00Z:
    - none;
    - the programme's official lists as `--trust-list` /
      `--tsa-trust-list` (the JSON lists of 2026-11-14, sequence 4,
      SHA-256 `266b4a57…` and `40c59c43…`);
    - the conformance test root
      (`tests/Fixtures/conformance-test/root.settings.json`).
  - Per file and side: the ordinary report with exit status, and
    `--crjson`. Absolute paths throughout (step 544's lesson). The run
    was started twice by mistake and the second was stopped; the counts
    are the first run's.

  Results:
  - **Report and exit status:** identical in all 3,663 runs. No exit 2.
  - **crJSON:** in each configuration, 1,208 documents differ only in
    `jsonGenerator.version` (`0.11.2` → `0.11.3`).
  - **The other 13** are the same 13 in each configuration. Each was
    checked again per configuration: apart from the version, the only
    difference is entries appended to a manifest's
    `validationResults.failure`, all with `ingredient.manifest.missing` or
    `assertion.ingredient.malformed`.
  - The 13 files: both `adobe-20220124-E-clm-CAICAI.jpg` copies; two
    under `graph-fuzz-cycle-acknowledged/`; nine under `ingredient/`.
- **`composer check`:** exit 0.
  - pest: 1,772 passed, 1 skipped;
  - api-check: 217 symbols;
  - spec-check: 92 specs.
- **`composer test:parallel`**, as CI runs it: exit 0, 1,772 passed,
  1 skipped.
- **`bin/package-check.php`:** 687 files, 6.5 MB, 2.3 MB as a zip, counted
  on the committed tree. Once this note is committed, 688.

## Left

Each on Maurice's word:
1. commit and push, then CI;
2. tag `v0.11.3`, the GitHub release, then Packagist;
3. downstream: the Drupal module's evidence run again on 0.11.3.
