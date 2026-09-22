# SPEC-020: The ingredient assertion and the manifest graph

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-22                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A manifest store with more than one manifest is one *active* manifest and
the manifests it carries along: the Content Credentials of the assets it
was made from, its *ingredients*. Each is named by an ingredient assertion
in the manifest that used it — `c2pa.ingredient` (v1, deprecated),
`c2pa.ingredient.v2` (deprecated), `c2pa.ingredient.v3` (C2PA 2.4 §18.16)
— with a `relationship` and, when the ingredient had credentials, a
hashed URI to its manifest box (`c2pa_manifest` in v1/v2, `activeManifest`
in v3) and in v3 a second one to that manifest's signature box
(`claimSignature`). Together the assertions form a graph over the store:
which manifest was used by which, and which manifests nobody names.

This verifier reads none of it. `Manifest` decodes an ingredient assertion
like any other (a CBOR map, its hashed URI checked, SPEC-011) and stops;
a store with more than one manifest is refused before the verdict
(SPEC-013 amendment 5: `general.error`, "until M7"), because a fault in a
manifest never looked at must not yield `Trusted` — the file that showed
it, `adobe-20220124-E-uri-CIE-sig-CA`, is tampered only in its ingredient
manifest. Eighteen corpus files are refused this way (`_MULTI` in
`tests/Pest.php`).

This spec is the first of three for M7 (step 53, §4): it reads the
ingredient assertions and builds the graph, reports what needs no
cryptography — the ingredient without provenance, the malformed
assertion, the reference to a manifest that is not in the store — and
renders the ingredients as c2patool does. It changes no verdict on any
corpus file (the refusal of amendment 5 stays until SPEC-021 validates
the referenced manifests) but it gives the report its second half,
`ingredientDeltas`, which sixteen single-manifest corpus files already
need to equal c2patool's: every one of them carries an ingredient
without a manifest, and c2patool says `ingredient.unknownProvenance` for
it — under `ingredientDeltas`, not under `activeManifest`.

**What goes wrong without it.** Nothing is *wrong* today: the refusal is
fail-closed. What is missing is any statement about the eighteen files,
and the shape of the report that M7's verdicts will land in. Building
that shape on single-manifest files first, where c2patool's JSON can be
matched byte for byte on the codes, means SPEC-021's cryptography lands
in a measured frame.

## Scope

**In scope**

- `Manifest\IngredientAssertion`, a `readonly` value object decoded from an
  assertion whose label is `c2pa.ingredient`, `c2pa.ingredient.v2` or
  `c2pa.ingredient.v3`, with or without a `__N` suffix (SPEC-007's
  labelling rule): `version` (1, 2, 3 from the label), `relationship`
  (`Manifest\Relationship`: `parentOf`, `componentOf`, `inputTo`),
  `title` (`dc:title`), `format` (`dc:format`), `documentId`, `instanceId`,
  `manifest` (the hashed URI to the manifest box — `c2pa_manifest` in
  v1/v2, `activeManifest` in v3 — or null), `claimSignature` (v3, or
  null), `thumbnail` (a hashed URI or null), `validationStatus` (v1/v2:
  the recorded list, as decoded, or null), `validationResults` (v3: the
  recorded map, as decoded, or null), `digitalSourceType`, `metadata`,
  and `data` — the whole decoded map, for the rendering.
