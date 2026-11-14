# Step 546 — SPEC-091: a failed ingredient assertion also on the manifest that holds it

*2026-11-14.* Maurice asked to "schrijf de verifier-spec voor de
crJSON-schrijver", then "goedgekeurd, open vragen zoals voorgesteld".

## Why

The finding came from the Drupal module's conformance evidence run on
1.0.0-alpha7, which used this verifier 0.11.2. The C2PA Conformance
Program's integrity rubric (`asset-rubric-integrity.yml`, rubric 1.0,
`c2pa-org/conformance-public` `380fbd7`) reads only
`manifests[0].validationResults`.

This verifier writes a failed ingredient assertion only in the delta
(SPEC-020 AC6, SPEC-075 AC5). So `ingredient/manifest-and-dst.png` and
`ingredient/hash-text.png` were `Invalid`, exit 1, while the rubric found
`valid_success` and `well_formed_success` true. `c2patool` lists
`ingredient.manifest.missing` under `activeManifest` too. SPEC-020 said
that difference was named in `docs/comparison.md`; it was not.

## Measured before writing the spec

- **What c2pa-rs repeats under `activeManifest`.** Over all 60 delta
  failures in the `c2patool` reports under `tests/Fixtures/c2patool/` (old
  JSON form), the same code and url appear under `activeManifest` for
  `ingredient.manifest.missing` in 5 of 5, and for every other code in 0
  of 55.
  - The five: both `E-clm-CAICAI` copies, `ingredient/manifest-and-dst`,
    `manifest-no-results`, `hash-text`.
  - The 55: `signingCredential.untrusted` 25, `.expired` 8,
    `ingredient.manifest.mismatch` 5, `assertion.hashedURI.mismatch` 4,
    `ingredient.claimSignature.mismatch` 4, and others 1–2 each.
- **Rubric lists.** `ingredient.manifest.missing` and
  `assertion.ingredient.malformed` are on `$well_formed_error_codes`. The
  `ingredient.*.mismatch` codes and `assertion.hashedURI.mismatch` are on
  `$valid_error_codes`.
- **Against the 59 crJSON answers.** The unique failure codes of
  `manifests[0]` equal `c2patool`'s in 46 files. Of the 13 others, 2 (both
  `E-clm` copies) differ only by the missing code. The other 11 are
  `signingCredential.expired` ×8, CAWG `general.error` ×2 and `video1.mp4`.
- **The corpus.** Read `--crjson` over 953 fixture media files. Deltas
  with either code: 13, 2 of them on a non-active manifest (both in
  `graph-fuzz-cycle-acknowledged/`).

## Built

- `src/Verifier/CrJsonDocument.php`: `HOLDER_CODES`,
  `withHeldReferenceFailures()` and `codeAndUrl()`. For each manifest,
  after its own results, the held deltas' failures with one of the two
  codes are appended, once per code and url.
- `tests/Unit/Verifier/CrJsonIngredientReferenceTest.php`, group
  SPEC-091: 15 tests.
- `tests/Unit/Verifier/CrJsonDocumentTest.php` SPEC-075 AC5: the first
  manifest's results are now the report's active results plus the held
  entries (SPEC-091 supersedes AC5 in part).
- `docs/comparison.md`: new section *Ingredient failures in the report and
  in crJSON*.
- `docs/conformance-harness.md`: one paragraph.
- `CHANGELOG.md`: *Unreleased*.

## Red first

- **The new tests before the code:** 9 failed, 5 passed.
  - The 5 green ones pin what must not move: the delta unchanged; AC4's
    two files; AC5 on a file without either code; the cycle file's first
    manifest.
  - The 9 red: AC1 ×2 ("actual size 0 matches expected size 1"), AC2 ×3,
    AC3, AC5 ×2, and the 48-of-59 count (46).
- **After the code:** SPEC-075's AC5 test went red on `E-clm-CAICAI`. That
  is the part SPEC-091 supersedes, and the test was changed to the new
  rule.
- **The once-per-code-and-url rule:** no fixture holds the same entry
  twice, so mutation M3 survived the first pass. A direct test through
  `ReflectionMethod` was added, as `MdatMerkleTest` does.

## Mutations

Each was run against groups SPEC-091 and SPEC-075, and each went red:

| mutation | result |
|---|---|
| M1 `assertion.ingredient.malformed` not lifted | 5 failed |
| M2 the active manifest only | 1 failed (AC3) |
| M3 no de-duplication | 1 failed (after the direct test was added) |
| M4 every delta code lifted | 7 failed |
| M5 nothing lifted | 10 failed |
| M6 entries put in front instead of after | 1 failed |

## The corpus before and after

`python3 -I snap.py` (in the scratchpad) ran `php bin/c2pa-verify --crjson
--at 2026-11-14T00:00:00Z` over the same 953 files before and after.

- Exit status and SHA-256 of standard error are identical for all 953.
- 13 documents differ. In each, the only difference is entries appended
  to some manifests' `validationResults.failure`, all with one of the two
  codes:
  - `ingredient.manifest.missing`: the two `E-clm-CAICAI` copies;
  - `assertion.ingredient.malformed`: the two cycle files and nine under
    `ingredient/` (`data-array`, `hash-text`, `manifest-and-dst`,
    `manifest-no-results`, `no-relationship`, `relationship-childof`,
    `relationship-int`, `v1-no-title`, `v4`).
- Every other byte of every document is unchanged.

## AC6 — the rubric, by hand

The integrity rubric and `c2pa_conformance_rubric_evaluator.py` (from
`380fbd7`) were run over the new crJSON at 2026-11-14T00:00:00Z:

| file | `well_formed_success` |
|---|---|
| `E-clm-CAICAI` (both copies) | false — `ingredient.manifest.missing`; now the same three false traits as `c2patool`'s own crJSON |
| `ingredient/manifest-and-dst`, `hash-text`, `manifest-no-results` | false — `assertion.ingredient.malformed` |

## Check

`composer check`: exit 0, 1,772 passed, 1 skipped; contract surface 217
symbols, unchanged; spec-check OK (92 specs, 127 test files).

Two PHPStan findings in the new test code were fixed: offset access on
`mixed`. Pint wanted the reflected class imported.

## Not done

No commit and no version yet. 0.11.3 is a patch (open question 3); its
preparation is a step of its own.
