# Step 123 — The rest of the actions content family, measured

*2026-09-24. Measurement only: no specification, test or code changed.
This sizes work for 0.3; it is not a release blocker.*

## Why

SPEC-018 put *"the content family of c2pa-rs's `verify_actions` 2.b–2.f"*
out of scope by name, and SPEC-032 took one rule out of it, a
`c2pa.created` without `digitalSourceType`. This step asks how much of
the rest `c2patool` enforces, and so how large the remaining gap is,
before anything is specified.

## The rules (read)

In `c2pa` 0.91.0, `claim.rs` `verify_actions()`, lines 2167–2859, for v2
claims:

- only one `c2pa.created` or `c2pa.opened`, and it must come first;
- 2.b: `c2pa.opened`, `c2pa.placed` and `c2pa.removed` need `parameters`
  with a non-empty `ingredients` list, resolving to an ingredient
  assertion with the right relationship (`parentOf` for opened,
  `componentOf` for placed and removed), else
  `assertion.action.ingredientMismatch`;
- 2.b.v: `c2pa.created` needs a `digitalSourceType` (done in SPEC-032);
- `c2pa.translated` needs `sourceLanguage` and `targetLanguage`;
- 2.c: a v1-style `ingredient` parameter must be a valid `parentOf`;
- 2.d: `c2pa.redacted` needs a resolvable reference;
- 2.e/2.f/2.h: icons in `softwareAgents` and `templates` are verified as
  hashed URIs;
- 2.f: `relatedAssertions` must be a non-empty list of resolvable
  references that are not ingredient or actions assertions;
- a watermark action needs a soft-binding assertion
  (`assertion.action.softBindingMissing`).

## Measured

Probes were made from `fixture-unsigned.jpg` with step 110's scratchpad
CA and signed by `c2patool` 0.28.0, then verified under the root by
0.27.22, 0.28.0 and this verifier:

| probe | 0.27.22 | 0.28.0 | here |
|---|---|---|---|
| `c2pa.created` then `c2pa.opened` | `Invalid` (`ingredientMismatch`) | `Invalid` (`ingredientMismatch`, `malformed`: *"cannot have more than one c2pa.created or c2pa.opened"*) | **`Trusted`** |
| `c2pa.edited` then `c2pa.created` | `Invalid` (`malformed`) | `Invalid` (`malformed`) | `Invalid` (`malformed`), already covered by SPEC-018 |
| a second actions assertion holding `c2pa.created` | — | — | — (`c2patool` could not embed it) |
| `c2pa.placed` without parameters | `Invalid` (`ingredientMismatch`) | `Invalid` (the same) | **`Trusted`** |
| `c2pa.placed` with an empty `ingredients` list | `Invalid` (`ingredientMismatch`) | `Invalid` (the same) | **`Trusted`** |
| `c2pa.placed` naming an ingredient that does not resolve | `Invalid` (`ingredientMismatch`) | `Invalid` (the same) | **`Trusted`** |
| `c2pa.translated` without languages | `Invalid` (`malformed`) | `Invalid` (the same) | **`Trusted`** |
| `c2pa.redacted` without a reference | `Trusted` | `Trusted` | `Trusted` |
| `relatedAssertions: []` | `Trusted` | **`Invalid`** (`malformed`) | `Trusted` |
| `c2pa.watermarked.bound` without a soft binding | `Trusted` | **`Invalid`** (`softBindingMissing`) | `Trusted` |

`StatusCode` holds neither `assertion.action.ingredientMismatch` nor
`assertion.action.softBindingMissing`.

## Size, reasoned from the table

- **Five probes are `Invalid` in both oracles and `Trusted` here**: one
  opening only, the ingredient parameters of placed (and so of opened and
  removed), and `c2pa.translated`. This is the same kind of gap SPEC-032
  closed, and the larger part of what remains.
- **Two are new in 0.28.0**: `relatedAssertions` and the watermark soft
  binding.
- **Not probed**: the icon checks (a hashed-URI icon that fails needs
  surgery to build), 2.c's v1-style `ingredient` parameter, and the
  `opened` → `parentOf` resolution with a real parent ingredient (it needs
  an ingredient added by the builder, which `c2patool` can do from a
  file).
- None of this lets a changed byte through. All of it is category 2 of
  `docs/conformance.md`.

An estimate, not a measurement: **one specification** of about eight
criteria and **two new status codes**, which would grow the contract by
two symbols. It rests on M7's ingredient model, which already resolves
ingredient assertions and their relationships (SPEC-020). It is about the
size of SPEC-031, and a natural first piece of work for 0.3.