- The rules that make an assertion malformed (`assertion.ingredient.malformed`,
  a failure whose url is the assertion's): the data is not a map; the
  label's version is above 3; `relationship` is missing, not a text
  string, or not one of the three values (C2PA 2.4 §15.11.3.2); a v1
  assertion without `dc:title`, `dc:format` or `instanceID`, a v2 without
  `dc:title` or `dc:format` (c2pa-rs `Ingredient::from_assertion`, "only
  the fields required by the CDDL for each version"); a manifest reference
  or `claimSignature` that is not a hashed URI (`url` text, `hash` bytes,
  optional `alg` text — SPEC-011's shape); `activeManifest` next to
  `digitalSourceType` (§18.16.12.3); a v3 assertion with `activeManifest`
  and no `validationResults` (§15.11.3.3, c2pa-rs `ingredient_checks`).
- `Manifest\ManifestGraph`, built from a `ManifestStore`: for every
  manifest, its ingredient assertions in claim order (v1: the `assertions`
  list; v2: `created_assertions` then `gathered_assertions` — the order
  c2patool's `ingredientDeltas` follow); the references resolved by
  manifest label (the label is the URI's first path segment after
  `/c2pa/`); the walk from the active manifest, depth-first, each manifest
  entered once; the results: `referenced` (label → the URIs of the
  assertions that name it), `missing` (references to labels not in the
  store), `unreferenced` (manifests in the store that the walk never
  reaches — "should be ignored", §15.11.3.3), `redactedAssertions` (every
  claim's `redacted_assertions`, collected, for SPEC-021), and the
  statuses the graph alone can state.
- Bounds, this verifier's own: a walk deeper than 32 or a store with more
  than 256 ingredient assertions is `general.error` on the store
  (c2pa-rs allows 200 deep and no count); a cycle — an ingredient whose
  reference leads back to a manifest on the current path — is
  `assertion.ingredient.malformed` on the assertion that closes it
  (c2pa-rs `get_claim_referenced_manifests`, "ingredient cannot be cyclic").
- The statuses: `ingredient.unknownProvenance` (informational, url = the
  assertion's, explanation "<title>: ingredient does not have provenance"
  as c2patool's) for an assertion without a manifest reference whose
  relationship is not `inputTo`; `ingredient.manifest.missing` (failure,
  url = the referenced manifest's **bare label**, as c2patool prints it)
  for a reference to a label not in the store; `assertion.ingredient.malformed`
  as above. Each carries the *scope*: the URI of the ingredient assertion
  it belongs to.
- `Report\ValidationStatus` gains an optional `ingredientUri` (the scope);
  `Report\ValidationResult::toArray()` renders scoped statuses under
  `validation_results.ingredientDeltas[]` as c2patool does —
  `{ingredientAssertionURI, validationDeltas: {success, informational,
  failure}}`, one entry per assertion URI in first-seen order, the key
  present only when there is at least one entry — and unscoped statuses
  under `activeManifest` as today. `validation_status` (the flat list of
  failures) takes active failures first, then every delta's failures in
  order. The three-state rule counts scoped failures: `Valid` needs every
  delta's failures to be `signingCredential.untrusted` only, `Trusted`
  needs none (c2pa-rs `validation_state`); on this spec's statuses that
  means a malformed assertion or a missing manifest is `Invalid`.
- `ManifestStore::toArray()` renders `ingredients` per manifest as
  c2patool does: `title`, `format`, `document_id`, `instance_id`,
  `relationship`, `active_manifest` (the referenced label), `metadata`,
  `thumbnail` (`{format, identifier}` with the URI made absolute against
  the *referring* manifest), `validation_status` (when recorded and
  non-empty), `validation_results` (v3, when recorded), `manifest_data`
  (`{format: "application/c2pa", identifier: <label>}` when referenced),
  `label` (the assertion label) — each key only when the field is
  present; and leaves ingredient and ingredient-thumbnail assertions out
  of the `assertions` list, as c2patool does.
- The Verifier runs the graph on every store after the manifest is read
  and before the checks, adds its statuses, and keeps SPEC-013 amendment
  5's refusal for stores with more than one manifest.

**Out of scope** (each needs its own spec before it may be built)

- Validating a referenced manifest — its box hash, its signature, chain,
  timestamp, assertions — and the deltas that come from that:
  `ingredient.manifest.validated` / `.mismatch`,
  `ingredient.claimSignature.*`, the dropping of statuses the ingredient
  assertion recorded, lifting amendment 5. **SPEC-021.**
- Update manifests (`c2um`): still refused by the JUMBF parser (SPEC-005
  AC13). **SPEC-022.**
- Redactions: `redactedAssertions` is collected, nothing acts on it. An
  ingredient assertion whose content is all `0x00` bytes (a redaction
  placeholder c2pa-rs skips) does not decode as CBOR and is refused as
  it is today (SPEC-007); nothing in this spec changes that.
- The ingredient's `data` hashed URI, `data_types`, `description`,
  `informational_URI`, soft bindings: decoded into `data`, not validated.
- Compressed manifests, remote ingredient manifests, `c2pa.ingredient.v4`
  (refused as "version above 3").

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-020')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The oracle is c2patool 0.27.22's recorded JSON over the corpora
(`tests/Fixtures/c2patool/`, `full.settings.json`): the `ingredients`
rendering and the `ingredientDeltas` of the sixteen single-manifest
files that carry ingredient assertions, and the manifest graph of the
eighteen multi-manifest files as measured in step 53. Variants for the
malformed rules are made in the tests-first step from the PNG fixture's
store (an ingredient assertion added, the claim re-bound and re-signed
with throw-away keys as `bin/make-absence-variants.php` does) and run
through c2patool.

- **AC1 — the three versions decode** *(happy path)*
  - Given `public-testfiles/adobe-20220124-CA.jpg` (v1, no reference),
    `writers/adobe-20260425-lightroom-classic-church.jpg` (v2),
    `writers/adobe-20260304-photoshop-remote-manifest.jpg` (three v3
    without references), and the c2pa-rs `CACA.jpg` active manifest (v3
    with `activeManifest`, `claimSignature` and `validationResults`)
  - When each manifest's ingredient assertions are decoded
  - Then the versions, relationships, titles, formats, instance ids and
    references are the ones c2patool renders (`ingredients[]` in the
    JSON: `relationship`, `title`, `format`, `instance_id`,
    `active_manifest`), the c2pa-rs `CACA` assertion has both hashed URIs
    (url `self#jumbf=/c2pa/urn:c2pa:5259041e-…:contentauth` and
    `…/c2pa.signature`, 32-byte hashes, no `alg`) and a `validationResults`
    map with `activeManifest` and `ingredientDeltas` keys, and the
    Photoshop file's three assertions are `c2pa.ingredient.v3`,
    `c2pa.ingredient.v3__1`, `c2pa.ingredient.v3__2` in that order.

- **AC2 — the malformed rules** *(required: error / malformed input)*
  - Given, as signed variants of the PNG fixture, an ingredient assertion
    (a) without `relationship`, (b) with `relationship: "childOf"`, (c)
    with relationship `42`, (d) labelled `c2pa.ingredient.v4`, (e) v1
    without `dc:title`, (f) v3 with `activeManifest` and no
    `validationResults`, (g) v3 with `activeManifest` next to
    `digitalSourceType`, (h) with `activeManifest` whose `hash` is a text
    string, (i) whose data is a CBOR array
  - When `IngredientAssertion::fromAssertion()` runs
  - Then each throws `ManifestException` with status
    `assertion.ingredient.malformed`, the assertion's url, and a message
    naming the fault (the missing field, the value found, the version);
    and through the Verifier each variant's report is `Invalid` with
    that failure under `ingredientDeltas` for the assertion's URI — the
    same code and url c2patool gives (measured in the tests-first step;
    where c2patool exits with an error and no JSON, the note records it
    and the criterion holds on the code alone).

- **AC3 — unknown provenance, in the deltas, informational**
  - Given the sixteen single-manifest corpus files with ingredient
    assertions (`adobe-20220124-CA`, `-CAI`, `-CI`, `-CII`, `-E-dat-CA`,
    `-E-sig-CA`, `-E-uri-CA`, `-XCA`, `-XCI`; c2pa-rs `CA`, `CA_ct`,
    `E-sig-CA`, `XCA`, `boxhash`, `cloud`; writers Photoshop, Lightroom)
  - When `Verifier::verify()` runs with `full.settings.json`
  - Then `validation_results.ingredientDeltas` has exactly c2patool's
    entries — the same `ingredientAssertionURI`s in the same order, each
    with `ingredient.unknownProvenance` informational, the assertion's
    url, and no success or failure — `validation_state` is unchanged from
    today's (equal to c2patool's), `activeManifest.informational` carries
    no `ingredient.*` code, and `validation_status` is unchanged.

- **AC4 — `inputTo` is not unknown provenance**
  - Given a signed variant with a v3 ingredient without reference and
    `relationship: "inputTo"`
  - When the Verifier runs
  - Then no `ingredient.unknownProvenance` is reported, `ingredientDeltas`
    is absent, the state is `Valid` (c2patool measured in the tests-first
    step).

- **AC5 — the graph of the multi-manifest files**
  - Given the eighteen `_MULTI` files
  - When `ManifestGraph::fromStore()` runs on each `ManifestStore`
  - Then: every graph's root is the last manifest box (the oracle's
    `active_manifest`); `referenced` names exactly the labels c2patool's
    deltas validate (the `active_manifest` of every rendered ingredient
    that has one — 1 in the two-manifest files, 3 in `CACAICAICICA`, 5 in
    `CAIAIIICAICIICAIICICA`, 5 in `exp-test1`, 2 in `CACAE-uri-CA` and
    `ocsp_with_assertion`); `missing` is `[]` for all but the two
    `E-clm-CAICAI` copies, where it is
    `['contentbeef:urn:uuid:8bb8ad50-ef2f-4f75-b709-a0e302d58019']` named by
    `…/c2pa.assertions/c2pa.ingredient__1`; `unreferenced` is `[]` for
    every file; `redactedAssertions` is `[]` for every file; and the
    ingredient assertions come in c2patool's delta order.

- **AC6 — the missing manifest is a failure with the bare label**
  - Given `public-testfiles/adobe-20220124-E-clm-CAICAI.jpg`
  - When the Verifier runs
  - Then, next to the amendment-5 refusal that still applies, the
    statuses hold one `ingredient.manifest.missing` with url
    `contentbeef:urn:uuid:8bb8ad50-ef2f-4f75-b709-a0e302d58019` (the bare
    label, as c2patool's), scoped to
    `self#jumbf=/c2pa/contentauth:urn:uuid:a4ec0a2e-…/c2pa.assertions/c2pa.ingredient__1`,
    and the state is `Invalid`. (c2patool lists the code twice; the drift
    alarms compare unique codes and this verifier reports it once.)

- **AC7 — cycles and bounds** *(malformed input)*
  - Given a store (built in memory from the c2pa-rs `CACA` boxes) whose
    ingredient manifest is given an ingredient assertion referencing the
    active manifest; and a chain of 33 manifests each referencing the
    previous; and a manifest with 257 ingredient assertions
  - When `ManifestGraph::fromStore()` runs
  - Then the first yields `assertion.ingredient.malformed` on the assertion
    that closes the cycle with "cyclic" in the explanation; the second and
    third throw `ManifestException` with `general.error` naming the bound
    (32, 256) — nothing recurses further.

- **AC8 — the rendering: `ingredients` as c2patool prints them**
  - Given the sixteen files of AC3 and the eighteen of AC5
  - When `toArray()` runs on the `ManifestStore`
  - Then for every manifest, `ingredients` equals c2patool's list entry
    for entry on the keys `title`, `format`, `document_id`,
    `instance_id`, `relationship`, `active_manifest`, `label`,
    `manifest_data`, `thumbnail`, `metadata`, and `validation_status`
    (present exactly when c2patool prints it, equal in code, url and
    explanation); `validation_results` is present exactly when c2patool
    prints it (its content compared by its two top-level keys); and the
    `assertions` list no longer holds `c2pa.ingredient*` or
    `c2pa.thumbnail.ingredient.*` labels — equal to c2patool's label list
    on every file where the only difference today is those two.

- **AC9 — the report shape** *(B change, measured on the flat list)*
  - Given `ValidationResult` built from statuses with and without
    `ingredientUri`
  - When `toArray()` runs
  - Then unscoped statuses land under `validation_results.activeManifest`,
    scoped ones under `ingredientDeltas` grouped by URI in first-seen
    order with the three kinds separated, `ingredientDeltas` is absent
    when no status is scoped; `validation_status` = active failures then
    delta failures; the state is `Invalid` when any scoped status is a
    failure other than `signingCredential.untrusted`, and `Trusted` needs
    no scoped failure at all. On every single-manifest corpus file the
    report is byte-identical to today's except for the `ingredientDeltas`
    key and the `ingredients` rendering (SPEC-019 AC11 and SPEC-013's
    alarms stay green).

- **AC10 — the corpus verdicts are unchanged** *(the drift alarm)*
  - Given the four corpora and every own variant
  - When the alarms run
  - Then no `validation_state` changes and no failure code appears or
    disappears; the eighteen `_MULTI` files are still `Invalid` with
    the amendment-5 `general.error`.

## References

- Specification: C2PA 2.4 §18.16 (the ingredient assertion, its three
  versions and fields; §18.16.3 relationships; §18.16.12.3 the two hashed
  URIs; §18.16.12.4 the recorded `validationStatus` / `validationResults`),
  §15.11.3.2 (`assertion.ingredient.malformed`), §15.11.3.3 (the
  recursive walk, `ingredient.unknownProvenance` unless `inputTo`,
  ignoring unreferenced manifests), §15.11.3.3.2 (`ingredient.manifest.missing`),
  §15.2.2 (the codes). Read 2026-09-22 (step 53).
- Oracle: c2patool 0.27.22, the recorded JSON under
  `tests/Fixtures/c2patool/{public-testfiles,c2pa-rs,writers}/`
  (`--settings full.settings.json`), the sixteen single-manifest files
  with ingredients and the eighteen multi-manifest files; step 53's
  `m7-measure.php` (the graph, the references, the active manifest) and
  its oracle summary (delta URIs and codes).
- Reasoned (from c2pa-rs 0.90.22 `assertions/ingredient.rs`
  `from_assertion`, `store.rs` `ingredient_checks` and
  `get_claim_referenced_manifests`, `validation_results.rs` `add_status`
  and `validation_state`): which fields each version requires; that
  statuses are scoped by the ingredient assertion's URI and grouped into
  `ingredientDeltas` in first-seen order; that `ingredient.manifest.missing`
  carries the bare label; that cycles are `assertion.ingredient.malformed`;
  that c2pa-rs does not check `digitalSourceType` against `activeManifest`
  (the specification does; this verifier follows the specification —
  stricter, named). The bounds 32 / 256 are this verifier's own.

## API sketch

Illustrative only — not binding implementation.

```php
// namespace Provemark\C2paVerifier\Manifest;

enum Relationship: string { case ParentOf = 'parentOf'; case ComponentOf = 'componentOf'; case InputTo = 'inputTo'; }

final readonly class IngredientAssertion
{
    public function __construct(
        public string $label,            // c2pa.ingredient.v3__1
        public string $url,              // self#jumbf=/c2pa/<manifest>/c2pa.assertions/<label>
        public int $version,             // 1 | 2 | 3
        public Relationship $relationship,
        public ?string $title,
        public ?string $format,
        public ?string $documentId,
        public ?string $instanceId,
        public ?HashedUri $manifest,     // c2pa_manifest (v1/v2) | activeManifest (v3)
        public ?HashedUri $claimSignature,
        public ?HashedUri $thumbnail,
        public ?array $validationStatus,   // v1/v2, as decoded
        public ?array $validationResults,  // v3, as decoded
        public ?string $digitalSourceType,
        public array $data,              // the whole map, plain
    ) {}

    /** @throws ManifestException  assertion.ingredient.malformed, url = the assertion's */
    public static function fromAssertion(string $manifestLabel, Assertion $assertion): self;

    public function manifestLabel(): ?string;   // the referenced label, from the URI
    public static function isIngredientLabel(string $label): bool;
}

final readonly class ManifestGraph
{
    public const MAX_DEPTH = 32;
    public const MAX_ASSERTIONS = 256;

    /** @param array<string, list<IngredientAssertion>> $ingredients  per manifest label, claim order */
    public function __construct(
        public string $active,
        public array $ingredients,
        public array $referenced,          // label => list<assertion url>
        public array $missing,             // list<array{label: string, by: string}>
        public array $unreferenced,        // list<label>
        public array $redactedAssertions,  // list<string>
        public array $statuses,            // list<ValidationStatus>, each with ingredientUri
    ) {}

    /** @throws ManifestException  general.error on the bounds */
    public static function fromStore(ManifestStore $store): self;
}

// namespace Provemark\C2paVerifier\Report;
final readonly class ValidationStatus
{
    public function __construct(
        public StatusCode $code,
        public string $url,
        public string $explanation,
        public ?string $ingredientUri = null,   // SPEC-020: the scope
    ) {}
}
```

`StatusCode` gains `ingredient.unknownProvenance` (informational),
`ingredient.manifest.missing` and `assertion.ingredient.malformed`
(failures). `ManifestStore::toArray()` renders `ingredients`; the
Verifier calls `ManifestGraph::fromStore()` between the manifest read and
the checks and merges its statuses. The `Manifest` layer gains no
dependency; `Report` is unchanged in its dependencies.

## Open questions

- **Where `ingredient.manifest.missing` sits in c2patool's JSON.** c2patool
  lists it under `activeManifest.failure` *and* under the delta, and twice
  in `validation_status` (logged once outside and once inside the
  ingredient scope). This verifier reports it once, scoped; the alarms
  compare unique codes. *Non-blocker; named in `docs/comparison.md`.*
- **The `validationResults` map of a v3 assertion.** Decoded and carried
  as data; whether it is validated for shape (a `validation-results-map`,
  §18.16.12.4.3) belongs to SPEC-021, which consumes it. *Non-blocker.*
- **A store whose active manifest has no ingredient assertion but holds a
  second manifest** (`unreferenced` non-empty): the specification says
  ignore; amendment 5 refuses; SPEC-021 decides. *Non-blocker.*

## Amendments

1. **2026-09-22, step 54b, measured** — the two corpus files that declare
   their manifest by URL (`c2pa-rs/cloud`, the Photoshop file) carry **no
   manifest store**; c2patool's JSON for them describes a manifest it
   fetched over the network, so there is nothing in the file to compare.
   AC3's list is fifteen files, not sixteen, and AC1's v3 examples come
   from `c2pa-rs/CACA` (a v3 with both hashed URIs in the active manifest,
   a v3 without a reference in its ingredient manifest) and `adobe-20220124-CAI`
   (two v1 assertions in claim order) instead of the Photoshop file. No
   rule of this spec changed.
2. **2026-09-22, step 54b, measured** — three literals of the approved
   text were wrong where the files disagree: (a) c2pa-rs's v3
   `activeManifest` **does** carry `alg: sha256` (AC1 said no `alg`); (b)
   the two `E-clm-CAICAI` copies keep one manifest the walk never reaches
   — their reference names `contentbeef:…` — so `unreferenced` is not
   empty for them (AC5); (c) c2patool renders `active_manifest` for such
   a reference but **not** `manifest_data`, and an ingredient's
   `thumbnail` identifier is printed where the thumbnail lives — in the
   ingredient's own manifest when the assertion's URI is absolute, in the
   referring manifest when it is relative (AC8). The rendering follows
   the files.
3. **2026-09-22, step 54b, measured** — AC5's "the ingredient assertions
   come in c2patool's delta order" holds as a *subsequence*: c2patool
   drops a status an ingredient assertion already recorded, and an
   assertion whose every status was dropped leaves no delta at all
   (`c2pa-rs ValidationResults::from_store`; the dropping is SPEC-021).
   The walk is compared to the deltas as a subsequence, and where no
   assertion recorded anything the two are equal. The walk itself is a
   public field (`ManifestGraph::$walk`), which the API sketch did not
   name.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Manifest/IngredientAssertionTest.php :: AC1 (three tests) / SPEC-020 | src/Manifest/IngredientAssertion.php; src/Manifest/Relationship.php |
| AC2 | tests/Unit/Manifest/IngredientAssertionTest.php :: AC2 (two tests) / SPEC-020 | src/Manifest/IngredientAssertion.php (`fromAssertion()`) |
| AC3 | tests/Unit/Verifier/IngredientDeltasTest.php :: AC3 / SPEC-020 | src/Manifest/ManifestGraph.php (`descend()`); src/Report/ValidationResult.php |
| AC4 | tests/Unit/Manifest/IngredientAssertionTest.php :: AC4 / SPEC-020 | src/Manifest/ManifestGraph.php (the `inputTo` rule) |
| AC5 | tests/Unit/Manifest/ManifestGraphTest.php :: AC5 / SPEC-020 | src/Manifest/ManifestGraph.php (`fromStore()`, `fromIngredients()`, `assertionLabels()`) |
| AC6 | tests/Unit/Verifier/IngredientDeltasTest.php :: AC6 / SPEC-020 | src/Manifest/ManifestGraph.php; src/Verifier/Verifier.php |
| AC7 | tests/Unit/Manifest/ManifestGraphTest.php :: AC7 (three tests, the third added in step 65b: a cycle stops that branch, not the walk) / SPEC-020 | src/Manifest/ManifestGraph.php (`MAX_DEPTH`, `MAX_ASSERTIONS`, the cycle rule) |
| AC8 | tests/Unit/Verifier/IngredientDeltasTest.php :: AC8 / SPEC-020 | src/Manifest/ManifestStore.php (`ingredientsArray()`, `manifestArray()`) |
| AC9 | tests/Unit/Verifier/IngredientDeltasTest.php :: AC9 / SPEC-020 | src/Report/ValidationStatus.php (`$ingredientUri`); src/Report/ValidationResult.php (`toArray()`) |
| AC10 | tests/Unit/Verifier/VerifierTest.php :: SPEC-013 AC10–AC13; tests/Unit/Cli/CommandTest.php :: AC11 / SPEC-019 | src/Verifier/Verifier.php |

`src/Manifest/{Relationship,IngredientAssertion,ManifestGraph}.php` map to this spec; `StatusCode`'s three new cases and `ValidationStatus::$ingredientUri` are its. Measured 2026-09-22: 13 red → 13 green, `composer check` exit 0, 327 tests.
