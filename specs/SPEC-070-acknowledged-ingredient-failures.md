# SPEC-070: Ingredient failures the signer acknowledged are reported, not dropped

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | SPEC-021 AC4 in one respect: what the ingredient assertion recorded is no longer absent from the validation results |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

C2PA 2.4 §18.16.12.4: a failure recorded in an ingredient assertion
(`validationStatus` in v1/v2, `validationResults` in v3) *"is considered an
explicit statement by the claim generator that an actor has acknowledged
validation errors in the ingredient's C2PA Claim itself and has chosen to
proceed"*. `c2pa-rs` implements that by dropping every status the
ingredient assertion recorded from the report. SPEC-021 AC4 copies it
(Maurice's decision, 2026-09-22, step 53 §4.2), with `c2pa-rs`'s guard
that no status about the active manifest is ever dropped (CAI-12751). So
`public-testfiles/adobe-20220124-CIE-sig-CA.jpg`, whose ingredient's
signature is genuinely broken, is `Trusted` here and in `c2patool`, and its
validation results say nothing about that ingredient.

N. Krawetz, "C2PA Issues and Evaluation" (2025-06-09, "Malicious nested
manifest alteration"), describes the abuse. A signer may alter an
ingredient's manifest and record its failures, and every validator that
follows the rule then hides the alteration. The record stays visible in
the manifest rendering (`manifests[<label>].ingredients[].validation_status`,
SPEC-020). But a caller who reads only the validation results, as the
WordPress plugin and the demo do, sees a clean `Trusted` and nothing more.

The rule itself is the specification's, and the verdict stays as it is.
What this spec changes is that the dropped statuses are reported as
informational, with an explanation that says who acknowledged them, so the
fact is in the validation results and never silent.

## Scope

**In scope**

- Every status `IngredientManifestCheck::drop()` removes today: it is kept,
  marked as acknowledged, and rendered under that ingredient's
  `validationDeltas.informational`.
- The verdict: an acknowledged status counts as neither a failure nor a
  success (`ValidationResult::fromStatuses()`).

**Out of scope** (each needs its own spec before it may be built)

- Changing the rule: acknowledged failures still do not make a file
  `Invalid`. That would be a refusal against the specification, and the
  maintainer decided otherwise in step 53.
- The guard (never dropped about the active manifest, nor about an update
  manifest's binding manifest): unchanged, these stay failures.
- What the plugin and the demo display: each repository decides that for
  itself, from the new informational.

## Behavior

- **AC1 — an acknowledged failure is reported as informational**
  - Given `public-testfiles/adobe-20220124-CIE-sig-CA.jpg`,
    `c2pa-rs/CIE-sig-CA.jpg` and `c2pa-rs/CACAE-uri-CA.jpg` (SPEC-021 AC4)
  - When the Verifier runs, with and without the full trust settings
  - Then the state is what it is today (`Trusted` / `Valid`, as
    `c2patool`), and each status the ingredient assertion recorded appears
    once under that ingredient's `validationDeltas.informational`, with
    its own code and url. Its explanation starts with *"acknowledged by the
    signer of <manifest label> in its ingredient assertion (C2PA 2.4
    §18.16.12.4), not counted in the verdict:"* and then gives the original
    explanation. It is not in `validation_status`, which holds failures only.

- **AC2 — the guard is unchanged** *(required: error path)*
  - Given the cases that pin the guard today
    (`tests/Unit/Verifier/IngredientManifestCheckTest.php`: a recorded
    status whose url names the active manifest, and a recorded failure
    about an update manifest's binding manifest, SPEC-022 amendment 6)
  - When `drop()` runs
  - Then those statuses come back unmarked, so they stay failures and
    make the result `Invalid`. Every other recorded status comes back
    marked as acknowledged. The existing tests that expect it to be absent
    (`drop()` returning `[]`) change to expect it marked. That is the
    one place SPEC-021's tests move.

- **AC3 — the verdict ignores an acknowledged status**
  - Given a `ValidationResult` built from one success
    (`signingCredential.trusted`) and one acknowledged
    `claimSignature.mismatch` scoped to an ingredient
  - When its state is computed
  - Then it is `Trusted`. Without the acknowledged mark, the same statuses
    give `Invalid`.

- **AC4 — nothing else moves**
  - Given the corpora and every fixture
  - When verified before and after
  - Then no state changes. The only new lines are the informational of
    AC1, on the files whose ingredient assertions recorded failures.

## References

- Specification: C2PA 2.4 §18.16.12.4 (recorded validation results),
  §15.11.3 (ingredient validation).
- Oracle: `c2patool` 0.28.1/0.28.2 report nothing for these statuses. This
  verifier says more, in informational only. The state is equal, as the
  drift alarm checks.
- Prompted by: N. Krawetz, "C2PA Issues and Evaluation" (2025-06-09),
  "Malicious nested manifest alteration" and "Mandatory nested manifest
  alteration".
- Reasoned: that no caller treats an informational as a failure. The flat
  `validation_status` list (what the sister library reads) holds failures
  only, so it does not change.

## API sketch

```php
// Report\ValidationStatus — one new constructor parameter, last, default false:
public function __construct(
    public StatusCode $code,
    public string $url,
    string $explanation,
    public ?string $ingredientUri = null,
    public bool $acknowledged = false,   // SPEC-070: recorded by a signer, reported, not counted
) {}

// Report\ValidationResult::fromStatuses(): skip $status->acknowledged when looking for
// success or failure. toArray(): $kind = 'informational' when acknowledged.
// Verifier\IngredientManifestCheck::drop(): instead of filtering, return the status
// re-made with acknowledged: true and the explanation prefixed.
```

## Open questions

- **The public contract (blocking).** `ValidationStatus` is one of the
  contract's classes, and `acknowledged` adds one symbol (135 → 136). It is
  additive: a caller constructing statuses by position is unaffected. Does
  that make the release 0.7.0 rather than 0.6.2? Proposal: 0.7.0, since
  the contract grows, with SPEC-068, SPEC-069 and its amendment 1 in the
  same release.
- **The code (non-blocking).** C2PA 2.4 has no code for "acknowledged".
  The proposal keeps the original code and marks it, rather than inventing
  one (brief §4: no vocabulary of our own). The cost is that a caller who
  reads only codes, not kinds, sees `claimSignature.mismatch` under
  informational. The kind and the explanation say what it is.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
