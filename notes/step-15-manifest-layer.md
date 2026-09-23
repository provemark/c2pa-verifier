# Step 15 — The Manifest layer (SPEC-007 implemented); M2 complete

*2026-09-21. Oracle: c2patool 0.27.22's JSON (step 14), the sister
library `provemark/content-credentials` v0.15.1, the values of steps
09/12/14.*

## What was built

`src/Manifest/`, the layer where boxes and CBOR become a claim:

- `ManifestStore::fromTree(Superbox)` — every `c2ma` child of the root is
  a manifest, the last is active; none is an error. `toArray()` /
  `toJson()` give c2patool's shape restricted to what M2 knows.
- `Manifest::fromBox(Superbox)` — exactly one assertion store (UUID and
  label), one claim box, one signature box, each of the last two with
  exactly one `cbor` content box; the claim's version from the box label;
  the claim decoded and typed; every assertion decoded by the kind of
  content box it holds (`cbor` → SPEC-006, `json` → `json_decode`,
  `bfdb`+`bidb` → `EmbeddedFile`, `uuid` → bytes); then every reference
  in the claim resolved — the signature URI must name the signature box,
  every assertion URI must land on a superbox inside the assertion store.
  `resolve()` handles both measured URI forms: absolute from the store
  root (`/c2pa/<manifest>/…`, refused for another manifest's label until
  M7) and relative to the manifest; a segment that names an `UnknownBox`
  is its own error, with the UUID.
- `Claim::fromMap(version, map)` — the required fields per the CDDL of
  §10.2.1 (v2: `instanceID`, `claim_generator_info`, `signature`,
  `created_assertions`; v1: `claim_generator`, `signature`, `assertions`,
  `dc:format`, `instanceID`), the generator info always as a list of maps
  each with a `name`, hashed URIs typed (`hash` must be a `CborBytes`),
  the rest kept in `other`.
- `Assertion`, `HashedUri`, `EmbeddedFile`, `ManifestException`.

## M2's "done when", measured

AC6: for each of the four fixtures, our `toJson()` and c2patool's
recorded JSON both go through the sister library's
`ManifestStoreParser::fromJson()`, and `hasManifest()`,
`isAiGenerated()`, `digitalSourceTypes()`, `softwareAgents()` and
`declaredSpecVersion()` are equal — the accessors that depend only on
the store's content. The crypto accessors (`isSignatureValid`,
`isTrusted`, `validationState`, `hasTimestamp`) join as M3–M6 fill
`signature_info` and `validation_*`. `docs/milestones.md` marks M2 done.

Three choices in the JSON view, each measured against c2patool's output
(step 14): `claim_generator_info` always a list; `assertions` without the
hard-binding assertion and the thumbnail (the thumbnail as its own field
with an absolute identifier); labels as stored — c2patool renders v1's
`c2pa.actions` as `c2pa.actions.v2`, the sister parser matches on the
prefix, and AC6 shows the accessors agree.

## Measured

- Red: 14 tests on the missing classes (`d7551c9`).
- First run of the layer: **14 passed** — AC6 included.
- PHPStan: three findings — a comparison it could prove always false
  (`gathered_assertions` guarded by version where the schema already
  decides it; the guard removed), and two unnarrowed offsets in the test
  (`assert`s added); then one more on a narrowing branch, restructured.
  `composer check` → exit 0: spec-check `OK: 8 spec(s), 8 test file(s)`,
  Pint passed, PHPStan `No errors`, Deptrac 0 violations (`Manifest` sees
  `Jumbf`, `Cbor`, `Support`), Pest **120 passed (632 assertions)**.

## What M2 now is

From a file to a claim: `Container` (three extractors and one reader) →
`Jumbf` (the tree) → `Cbor` (the values) → `Manifest` (the meaning), 120
tests, seven specs, 74 fixtures with c2patool's verdict on each, and one
external contract satisfied. Nothing yet says whether any of it is
*true*: M3 (the signature over `claimBytes()`, from `signatureBytes()`),
M4 (the hashed URIs against `Assertion::$box->payload()`, the hash
binding against the file), M5 (the chain), M6 (the timestamp).

## Reasoned, not measured

- A `CborTag` inside assertion data is rendered as its content in the
  JSON view; no fixture carries one there.
- An embedded file that is not a thumbnail (an icon, say) is rendered
  with its bytes base64; no fixture carries one.
- Cross-manifest URIs are refused with a message naming M7; the
  ingredient fixtures of `public-testfiles` will exercise them.
