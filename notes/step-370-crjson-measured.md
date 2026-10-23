# Step 370 — crJSON read and measured, before anything is built

*2026-10-23.* The third step of the plan in step 367. The conformance
programme's harness answers in crJSON. This step reads the format, runs
the reference implementation's crJSON through the format's own schema,
and compares it with the report this verifier already writes. No code.

## The format

C2PA 2.4, "Content Credentials JSON" (crJSON), read from the published
HTML on 2026-10-23. Its §5 embeds a JSON Schema (draft 2020-12, `$id`
`https://c2pa.org/crjson/crJSON.schema.json`; the copy measured against
has SHA-256 `5d9c53ca7df9a9fe…`).

- §1–2: a derived JSON-LD view of a manifest store and its validation
  results, "for profile evaluation, interoperability testing, and
  validation reporting". Not an input format, not lossless, not
  independently verifiable.
- §3.1: three required top-level fields, `@context`, `manifests`,
  `jsonGenerator` (`name`, and `version` in SemVer).
- §3.4: every manifest of the store, in reverse store order, so the
  active manifest is `manifests[0]`. Each has `label`, `assertions`,
  `signature`, `validationResults`, exactly one of `claim` or `claim.v2`,
  optionally `ingredientDeltas`, and **no other property**
  (`additionalProperties: false`).
- §3.5: the claim, serialised as a CBOR assertion is; absent
  `gathered_assertions` and `redacted_assertions` are written as `[]`.
- §3.6: `assertions` is an object keyed by label. JSON-LD assertions are
  copied; CBOR assertions follow Table 1 (byte strings as Base64 with a
  `b64'` prefix, tag-0 dates as their text, non-string map keys turned
  into strings). An assertion nothing can be derived from is `{}`.
- §3.7: `signature` holds `algorithm` (a C2PA name such as `ES256`) and
  `certificateInfo` for the leaf: `serialNumber`, `issuer` and `subject`
  as DN maps, `validity.notBefore`/`notAfter`. With a timestamp it also
  holds `timeStampInfo` with the time and the TSA's leaf.
- §3.8: **validation results per manifest**: `success`, `informational`,
  `failure` (entries with `code` and optional `url`, `explanation`),
  plus `specVersion` and `validationTime` (RFC 3339). Ingredient deltas
  go in the same manifest's `ingredientDeltas`.

There is **no verdict field**. No `validation_state`, no `Valid` or
`Trusted`: a reader of crJSON works the verdict out from the codes.

Three places where the prose and the schema disagree:

| | prose | schema |
|---|---|---|
| the timestamp object | `timestampInfo` | `timeStampInfo` |
| §3.8's results | "a `statusCodes` object along with a `validationTime`" | the three arrays directly under `validationResults`, which also requires `specVersion` |
| a signature with nothing readable | "shall be present as an empty object `{}`" | `algorithm` and `certificateInfo` required |

## `c2patool` 0.28.2 `--crjson` against the schema

Run over the root `fixture-signed.*` files and the `c2pa-rs/`,
`writers/` and `public-testfiles/` corpora: 85 files, 62 with a manifest
(the rest are not C2PA or carry none). Validated with Python
`jsonschema` 4.26.0 (`Draft202012Validator`), in a scratch environment
outside the repository.

- **0 of 62 are schema-valid.** Every manifest carries
  `isUpdateManifest` and `isCompressedManifest`, which the manifest
  definition does not allow (95 manifests).
- With those two keys removed: **55 of 62 valid**. Of the other seven,
  six have a `timeStampInfo` without its required `timestamp`, and one
  (`writers/c2pa-rs-cawg_ica.jpg`) has a v1 claim whose `metadata` is an
  array where the schema wants an object.
- `jsonGenerator` is `{"name": "c2pa-rs", "version": "0.91.2"}`.
  `specVersion` is `"2.3.0"`. `algorithm` is lower case (`"es256"`).
  `serialNumber` is lower-case hex. The thumbnail assertions hold
  `format`, `identifier` and `hash`, not the image.

So the reference implementation does not produce schema-valid crJSON
today. Where it and the schema differ, the schema is the written rule.

## crJSON against `c2patool`'s ordinary report

The same 62 files, `c2patool` without `--crjson`, compared on
`(code, url)` per group:

- `manifests[0].validationResults` equals the ordinary report's
  `validation_results.activeManifest` on **all 62**.
- The ingredient deltas are **the same set** on all 62. In 15 files they
  are distributed differently: crJSON puts each delta on the manifest
  that holds the ingredient assertion, and the ordinary report lists
  them all under the active manifest.
- Each non-active manifest's own `validationResults` equals the delta
  that names it: **31 of 31**. Two manifests no ingredient names (the
  `E-clm-CAICAI` file, in two corpora) have empty results.

This verifier's report already follows `c2patool`'s ordinary
`validation_results` (SPEC-010, and the corpus tests behind it), apart
from the divergences `docs/comparison.md` names. So the
**validation results in crJSON can be derived from what the report
already holds**: the active manifest's statuses as they are, and each
scoped status moved to the manifest whose label its ingredient-assertion
URL names. Nothing new needs to be checked.

What the report does not hold yet is the manifest content in crJSON's
shape: the claim as a map, every assertion decoded by Table 1, and the
leaf certificate as DN maps. The parts exist inside the verifier
(`Claim`, `Assertion::$data` decoded from CBOR, `Certificate`), but the
public report renders a smaller, `c2patool`-reader-shaped view.

## The conformance tool

`contentauth/c2pa-conformance-tool` (`6d10ee0`) builds `c2pa-rs` to
WASM from a submodule at `518fe03` (2026-09-30, main; 32 commits ahead
of and 37 behind `c2pa-v0.91.2`). Its crJSON is therefore `c2pa-rs`'s,
like `c2patool`'s. It was not built or run here: reasoned, not measured.

## What follows

A proposal for the specs, each a step of its own:

1. **CBOR to crJSON values** (Table 1): byte strings as `b64'…`, tags,
   non-string keys, floats. One concept, testable on vectors.
2. **The crJSON document**: root, manifests in reverse order, `label`,
   the claim, the assertions, the per-manifest validation results and
   deltas, `validationTime` (SPEC-073's time, or now), `specVersion`.
   `bin/c2pa-verify --crjson`.
3. **The signature**: `algorithm`, `certificateInfo`, `timeStampInfo`.

Choices for Maurice, to take in the first of those specs:

- Where `c2patool` and the schema differ, follow the schema: no
  `isUpdateManifest`, `timeStampInfo` with its `timestamp`.
- `specVersion`: `"2.4.0"`, the version this verifier implements, rather
  than `c2patool`'s `"2.3.0"`.
- `algorithm`: the specification's names (`ES256`), not `c2patool`'s
  lower case.
- The comparison: every crJSON this verifier writes is validated against
  the schema in a test, and its codes are compared per manifest with
  `c2patool --crjson`, the known divergences listed by name.
- The API: a separate rendering of the same `VerificationReport`, so the
  existing report and its contract do not change.

## Measured and reasoned

- Measured: the format page and its schema; `c2patool` 0.28.2 with and
  without `--crjson` over 85 files; the schema validation; the
  comparisons above; the conformance tool's submodule commit.
- Reasoned: that the schema is the rule where it and `c2patool` differ;
  that the results can be derived from the existing report; the split
  into three specs.
