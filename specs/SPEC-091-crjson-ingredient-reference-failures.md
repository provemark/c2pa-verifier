# SPEC-091: crJSON — a failed ingredient reference also on the manifest that holds it

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-11-14 ("goedgekeurd, open vragen zoals voorgesteld") |
| Supersedes | SPEC-075 AC5, in part (where a delta's failures are written) |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-020 AC6 reports `ingredient.manifest.missing` once, in the
ingredient's delta, where `c2patool` reports it twice: in the delta and
in the active manifest's own results. SPEC-020 left that as a question
about the report's shape, with the verdict equal and the drift alarms
comparing unique codes. SPEC-075 AC5 carries the same shape into crJSON:
a delta goes into `ingredientDeltas` of the manifest that holds the
assertion, and into the `validationResults` of the manifest it names.
A delta that names no manifest of the store therefore reaches no
`validationResults` at all.

That now matters, because the C2PA Conformance Program judges a
validator's crJSON with Asset Rubrics, and the integrity rubric
(`asset-rubric-integrity.yml`, rubric 1.0, `c2pa-org/conformance-public`
`380fbd7`) reads only `manifests[0].validationResults`. It never reads
`ingredientDeltas`. Both codes concerned are on its
`$well_formed_error_codes` list.

Measured on 2026-11-14 through the Drupal module's harness, on
`ingredient/manifest-and-dst.png` and `ingredient/hash-text.png` with the
conformance test root as both lists:

- The report is `Invalid`, exit 1.
- The only fault, `assertion.ingredient.malformed`, sits in the delta.
- The rubric finds `valid_success` and `well_formed_success` **true**.
  Only `trusted_success` fails, and only because these fixtures' signer is
  not the test root.

`c2patool` 0.27.22 writes `ingredient.manifest.missing` for both files,
in the delta and under `activeManifest`, so its output fails
`well_formed_success`.

Reasoned, not measured: an asset with this fault under a trusted signer
passes every rubric trait from this verifier, while the verifier calls it
`Invalid`. A conformance evaluation would read that as a validator that
misses the fault.

What `c2pa-rs` does was measured over every `c2patool` report under
`tests/Fixtures/c2patool/` (old JSON form, 60 delta failures). It repeats
under `activeManifest`, with the same code and url, exactly one code:

| | `ingredient.manifest.missing` | every other delta failure |
|---|---|---|
| also under `activeManifest` | 5 of 5 | 0 of 55 |

The 55 cover `ingredient.manifest.mismatch`,
`ingredient.claimSignature.mismatch`, `assertion.hashedURI.mismatch` and
`signingCredential.*`. The five files are both copies of
`adobe-20220124-E-clm-CAICAI.jpg`, `ingredient/manifest-and-dst`,
`ingredient/manifest-no-results` and `ingredient/hash-text`. In the last
three this verifier reports `assertion.ingredient.malformed` instead, and
is stricter (step 54).

The two codes differ in kind from the rest. Each says the holder's own
ingredient assertion failed: its reference resolves to no manifest
(§15.11.3.3.2), or the assertion itself is malformed (§18.16.12). The
other codes are about the ingredient's manifest, and that manifest's own
`validationResults` already carries them (SPEC-075 AC5).

## Scope

**In scope**

- crJSON only (`CrJsonDocument::of()`, so `--crjson` too). A failure with
  code `ingredient.manifest.missing` or `assertion.ingredient.malformed`
  in a delta is also written in the `validationResults.failure` of the
  manifest whose `ingredientDeltas` holds that delta.
- `docs/comparison.md`: the difference SPEC-020 said was named there and
  is not (0 mentions, checked 2026-11-14), and what this spec changes about
  it.
- `docs/conformance-harness.md`: one sentence.

**Out of scope** (each needs its own spec before it may be built)

- The report (`toArray()`, `validation_state`, the statuses). SPEC-020 AC6
  stands: the report holds each code once, scoped. No verdict moves.
- Any other delta code. `c2pa-rs` keeps them in the delta, and so does
  this verifier.
- Reporting `ingredient.manifest.missing` where this verifier reports
  `assertion.ingredient.malformed` (step 54's stricter answers).
- `informational` and `success` entries of a delta.

## Behavior

- **AC1 — the missing manifest, as `c2patool` writes it**
  - Given `public-testfiles/adobe-20220124-E-clm-CAICAI.jpg` and its copy
    in `c2pa-rs/`
  - When written as crJSON
  - Then:
    - `manifests[0].validationResults.failure` holds
      `ingredient.manifest.missing` once, with url
      `contentbeef:urn:uuid:8bb8ad50-ef2f-4f75-b709-a0e302d58019` and the
      delta's explanation;
    - the delta under `manifests[0].ingredientDeltas` is unchanged;
    - the unique failure codes of `manifests[0]` equal those of
      `c2patool`'s crJSON for the file
      (`tests/Fixtures/c2patool/crjson/…`), which they do not today.

- **AC2 — a malformed ingredient assertion**
  - Given `ingredient/manifest-and-dst.png`, `ingredient/hash-text.png`
    and `ingredient/manifest-no-results.png`
  - When written
  - Then `manifests[0].validationResults.failure` holds the delta's
    `assertion.ingredient.malformed`, url and explanation as in the
    delta, once.

- **AC3 — on the manifest that holds the delta, not on the active one**
  - Given
    `graph-fuzz-cycle-acknowledged/cycle-below-the-active-acknowledged.png`,
    whose delta with `assertion.ingredient.malformed` sits on a manifest
    other than the active one
  - When written
  - Then that manifest's `validationResults.failure` holds it, and
    `manifests[0].validationResults` is unchanged.

- **AC4 — other delta codes stay where they are** *(error path)*
  - Given `public-testfiles/adobe-20220124-E-uri-CIE-sig-CA.jpg`
    (`assertion.hashedURI.mismatch` in an ingredient's manifest) and
    `spec052/crc32b-reference.jpg` (`ingredient.manifest.mismatch`)
  - When written
  - Then `manifests[0].validationResults` is byte-identical to today's.

- **AC5 — no duplicate, nothing else moves**
  - Given every fixture media file
  - When written before and after
  - Then:
    - a document changes only where a delta holds one of the two codes:
      13 deltas today, 2 of them on a non-active manifest (measured
      2026-11-14 over 953 files read);
    - the change is only the added failure entries;
    - an entry already present with the same code and url is not added
      again;
    - report, exit status and every other byte are the same.
  - Against the 59 `c2patool` answers, the unique failure codes of
    `manifests[0]` equal `c2patool`'s in 48 files, where they are equal in
    46 today. The two that move are AC1's. The 11 that stay are named in
    `docs/comparison.md`: `signingCredential.expired`, `general.error` for
    CAWG, and `video1.mp4`.

- **AC6 — the rubric**
  - Given AC1's and AC2's documents
  - When `asset-rubric-integrity.yml` judges them
    (`c2pa_conformance_rubric_evaluator.py`, conformance-public `380fbd7`)
  - Then `well_formed_success` is false, naming the code.
  - Measured once by hand and recorded in the step note. The evaluator is
    Python and not a dependency, so this is not a Pest test.

## References

- Specification: C2PA 2.4 §15.11.3.3.2 (`ingredient.manifest.missing`),
  §18.16.12 (the ingredient assertion), §15.2.1 (results per manifest and
  ingredient deltas); crJSON 2.4 §3.8 (`validationResults`,
  `ingredientDeltas`).
- Oracle:
  - `c2patool` 0.27.22 and 0.28.2, `tests/Fixtures/c2patool/` (the old
    JSON form) and `tests/Fixtures/c2patool/crjson/`;
  - the rubric and evaluator from `c2pa-org/conformance-public` `380fbd7`.
- Measured, 2026-11-14:
  - the 60-delta table above;
  - the 46/59 comparison;
  - the 13 deltas in 953 files;
  - the two harness runs and the rubric.

  Records are in the Drupal module's local notes
  (`conformance-evidence-2026-11-14/`). This verifier's own step note will
  repeat each count with its command.
- Reasoned: that a trusted asset with this fault passes every rubric
  trait; that the two codes are about the holder's assertion and the
  others about the ingredient's manifest.

## API sketch

```php
// Provemark\C2paVerifier\Verifier\CrJsonDocument::of(), after $held is built:
// for each manifest, its own codes gain, under 'failure', every entry of
// its held deltas whose code is one of
//   StatusCode::IngredientManifestMissing, StatusCode::AssertionIngredientMalformed
// not already present by (code, url). The deltas themselves are untouched.
private const LIFTED = ['ingredient.manifest.missing', 'assertion.ingredient.malformed'];
```

## Open questions

1. **Which codes.**
   - *Proposal:* both, `ingredient.manifest.missing` and
     `assertion.ingredient.malformed`. Each is a failure of the holder's
     own assertion, and both are on the rubric's well-formed list.
   - *Alternative:* `ingredient.manifest.missing` only, which is exactly
     what `c2pa-rs` does (5 of 5, 0 of 55). AC2 and AC3 would then go: the
     three ingredient fixtures would keep the rubric gap, because this
     verifier reports the other code there.
   - Blocking.
   *Status 2026-11-14:* settled as proposed, both codes.
2. **A non-active holder (AC3).**
   - *Proposal:* the holder, whichever manifest it is, so that each
     manifest's results describe its own assertions.
   - *Alternative:* the active manifest only, as the five measured
     `c2pa-rs` cases do; all five are on the active manifest, so a nested
     case is unmeasured there.
   - Non-blocking.
   *Status 2026-11-14:* settled as proposed, the holder.
3. **Release.**
   - *Proposal:* a patch, 0.11.3. No verdict, report field or contract
     symbol moves, only crJSON entries are added, as with SPEC-075
     amendment 4 in 0.11.1.
   - The Drupal module asks `^0.11`, so it takes 0.11.3 without a change.
     Its conformance evidence is then run again.
   - Non-blocking.
   *Status 2026-11-14:* settled as proposed, 0.11.3.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Verifier/CrJsonIngredientReferenceTest.php :: AC1: the missing manifest is also on the active manifest, as c2patool writes it (2 files); AC1: the delta itself is left as the report has it / SPEC-091 | src/Verifier/CrJsonDocument.php :: of(), withHeldReferenceFailures(), HOLDER_CODES |
| AC2 | tests/Unit/Verifier/CrJsonIngredientReferenceTest.php :: AC2: a malformed ingredient assertion is also on the active manifest (3 files) / SPEC-091 | src/Verifier/CrJsonDocument.php :: HOLDER_CODES |
| AC3 | tests/Unit/Verifier/CrJsonIngredientReferenceTest.php :: AC3: on the manifest that holds the delta, not on the active one / SPEC-091 | src/Verifier/CrJsonDocument.php :: of() (the held deltas of each manifest) |
| AC4 | tests/Unit/Verifier/CrJsonIngredientReferenceTest.php :: AC4: other delta codes stay where they are (2 files) / SPEC-091 | src/Verifier/CrJsonDocument.php :: withHeldReferenceFailures() |
| AC5 | tests/Unit/Verifier/CrJsonIngredientReferenceTest.php :: AC5: each held entry once, and nothing but held entries is added (4 files); AC5: against c2patool … equal in 48 of 59 files; AC5: two deltas naming the same missing manifest add it once…; tests/Unit/Verifier/CrJsonDocumentTest.php :: AC5 (SPEC-075, superseded in part); the corpus before and after (notes/step-546-crjson-ingredient-reference-failures.md) | src/Verifier/CrJsonDocument.php :: withHeldReferenceFailures(), codeAndUrl() |
| AC6 | measured by hand, notes/step-546-crjson-ingredient-reference-failures.md | — |
