# Step 355 — SPEC-070: ingredient failures the signer acknowledged are reported

*2026-10-13.*

C2PA 2.4 §18.16.12.4 lets a writer record an ingredient's validation
failures in the ingredient assertion, as an acknowledgement. `c2pa-rs`
then drops those statuses from the report, and SPEC-021 AC4 copied that.
N. Krawetz ("Malicious nested manifest alteration", 2025) describes the
abuse: a signer can hide a tampered ingredient this way. Maurice approved
SPEC-070 today. The rule and the verdict stay as they are, and the fact is
now reported.

## What changed

- `Report\ValidationStatus` gains `public bool $acknowledged = false`, its
  last constructor parameter. The public contract grows from 135 to 136
  symbols, hence 0.7.0 (Maurice's decision).
- `ValidationResult::fromStatuses()` skips an acknowledged status, and
  `toArray()` renders it as informational under its ingredient's deltas. It
  never appears in `validation_status`, which holds failures only.
- `IngredientManifestCheck::drop()` becomes `acknowledge()`. A recorded
  failure comes back marked, its explanation prefixed *"acknowledged by the
  signer of <label> in its ingredient assertion (C2PA 2.4 §18.16.12.4),
  not counted in the verdict:"*. The guard (the active manifest, and an
  update manifest's binding manifest) is unchanged.
- **Amendment 1, decided while building:** only failures. A recorded
  success or informational line is still dropped, because it hides
  nothing. Reading "every status" literally brought five success lines
  back on one ingredient of a redaction fixture.

## Callers checked

The WordPress plugin reads `validation_status` (failures only). The sister
library's `ManifestStoreParser` reads only `success` and `failure` from an
ingredient's deltas. Neither sees the new lines as failures. A PHP caller
that walks `result->statuses` and asks `code->isFailure()` would. The
CHANGELOG for 0.7.0 must say so.

## Measured

- Tests first: 6 failed before, green after (with amendment 1).
- Mutations caught: the verdict counting acknowledged statuses, rendering
  by code kind, an acknowledged `untrusted` holding `Trusted` down, the
  active-manifest guard removed, successes acknowledged too.
- AC4, the corpus before and after (793 files, two modes): no state
  changes. With the acknowledged lines left out, every code list is
  identical to before. Under the full trust settings, 31 files gain
  acknowledged lines: `signingCredential.untrusted` 27 times,
  `claimSignature.mismatch` 3, and `assertion.hashedURI.mismatch`,
  `assertion.cloud-data.hardBinding` and `signingCredential.expired` once
  each.
- `composer check`: 1,064 passed. Contract: 136 symbols.
