# SPEC-022: Update manifests

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-22                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Sometimes a manifest is added to an asset **without touching a byte of the
content**: a later owner records that the file was published, or opens it
and adds metadata, or redacts an assertion of the manifest before it.
C2PA 2.4 §11.2.3 gives that its own box type — an *update manifest*, JUMBF
UUID `c2um` — and its own rules: no hard binding (the content did not
change, so the old binding still holds), no thumbnail, actions only from
`c2pa.edited.metadata`, `c2pa.opened`, `c2pa.published`, `c2pa.redacted`,
and exactly one ingredient, `parentOf`, naming the manifest it updates.

This verifier refuses the box outright: `JumbfParser` throws "update
manifests (c2um) are not supported" (SPEC-005 AC13). It is the last thing
standing between M7 and a verifier that reads every multi-manifest file in
the corpora — `c2pa-rs/update_manifest.jpg` is the one file SPEC-021 could
not measure, and the one shape in which a real workflow (a publisher
stamping an asset it did not edit) reaches a reader.

Two consequences make this more than "read one more box type":

1. **The hard binding is not in the active manifest.** §15.12: when the
   active manifest is an update manifest, the binding is found by
   following the `parentOf` chain to the first standard manifest; if none
   is found, or it has no hard binding, the claim is rejected with
   `claim.hardBindings.missing`. c2patool reports that binding's
   `assertion.dataHash.match` under the **active** manifest, not in a
   delta — measured on the fixture.
2. **The exclusion in that binding is stale.** The parent wrote it when
   the store was shorter; appending the update manifest grew the store.
   Measured on `update_manifest.jpg`: the parent's `c2pa.hash.data`
   excludes `{start: 9964, length: 18874}` while the store now occupies
   `{start: 9964, length: 43607}`. §15.12.1.1 says so in as many words —
   the exclusion that starts where the store starts "shall be treated as
   the current length of the entire C2PA Manifest Store", and every later
   exclusion's start shifts by the difference. Without that rule this
   verifier's *cover* check (SPEC-012 amendment 5) refuses the file, and
   with a naive fix it would hash the wrong bytes.

**What goes wrong without it.** One corpus file stays refused, and any
file a publisher stamps this way reads as `Invalid` — a refusal where
c2patool says `Trusted`. The risk of getting it wrong is the mirror
image: an exclusion adjusted too generously would let an attacker hide
bytes *outside* the manifest store from the hash, which is exactly the
hole the data hash exists to close.

**Measured before writing this spec** (2026-09-22, c2patool 0.27.22 on
`tests/Fixtures/c2pa-rs/update_manifest.jpg`, and the store read by hand
since the parser refuses it):

- The store holds two manifests: `c2ma` `adobe:urn:uuid:6a6b4dbe…` (the
  parent, claim v1, seven assertions including `c2pa.hash.data` and two
  thumbnails) and `c2um` `urn:c2pa:d9702464…:update_manifest_vendor` (the
  active one, claim **v2**, two assertions: `c2pa.actions.v2` and
  `c2pa.time-stamp`, plus one `c2pa.ingredient.v3`).
- The update manifest's actions are `c2pa.opened` and
  `c2pa.edited.metadata` — both on §11.2.3's list; its ingredient is
  `parentOf` with `activeManifest`, `claimSignature` and a full
  `validationResults`.
- c2patool: `Trusted`; the active manifest's successes are
  `signingCredential.trusted`, `claimSignature.insideValidity`,
  `claimSignature.validated`, three `assertion.hashedURI.match` and
  **`assertion.dataHash.match`**; the one delta holds
  `ingredient.manifest.validated` with url
  `self#jumbf=/c2pa/adobe:urn:uuid:6a6b4dbe…/c2pa.claim` — the
  reference's url verbatim, as SPEC-021 already renders it. Everything
  else about the parent was recorded in the ingredient's
  `validationResults` and dropped.
- The parent's claim carries `claim_generator_info: []` — an **empty
  list**, which this verifier's claim reader refuses today ("not a
  non-empty list of maps"); c2patool reads it as none, exactly as it
  reads the `null` of SPEC-007 amendment 4.
- c2pa-rs 0.90.22 (`claim.rs`, `ALLOWED_UPDATE_MANIFEST_ACTIONS` and
  `verify_internal`): the four rules above with
  `manifest.update.invalid`, and `manifest.update.wrongParents` when
  there is no `parentOf` ingredient; for a *standard* manifest more than
  one `parentOf` is `manifest.multipleParents`.

