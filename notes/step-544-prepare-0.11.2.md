# Step 544 — Prepare 0.11.2

*2026-11-13.* Maurice: "bereid verifier 0.11.2 voor". A patch, as SPEC-073
amendment 3's open question 3 settled: the API now refuses a validation
time whose UTC year lies outside 0001–9999 (steps 542–543, `84c52a7`). No
contract symbol changes, and such a time never gave a schema-valid
crJSON document. A caller on `^0.11`, the library and the Drupal module
among them, receives it without a change of constraint. The library's
own SPEC-047 refusal stays in front of it.

Since `v0.11.1`:
- step 540 made SPEC-086 AC6 compare two runs on one time (test only);
- step 541 recorded the API gap as a candidate;
- steps 542–543 built SPEC-073 amendment 3.

## What changes

- **`CHANGELOG.md`.** The *Unreleased* section becomes **0.11.2 —
  2026-11-13**, with an introduction holding the comparison below.
- **The version.** `Verifier::VERSION` is `0.11.2`.
- **`README.md`.** The sentence on the current tag names 0.11.2 and what
  it changes.
- **`SECURITY.md`.** Unchanged: no verdict moves.

## Measured

- **CI on `84c52a7`**: run 43481550559, 8 of 8 jobs `success`.
- **Against `v0.11.1`:**
  - a worktree at `v0.11.1` with a copied `vendor/` and its own
    autoloader, checked to load its own `src/` (`VERSION` 0.11.1);
  - the 1,227 files of step 538 (every file under `tests/Fixtures/`
    except `.md`, `.json`, `.pem` and `.php`);
  - without settings and with the conformance tool's trust settings
    (step 535), validation time 2026-11-14T00:00:00Z;
  - the ordinary report with exit status, and `--crjson`, per side.

  Results:
  - **Report and exit status:** identical in all 2,454 runs.
  - **crJSON:** every document, 1,227 in each configuration, differs only
    in `jsonGenerator.version` (`0.11.1` → `0.11.2`).
  - No fatal error and no exit 2 on either side.

  The first run of this comparison called the script by a relative path,
  so the settings file was not found and the configuration with settings
  exited 2 on both sides; it read as identical. It was rerun with the
  absolute path, and the counts above are from that run.

  The command line refuses an out-of-range time itself (exit 2), so it
  never reaches the new check. The comparison shows only that nothing
  else moved. The new check is shown by SPEC-073 amendment 3's tests.
- **`composer check`:** exit 0.
  - pest: 1,757 passed, 1 skipped;
  - api-check: 217 symbols;
  - spec-check: 91 specs;
  - phpstan clean.
- **`bin/package-check.php`:** 683 files, 6.4 MB, 2.3 MB as a zip. It
  counts `git archive` of the committed tree, so this note, once
  committed, makes 684 (step 538 met the same off-by-one).

## Left

Each on Maurice's word:
1. commit and push, then CI;
2. tag `v0.11.2`, the GitHub release, then Packagist.
