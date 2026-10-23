# Step 374 — SPEC-075 and ADR-0006 drafted: the crJSON document

*2026-10-23.* The second crJSON spec of step 370, after Maurice agreed
with the outline and its five choices (embedded files as `c2pa-rs`
writes them, `Verifier::VERSION`, the minimal example's `@context`,
`toCrJson()` with the time on the report, a dev-only schema validator).
`specs/SPEC-075-crjson-document.md` and
`docs/adr/ADR-0006-json-schema-validator-for-tests.md` are drafts. No
repository code.

## Measured for the draft

Against `c2patool` 0.28.2 `--crjson` on the 59 files of
`tests/Fixtures/c2patool/crjson/`, with scratch scripts outside the
repository:

- Manifest order: this verifier's store order reversed equals
  `c2patool`'s on 59 of 59.
- Delta placement: equal in 56 of 59. The three others are files where
  one manifest is named by two ingredient assertions; SPEC-021 AC9
  validates it once, under the first, and `c2pa-rs` lists both.
- Codes: differ in 42 of 59, every difference one `docs/comparison.md`
  names. The report's codes are tested against `c2patool` elsewhere.
- Claims mapped faithfully with SPEC-074: 18 of 18 v2 claims are
  schema-valid; 53 of 74 v1 claims are not, 49 for a missing
  `claim_generator_info`. `c2pa-rs` makes one up and rewrites v1's
  relative URIs as absolute.
- Embedded files: 156 thumbnails and icons, all `{format, identifier,
  hash}`.
- The programme's rubrics (34 files): `validationResults` 72 mentions,
  `assertions` 142, `label` 52, `claim.v2` 10; `signature`,
  `certificateInfo`, `jsonGenerator`, `validationTime` none.
- `opis/json-schema` 2.6.0 (Apache-2.0, draft 2020-12, 57.7 million
  downloads); `justinrainbow/json-schema` stops at draft-07.

## Open questions in the draft

1. v1 claims without `claim_generator_info`: faithful (proposed) and
   the schema error accepted by name, or `c2pa-rs`'s made-up value.
   Blocking.
2. A file without a store: `manifests: []` with the failure on standard
   error (proposed), or no crJSON at all.
