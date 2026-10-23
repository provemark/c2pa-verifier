# Step 375 — SPEC-075: the crJSON document, built

*2026-10-23.* Approved with both open questions as proposed (v1 claims
written faithfully; `manifests: []` for a file without a store), and
ADR-0006 accepted. Built tests-first; one amendment on the way, approved.

## What changed

- `VerificationReport::toCrJson()` and `bin/c2pa-verify --crjson`: the
  report as a crJSON document (C2PA 2.4 crJSON §3), built by
  `Verifier\CrJsonDocument`. Every manifest, active first; the claim and
  the CBOR assertions by SPEC-074, JSON assertions as they are, embedded
  files as `{format, identifier, hash}`; each manifest's own results and
  deltas; `specVersion` `2.4.0`; `validationTime`. The signature is `{}`
  until SPEC-076.
- `VerificationReport::$validationTime`: the time the report judged at,
  SPEC-073's or the clock when `verify()` started.
- `Verifier::VERSION` = `0.8.0-dev`.
- `opis/json-schema` `^2.6` in `require-dev` only (ADR-0006); the schema
  copied under `tests/Fixtures/crjson/` with its source, date, SHA-256
  and licence.
- Three SPEC-074 helpers moved to `tests/Shared.php`, now that a second
  file uses them.

## Measured

- The validator first: on `c2patool`'s own 59 answers `opis/json-schema`
  finds what Python `jsonschema` found in step 370 (all invalid as
  written; 52 valid without the two extra keys; then 6 missing
  `timestamp`s and 1 v1 `metadata` array).
- Red: 14 of 15 (the fifteenth is that validator check).
- Amendment 1, from the green run: five named ways in which `c2pa-rs`'s
  claims differ from faithful ones (absolute `signature` URIs in v1 and
  v2; absolute v1 `assertions` URIs; a made-up `claim_generator_info`
  where the claim's is absent, null or empty; `redacted_assertions: []`
  for v2 only; v1 fields the schema does not name left out), and every
  schema error inside a v1 claim accepted (49 missing
  `claim_generator_info`, 2 null, 1 empty, 1 `metadata` array).
- Green: 15 of 15. Mutations, each failing: every delta on the active
  manifest; store order not reversed; the clock instead of the report's
  time; the embedded hash left out.
- The ordinary report over the corpus before and after: 0 of 1,842 runs
  moved.
- The contract: 136 → 139 symbols. `composer check`: 1,182 passed, 1
  skipped.

## Next

SPEC-076: the signature (`algorithm`, `certificateInfo`,
`timeStampInfo`), after which the whole document must pass the schema.