## Scope

**In scope**

- `Jumbf\JumbfParser` reads a `c2um` superbox as a manifest, exactly as
  `c2ma` (SPEC-005 AC13 amended for `c2um` only). `c2cm` (Brotli) and
  `c2tm` (the deprecated time-stamp manifest, §11.2.5 — "not to be …
  read by manifest consumers") stay refused, each with its own message.
- `Manifest\Manifest::$isUpdateManifest` — true when the box's UUID is
  `c2um`; `ManifestStore` keeps them like any other manifest, and the
  active manifest is still the last in the store.
- `Manifest\UpdateManifestCheck` (name illustrative), run on **every**
  manifest in the store, each rule from C2PA 2.4 §11.2.3 with c2pa-rs's
  code:
  - an update manifest with any `c2pa.hash.*` assertion →
    `manifest.update.invalid`;
  - with a thumbnail assertion (`c2pa.thumbnail.claim*`) →
    `manifest.update.invalid`;
  - with an action outside {`c2pa.edited.metadata`, `c2pa.opened`,
    `c2pa.published`, `c2pa.redacted`} → `manifest.update.invalid`;
  - with no `parentOf` ingredient → `manifest.update.wrongParents`;
    with more than one ingredient of any relationship →
    `manifest.update.invalid`;
  - a **standard** manifest with more than one `parentOf` ingredient →
    `manifest.multipleParents` (§15.11, c2pa-rs `verify_internal`).
  The url is the claim's (`self#jumbf=/c2pa/<label>/c2pa.claim[.v2]`), as
  c2pa-rs logs it; a status for a manifest that is not the active one is
  scoped to the ingredient assertion that named it (SPEC-021's rule).
- **The binding manifest** (§15.12): the active manifest when it is not
  an update manifest and has a `c2pa.hash.data`; otherwise the first
  manifest reached by following `parentOf` references (through update
  manifests) that is a standard manifest with a hard binding. None found
  → `claim.hardBindings.missing` on the active manifest's claim, as
  today. The data hash then runs on **that** manifest's assertion against
  this file's bytes, and its status is reported unscoped — under the
  active manifest, as c2patool does.
- **The exclusion adjustment** (§15.12.1.1), applied only when the store
  holds at least one update manifest: the exclusion whose `start` equals
  the manifest store's start in this file is replaced by the store's
  current range (start and length as `ManifestStoreBytes::$ranges`
  reports them), and every exclusion whose `start` is greater than that
  start is moved by the difference between the new and the old length.
  Exclusions that start before the store are untouched. The adjustment
  is computed, never trusted: the *cover* rule of SPEC-012 amendment 5
  still applies afterwards, so an exclusion that does not cover the store
  is still `assertion.dataHash.mismatch`.
- `Report\StatusCode` gains `manifest.update.invalid`,
  `manifest.update.wrongParents` and `manifest.multipleParents` (all
  failures).
- `Manifest\Claim`: a `claim_generator_info` that is an **empty list**
  counts as absent, as `null` does (SPEC-007 amendment 4 extended) —
  measured on this fixture's parent.
- The exception lists in `tests/Pest.php` lose `update_manifest`.

**Out of scope** (each needs its own spec before it may be built)

- The `c2pa.time-stamp` assertion (§11.2.3's replacement for time-stamp
  manifests): decoded like any assertion, its hashed URI checked, its
  content not read. c2patool reports no `timeStamp.*` status for the
  fixture's assertion either; SPEC-017 reads `sigTst`/`sigTst2` from the
  COSE header only.
- Redactions (`c2pa.redacted` as an *action* is allowed by §11.2.3 and
  read like any other action; a claim with `redacted_assertions` is
  still refused — SPEC-021).
- Compressed manifests (`c2cm`, Brotli) and time-stamp manifests
  (`c2tm`).
- Writing or updating manifests: this is a verifier.
- An update manifest whose parent chain leaves this file (a remote
  manifest): the chain is followed in the store only; a reference that
  is not in the store is `ingredient.manifest.missing` (SPEC-020).

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-022')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The oracle is c2patool 0.27.22 on `tests/Fixtures/c2pa-rs/update_manifest.jpg`
(`--settings full.settings.json`, recorded in
`tests/Fixtures/c2patool/c2pa-rs/update_manifest.json`) and on the signed
variants made in the tests-first step.

- **AC1 — the fixture reads, and its verdict is c2patool's** *(happy path)*
  - Given `c2pa-rs/update_manifest.jpg`
  - When `Verifier::verify()` runs with `full.settings.json`
  - Then the report is `Trusted`; the store holds two manifests and the
    active one is the `c2um` box `urn:c2pa:d9702464…:update_manifest_vendor`
    with `claim_version` 2; `checks_performed` is
    `['timestamp'?, 'signature', 'certificate', 'trust', 'hashedUris',
    'actions', 'ingredients', 'dataHash']`; the failure codes equal
    c2patool's (none); the one delta holds `ingredient.manifest.validated`
    with url `self#jumbf=/c2pa/adobe:urn:uuid:6a6b4dbe…/c2pa.claim`.

- **AC2 — the hard binding comes from the parent, and is reported unscoped**
  - Given the same file
  - When the Verifier runs
  - Then exactly one `assertion.dataHash.match` is reported, its
    `ingredientUri` is null (the active manifest's own line, as
    c2patool), and its url names the **parent** manifest's
    `c2pa.hash.data`; the active manifest has no hash assertion of its
    own, and no `claim.hardBindings.missing` is reported.

- **AC3 — the stale exclusion is adjusted, and the hash still covers the store** *(the rule that could hide bytes)*
  - Given the same file, whose parent excludes `{start: 9964, length:
    18874}` while the store now occupies `{start: 9964, length: 43607}`
  - When the data hash is checked
  - Then the exclusion used is the store's current range, the hash
    matches, and the *cover* rule of SPEC-012 is applied to the adjusted
    exclusion — measured through the check's seam: with the adjustment
    the assertion's exclusion covers the store; without it, it does not.
  - And given a variant of the file with one byte changed **outside** the
    manifest store (a pixel byte after it)
  - Then the report is `Invalid` with `assertion.dataHash.mismatch`: the
    adjustment widens the exclusion to the store, never beyond it.

- **AC4 — an update manifest that breaks §11.2.3** *(required: error / malformed input)*
  - Given signed variants of the fixture: (a) an action `c2pa.edited`
    added to the update manifest's actions; (b) a `c2pa.hash.data`
    assertion added to it; (c) its ingredient's relationship changed to
    `componentOf`; (d) a second ingredient assertion added
  - When the Verifier runs
  - Then (a) and (b) report `manifest.update.invalid`, (c) reports
    `manifest.update.wrongParents`, (d) reports
    `manifest.update.invalid`; each with the claim's url, each `Invalid`,
    and each equal in code to c2patool's answer where c2patool produces a
    report (recorded in the tests-first step; where it exits without
    JSON, the criterion holds on this verifier's code and the note says
    so).

- **AC5 — no parent with a hard binding** *(error path)*
  - Given a signed variant in which the update manifest's ingredient
    names a manifest that is itself an update manifest with no further
    parent (a chain that never reaches a standard manifest)
  - When the Verifier runs
  - Then the report is `Invalid` with `claim.hardBindings.missing` on the
    active manifest's claim, and no data hash is checked.

- **AC6 — a standard manifest with two parents**
  - Given a signed variant of the PNG fixture with two `parentOf`
    ingredient assertions
  - When the Verifier runs
  - Then `manifest.multipleParents` is reported once, with the claim's
    url, and the report is `Invalid`.

- **AC7 — `c2cm` and `c2tm` stay refused**
  - Given the PNG fixture's store with the manifest box's UUID changed to
    `c2cm`, and the same with `c2tm`
  - When the store is parsed
  - Then each throws `JumbfException` naming the box type and saying it
    is not supported — the `c2um` change made in this spec is the only
    one (SPEC-005 AC13 amended for `c2um` only).

- **AC8 — an empty `claim_generator_info` counts as absent**
  - Given the fixture's parent claim (`claim_generator_info: []`) and the
    `ocsp.jpg` claim (`null`, SPEC-007 amendment 4)
  - When each manifest is read
  - Then both give `claimGeneratorInfo === null` and no exception, and
    the store's JSON renders as c2patool's does for those files.

- **AC9 — the corpora are unchanged, and one file joins them**
  - Given the four corpora, the own variants, the CLI's criterion and
    `bin/fuzz.php`
  - When the alarms run
  - Then no verdict changes except `c2pa-rs/update_manifest`, which moves
    from refused (`general.error`, a `c2um` box) to c2patool's
    `Trusted`; `update_manifest` leaves `SPEC013_RS_MULTI` and
    `SPEC013_NOT_YET`; no exception escapes the fuzzer.

## References

- Specification: C2PA 2.4 §11.2.3 (update manifests: the box type, the
  four allowed actions, no hash assertion, no thumbnail, exactly one
  `parentOf` ingredient), §11.2.4–§11.2.5 (compressed and time-stamp
  manifests — out of scope), §15.11.2.2 (an update manifest's `parentOf`
  ingredient is validated), §15.12 (the binding is found through the
  `parentOf` chain; none → `claim.hardBindings.missing`), §15.12.1.1 (the
  exclusion treated as the store's current length and the shift of later
  exclusions), §15.2.2 (`manifest.update.invalid`,
  `manifest.update.wrongParents`, `manifest.multipleParents`). Read
  2026-09-22.
- Oracle: c2patool 0.27.22 on `tests/Fixtures/c2pa-rs/update_manifest.jpg`
  with `full.settings.json` (the recorded JSON: `Trusted`, the successes
  listed under Problem, the single delta); the store's two boxes, the
  update manifest's assertions and actions, the parent's exclusion
  `{9964, 18874}` against the store's range `{9964, 43607}`, and the
  parent's `claim_generator_info: []` — all read by hand on 2026-09-22,
  since the parser refuses the box.
- Reasoned (from c2pa-rs 0.90.22 `claim.rs` `verify_internal`,
  `ALLOWED_UPDATE_MANIFEST_ACTIONS`, and `store.rs`
  `get_hash_binding_manifest`): which code each broken rule gets; that
  the adjustment runs only when the store holds an update manifest; that
  the binding claim is found by walking `parentOf` through update
  manifests until a standard manifest with a hash assertion.

## API sketch

Illustrative only — not binding implementation.

```php
// namespace Provemark\C2paVerifier\Jumbf;
// JumbfParser::UUID_UPDATE_MANIFEST is read like UUID_MANIFEST; c2cm and c2tm still throw.

// namespace Provemark\C2paVerifier\Manifest;

final readonly class Manifest
{
    public bool $isUpdateManifest;   // the box UUID was c2um
    // …
}

final readonly class UpdateManifestCheck
{
    public const array ALLOWED_ACTIONS = ['c2pa.edited.metadata', 'c2pa.opened', 'c2pa.published', 'c2pa.redacted'];

    /**
     * §11.2.3 for every manifest in the store, plus §15.11's one-parent rule for standard manifests.
     *
     * @param  array<string, list<IngredientAssertion>>  $ingredients  the graph's, per manifest label
     * @return list<ValidationStatus>
     */
    public function check(ManifestStore $store, array $ingredients): array;

    /**
     * The manifest whose hard binding covers the asset (§15.12): the active one, or the first
     * standard manifest with a c2pa.hash.data up the parentOf chain, or null.
     */
    public static function bindingManifest(ManifestStore $store, ManifestGraph $graph): ?Manifest;
}

// namespace Provemark\C2paVerifier\Hash;
final readonly class DataHashCheck
{
    /**
     * @param  array{start: int, length: int}|null  $storeRange  when the store holds an update manifest,
     *   the exclusion that starts where the store starts is treated as this range and later exclusions
     *   shift by the difference (C2PA 2.4 §15.12.1.1)
     */
    public function check(Manifest $manifest, $stream, ManifestStoreBytes $store, ?array $storeRange = null): array;
}
```

`StatusCode` gains the three codes. The Verifier asks the graph for the
binding manifest, runs `DataHashCheck` on it with the store's range when
an update manifest is present, and runs `UpdateManifestCheck` beside the
actions check.

## Open questions

- **Where the §11.2.3 rules run for a non-active manifest.** An update
  manifest can itself be an ingredient. The rules are per manifest, so
  the check walks the store and scopes a status for a non-active manifest
  to the assertion that named it (SPEC-021's rule). *Non-blocker.*
- **`checks_performed` for the update case.** `dataHash` is still the
  name, though the assertion checked belongs to another manifest; the
  report's url says which. A second name would be a second truth.
  *Decided.*
- **An update manifest as the *only* manifest.** Then the `parentOf`
  chain reaches nothing and AC5's `claim.hardBindings.missing` applies —
  which is also what §15.12 says. No corpus file has one; the variant of
  AC5 makes it. *Decided.*
- **The `c2pa.time-stamp` assertion.** Out of scope here; if a later spec
  reads it, it will need SPEC-016's token reader and a fixture whose
  timestamp is *only* in the assertion. *Non-blocker.*

## Amendments

(none yet)

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
