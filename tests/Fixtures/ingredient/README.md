# The ingredient variants (step 54, SPEC-020)

Signed manifests with one ingredient assertion *added* to the PNG
fixture's store: an assertion box in front of the thumbnail, an entry in
the claim's `created_assertions`, the data hash re-bound to the longer
store, and the claim re-signed with a throw-away P-256 hierarchy (keys
outside the repository, deleted at the end of the run; the public root in
`throw-away-root.pem` and, as the only anchor, in
`throw-away-root.settings.json`). `bin/make-ingredient-variants.php
<scratch>` builds them; regenerating changes the root and every signature,
so the c2patool JSON under `../c2patool/ingredient/` is regenerated with
them. Unsigned, every one of these would be `Invalid` for the signature
alone and prove nothing about the rule under test.

| variant | the ingredient assertion | c2patool 0.27.22 | this verifier (SPEC-020) |
|---|---|---|---|
| `componentof` | v3, `componentOf`, title/format/instanceID, no manifest — the control | `Valid`; `ingredient.unknownProvenance` under `ingredientDeltas` | the same (AC3) |
| `inputto` | the same with `inputTo` | `Valid`, no deltas | the same (AC4) |
| `no-relationship` | v3 without `relationship` | exit 1, "the assertion had a mandatory field missing" | `assertion.ingredient.malformed`, `Invalid` (AC2 a) |
| `relationship-childof` | `relationship: "childOf"` | exit 1 (as above) | malformed (AC2 b) |
| `relationship-int` | `relationship: 42` | exit 1 (as above) | malformed (AC2 c) |
| `v4` | label `c2pa.ingredient.v4` | exit 1, "Ingredient version to new" | malformed (AC2 d) |
| `v1-no-title` | `c2pa.ingredient` without `dc:title` | exit 1 (mandatory field missing) | malformed (AC2 e) |
| `manifest-no-results` | v3 with `activeManifest` (a label not in the store) and no `validationResults` | `Invalid`: `assertion.ingredient.malformed` ("ingredient V3 must have validation results") and `ingredient.manifest.missing` in the delta | malformed at parse; the reference is not followed (AC2 f) |
| `manifest-and-dst` | v3 with `activeManifest` next to `digitalSourceType` (and `validationResults`) | `Invalid`: `ingredient.manifest.missing` only — c2pa-rs has no rule for the pair | malformed (AC2 g; the specification's rule, §18.16.12.3 — stricter, named) |
| `hash-text` | v3 whose `activeManifest.hash` is a text string | `Invalid`: `ingredient.manifest.missing` only — the text decoded as a hash | malformed (AC2 h — a hashed URI's hash is bytes, SPEC-011; stricter, named) |
| `data-array` | the content is the CBOR array `[1, 2]` | exit 1, "could not decode assertion" | malformed (AC2 i) |

Every variant is `Valid` under this verifier *before* SPEC-020 (measured
2026-09-22 with `bin/c2pa-verify`): the signature and the binding are
intact, and nothing reads the assertion.
