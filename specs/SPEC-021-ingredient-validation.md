# SPEC-021: Validating the ingredient manifests

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

SPEC-020 reads the ingredient assertions and walks the graph, but the
manifests it finds are still never looked at: a store with more than one
manifest is refused with `general.error` ("ingredient manifests are not
validated before M7"), because a fault in a manifest nobody examined must
not yield `Trusted` — `adobe-20220124-E-uri-CIE-sig-CA`, tampered only in
its ingredient, was `Trusted` here before SPEC-013 amendment 5 refused the
whole class. Eighteen corpus files are refused this way, and with them
every real editing history: a photograph opened in Lightroom and placed in
a composite carries two or three manifests, and this verifier says nothing
about any of them.

This spec validates them. For each manifest the graph reached: the hash
the referring assertion recorded over its box (C2PA 2.4 §15.11.3.3.2), and
then the manifest itself — its signature, certificate profile, chain and
trust, its timestamp, its hashed URIs, its actions — everything the active
manifest gets except the hard binding, which an ingredient cannot have
checked because its asset's bytes are not in this file (§15.11.3.3.1:
"content bindings are not evaluated"). The statuses are scoped to the
ingredient assertion that named the manifest, so they render in
`validation_results.ingredientDeltas` where SPEC-020 put the graph's.

One rule of the specification decides how this reads: an ingredient
assertion records what its writer found when it used the ingredient
(`validationStatus` in v1/v2, `validationResults` in v3, §18.16.12.4), and
a *failure* recorded there "is considered an explicit statement by the
claim generator that an actor has acknowledged validation errors in the
ingredient's C2PA Claim itself and has chosen to proceed". c2pa-rs
implements that by dropping, from the report, every status an ingredient
assertion already recorded — which is why `adobe-20220124-CIE-sig-CA`, an
ingredient whose *signature is genuinely broken*, is `Trusted` at
c2patool. The maintainer decided on 2026-09-22 (step 53 §4.2) to copy that
exactly, with c2pa-rs's guard against the obvious abuse: a status about
the **active** manifest is never dropped, so an attacker-authored
ingredient assertion cannot cancel a failure in the manifest that is
actually being verified (CAI-12751).

**What goes wrong without it.** Every multi-manifest file is `Invalid`,
which is a refusal, not a verdict — the caller cannot tell "we do not look
at this" from "this is broken". And the four corpora's most interesting
files, the ones with real ingredient histories, measure nothing.

**Measured before writing this spec** (step 53 and the simulation of
2026-09-22, `m7-validate.php` / `m7-state.php` in the scratch directory,
against c2patool 0.27.22 with `full.settings.json`):

- Of the seventeen readable multi-manifest files, **six** carry a
  reference whose hash matches the manifest box's payload (§8.4.2.3), and
  **eleven** a reference that matches the *claim's CBOR bytes* instead —
  the pre-1.3 form c2pa-rs still accepts (`verify_by_alg(alg, hash,
  ingredient.data())`). c2patool issues `ingredient.manifest.validated`
  only on the box-payload match, never on the legacy one, and neither is
  a failure.
- Running this verifier's existing checks on each referenced manifest and
  then dropping what the assertion recorded gives, per file, **exactly
  c2patool's delta failures** on fourteen of the seventeen; the three that
  differ (`ocsp`, `ocsp_with_assertion`, `exp-test1`) differ by
  `signingCredential.expired` only — their TSAs are not in the settings,
  so the signer is judged at *now*, which is the named leniency those
  three files already carry for their active manifest
  (`SPEC013_RS_TSA_NOT_CONFIGURED`, ADR-0004 decision 3).
- Simulating the whole verdict gives **c2patool's `validation_state` on
  sixteen of the eighteen** files; the two that differ are `ocsp` and
  `ocsp_with_assertion` for that same reason.
- Without the dropping rule, four files would report failures c2patool
  does not (`claimSignature.mismatch` on the two `CIE-sig-CA` files and
  `E-uri-CIE-sig-CA`, `assertion.hashedURI.mismatch` on
  `CACAE-uri-CA`) — the rule is not an optimisation, it is the
  specification's meaning of a recorded failure.

## Scope

**In scope**

- `Manifest\IngredientManifestCheck` (name illustrative): for each entry of
  `ManifestGraph::$referenced`, in walk order, and scoped to the ingredient
  assertion's URI:
  1. **The box hash.** The referring hashed URI's `hash` compared with the
     hash of the referenced manifest's superbox payload (SPEC-020's
     `Superbox::payload()`, C2PA 2.4 §8.4.2.3) under the reference's `alg`
     or, absent that, the *referenced* claim's `alg` (SHA-256 when it has
     none). A match is `ingredient.manifest.validated` (success), url the
     reference's url **verbatim** (c2patool prints what the writer wrote:
     `self#jumbf=/c2pa/<label>` in most files, `…/c2pa.claim` in
     `update_manifest`). No match: the same hash over the referenced
     claim's CBOR bytes (the pre-1.3 form) — a match there is silent,
     neither success nor failure, as c2patool. Neither: a failure
     `ingredient.manifest.mismatch` with that url.
  2. **The manifest itself**, whatever the hash said (c2pa-rs validates
     the ingredient claim even after a box-hash match; a "short circuit"
     the code names but does not take): the timestamp check, the claim
     signature, the certificate profile, the chain and trust under the
     same `TrustSettings` as the active manifest, the hashed URIs, the
     actions assertion. **Not** the data hash: an ingredient has no hard
     binding to this file's bytes.
- **Dropping what the ingredient assertion recorded** (§18.16.12.4,
  c2pa-rs `ValidationResults::from_store`): a status produced under an
  ingredient assertion is left out of the report when the assertion's
  recorded list holds an entry with the same code *and* the same url,
  where a recorded url that is relative (`self#jumbf=c2pa.assertions/…`)
  is first made absolute against the manifest the assertion references.
  The guard: a status whose url names the **active** manifest is never
  dropped. v1/v2 read `validationStatus`; v3 reads
  `validationResults.activeManifest` (its three lists) and
  `validationResults.ingredientDeltas[].validationDeltas` (the same),
  since a v3 assertion records the whole tree it validated.
- **Lifting SPEC-013 amendment 5**: a store with more than one manifest is
  no longer refused for being multi-manifest. What still refuses, each for
  its own named reason: a `c2um` update manifest (the JUMBF parser,
  SPEC-005 AC13, until SPEC-022), a CAWG identity assertion (SPEC-013
  amendment 7), a claim with a non-empty `redacted_assertions` (new, see
  below), and the graph's bounds (SPEC-020).
- **Redactions refused.** A claim in the store whose `redacted_assertions`
  is non-empty is `general.error` on the store: the specification then
  requires the claim-signature-hash method (§15.11.3.3.1) and the
  `assertion.notRedacted` check, and no corpus file has one to measure
  against. Named in `docs/comparison.md`; the maintainer decided this on
  2026-09-22 (step 53 §4.3).
- `Report\StatusCode` gains `ingredient.manifest.validated` (a success) and
  `ingredient.manifest.mismatch` (a failure).
- `checks_performed` gains `ingredients` after `actions`, when the graph
  reached at least one manifest.
- The report's `manifests` map keeps every manifest in the store, as
  today; `ingredients[].validation_status` (what the *writer* recorded)
  stays what SPEC-020 renders — this spec adds no field to it.

**Out of scope** (each needs its own spec before it may be built)

- Update manifests (`c2um`): SPEC-022, which also brings §15.12's rule
  that the hard binding then lives in the `parentOf` chain.
- The claim-signature-hash method (§15.11.3.3.1) and
  `ingredient.claimSignature.validated` / `.mismatch` / `.missing`: they
  exist for redacted ingredient manifests, which this spec refuses. When
  a redaction fixture exists, that spec adds both.
- `assertion.notRedacted`, `assertion.selfRedacted` (§15.11.3.3): the same.
- Validating the *shape* of a recorded `validationResults` beyond what the
  dropping rule reads, or reporting a v3 assertion whose record disagrees
  with what this verifier finds (§15.11.3.3's "return it as part of the
  validation results" concerns a claim generator writing an assertion, not
  a verifier reporting).
- CAWG identity assertions in an ingredient manifest: refused as in the
  active manifest.
- OCSP and revocation, in any manifest: never in the verification path.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-021')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The oracle is c2patool 0.27.22's recorded JSON over the corpora
(`--settings full.settings.json`, and without settings for the writers
corpus), plus signed variants made in the tests-first step from the
multi-manifest files.

- **AC1 — the box hash: validated, legacy, mismatch**
  - Given `c2pa-rs/CACA.jpg` (a v3 reference matching the box payload),
    `public-testfiles/adobe-20220124-CACA.jpg` (a v1 reference matching
    the claim's CBOR bytes) and a variant of the first with one byte of
    the ingredient manifest's box changed after signing
  - When the ingredient manifests are checked
  - Then the first reports `ingredient.manifest.validated` (success) with
    url `self#jumbf=/c2pa/urn:c2pa:5259041e-…:contentauth` — c2patool's
    code, url and place in the delta; the second reports neither that
    code nor a failure; the third reports `ingredient.manifest.mismatch`
    (failure) with the reference's url, and the file is `Invalid`.

- **AC2 — the manifest is validated, not just hashed** *(the rule that matters)*
  - Given a variant of `c2pa-rs/CACA.jpg` in which the ingredient
    manifest's *signature* is broken while the referring hashed URI is
    re-computed so that the box hash still matches
  - When `Verifier::verify()` runs
  - Then the report holds `claimSignature.mismatch` scoped to the
    ingredient assertion **next to** `ingredient.manifest.validated`, and
    the state is `Invalid` — a matching box hash never stands in for
    validating the manifest.

- **AC3 — every corpus file: the state and the delta failures are c2patool's**
  - Given the seventeen `_MULTI` files this verifier's JUMBF parser reads
    (`update_manifest` is a `c2um` box) with `full.settings.json`
  - When the Verifier runs
  - Then `validation_state` equals c2patool's on all but `ocsp` and
    `ocsp_with_assertion`, which are `Invalid` here for
    `signingCredential.expired` (their TSAs are not configured — the
    existing `SPEC013_RS_TSA_NOT_CONFIGURED` leniency, now visible in an
    ingredient as well); and for every file the set of failure codes in
    `validation_status` equals c2patool's, except those two and
    `signingCredential.expired`. The `_MULTI` exception lists in
    `tests/Pest.php` shrink to the files still refused by name
    (`update_manifest`, `cawg_ica`), and SPEC-013 AC11's "more than one
    manifest is `general.error`" criterion is replaced by this one.

- **AC4 — what the ingredient assertion recorded is dropped, and only that** *(the specification's rule)*
  - Given `public-testfiles/adobe-20220124-CIE-sig-CA.jpg` (the ingredient
    assertion records `claimSignature.mismatch` for a signature that is
    genuinely broken), `c2pa-rs/CIE-sig-CA.jpg` (the same in v2) and
    `c2pa-rs/CACAE-uri-CA.jpg` (a recorded `assertion.hashedURI.mismatch`)
  - When the Verifier runs
  - Then all three are `Trusted`, exactly as c2patool, and the dropped
    statuses appear nowhere in the report; and the *recorded* list is
    still visible to the caller in `manifests[<label>].ingredients[].validation_status`
    (SPEC-020's rendering), so nothing is hidden — only re-reported.
  - And given `public-testfiles/adobe-20220124-E-uri-CIE-sig-CA.jpg`,
    where one fault (the ingredient's `c2pa.actions` hashed URI) is *not*
    in the recorded list
  - Then that one is reported, the state is `Invalid`, and the codes equal
    c2patool's.

- **AC5 — the guard: a recorded status never cancels the active manifest's** *(required: error path)*
  - Given a signed variant of the PNG fixture whose ingredient assertion
    records `claimSignature.mismatch` with the url of the **active**
    manifest's signature box, and whose active manifest's signature is
    then broken
  - When the Verifier runs
  - Then `claimSignature.mismatch` is reported for the active manifest and
    the state is `Invalid`: a status whose url names the active manifest
    is never dropped, whatever any ingredient assertion claims
    (c2pa-rs's CAI-12751 guard, copied).

- **AC6 — a redaction is refused** *(error path)*
  - Given a signed variant whose claim carries a non-empty
    `redacted_assertions`
  - When the Verifier runs
  - Then the report is `Invalid` with one `general.error` on the store
    naming redactions and the spec that will validate them, and no
    ingredient status is produced for that store.

- **AC7 — the data hash never runs on an ingredient**
  - Given `public-testfiles/adobe-20220124-CACA.jpg`
  - When the Verifier runs
  - Then `assertion.dataHash.match` appears exactly once, for the active
    manifest, and no `assertion.dataHash.*` status is scoped to an
    ingredient assertion — even though every ingredient manifest in that
    file carries a `c2pa.hash.data` of its own (they bind *their* asset,
    which is not this file).

- **AC8 — `checks_performed` and the report's shape**
  - Given a multi-manifest file and a single-manifest file
  - When the Verifier runs
  - Then the first lists `['timestamp', 'signature', 'certificate',
    'trust', 'hashedUris', 'actions', 'ingredients', 'dataHash']` and the
    second the same without `ingredients`; the deltas hold the ingredient
    statuses grouped by assertion URI in walk order (SPEC-020 AC9's
    shape); and `validation_status` lists the active manifest's failures
    before the deltas'.

- **AC9 — one ingredient manifest, two references** *(a graph, not a tree)*
  - Given `public-testfiles/adobe-20220124-CAIAIIICAICIICAIICICA.jpg`,
    where one manifest is named by more than one assertion
  - When the Verifier runs
  - Then that manifest is validated once, its statuses are scoped to the
    assertion that named it first (the walk order of SPEC-020), and the
    state and failure codes equal c2patool's.

- **AC10 — the corpora and the fuzzer stay green** *(the drift alarms)*
  - Given the four corpora, the own variants and `bin/fuzz.php` with its
    recorded seed
  - When the alarms run
  - Then no single-manifest verdict changes; the writers, official and
    c2pa-rs alarms hold with the shrunken exception lists; and no
    exception escapes the verifier on a mutated multi-manifest file.

## References

- Specification: C2PA 2.4 §15.11.2 (validate each ingredient, whatever its
  relationship), §15.11.3.3 (the recursive algorithm, the two validation
  methods, "if the ingredient assertion contains a validationResults
  field …"), §15.11.3.3.2 (the manifest hash method,
  `ingredient.manifest.validated` / `.mismatch` / `.missing`),
  §15.11.3.3.1 (the claim-signature method — out of scope here),
  §8.4.2.3 (hashing a JUMBF box), §18.16.12.4 (what a recorded failure
  means), §15.12 (the asset's content is bound by the active manifest).
  Read 2026-09-22 (step 53).
- Oracle: c2patool 0.27.22 on the eighteen multi-manifest corpus files
  (`tests/Fixtures/c2patool/`), the delta contents of `CACA`,
  `CACAE-uri-CA`, `CIE-sig-CA`, `ocsp_with_assertion` read code by code;
  the simulation of 2026-09-22 (step 55's note) that measured, with this
  verifier's own checks: six box-payload hashes, eleven legacy hashes,
  fourteen of seventeen files' delta failures equal, sixteen of eighteen
  states equal, and the two exceptions named above.
- Reasoned (from c2pa-rs 0.90.22 `store.rs` `ingredient_checks`,
  `validation_results.rs` `from_store` and `validation_state`): that the
  ingredient claim is validated even when the box hash matched; that the
  dropping compares code and url (and `kind`, which follows from the
  code here); that the active-manifest guard is two independent tests in
  c2pa-rs, of which this verifier keeps the one it can apply (the url
  names the active manifest — this verifier has no "logged outside an
  ingredient scope" signal beyond the scope it sets itself).

## API sketch

Illustrative only — not binding implementation.

```php
// namespace Provemark\C2paVerifier\Manifest;

final readonly class IngredientManifestCheck
{
    public function __construct(
        private ClaimSignatureCheck $signature = new ClaimSignatureCheck,
        private CertificateProfileCheck $certificate = new CertificateProfileCheck,
        private ChainCheck $trust = new ChainCheck,
        private HashedUriCheck $hashedUris = new HashedUriCheck,
        private ActionsCheck $actions = new ActionsCheck,
        private TimestampCheck $timestamp = new TimestampCheck,
    ) {}

    /**
     * Every manifest the graph reached, in walk order, each scoped to the assertion that named it.
     *
     * @return list<ValidationStatus>
     */
    public function check(ManifestStore $store, ManifestGraph $graph, ?TrustSettings $settings): array;

    /** The box hash of one reference: validated, legacy (silent), or mismatch. */
    public function hash(Manifest $ingredient, HashedUri $reference, string $scope): ?ValidationStatus;

    /**
     * The statuses an ingredient assertion already recorded, as "code url" keys with relative
     * urls made absolute against the manifest it references (v1/v2 validationStatus, v3
     * validationResults — activeManifest and every ingredientDelta).
     *
     * @return list<string>
     */
    public static function recorded(IngredientAssertion $ingredient): array;
}
```

`StatusCode` gains `ingredient.manifest.validated` (success) and
`ingredient.manifest.mismatch` (failure). The Verifier calls the check
after `actions` and before `dataHash`, adds `ingredients` to
`checks_performed`, and drops the multi-manifest refusal of SPEC-013
amendment 5 (which that spec records as amended by this one). Deptrac:
`Manifest` would need `Cose`, `Trust`, `Hash` and `Timestamp`, which
inverts today's arrows — so the check lives in the `Verifier` layer
instead, beside the orchestration that already holds those collaborators.

## Open questions

- **Where the check lives.** `Manifest` may not depend on `Cose`, `Trust`,
  `Hash` or `Timestamp` (Deptrac, and the layering is deliberate: the
  parsers know nothing of cryptography). The check therefore belongs in
  `src/Verifier/` — `Verifier\IngredientManifestCheck` — which already
  depends on everything it needs. *Non-blocker; decided in the
  tests-first step, named here so the reader is not surprised.*
- **The timestamp of an ingredient.** `TimestampCheck` supplies the time a
  signer's validity is judged at. An ingredient manifest's own timestamp
  is used for its own signer, exactly as for the active manifest; the
  active manifest's time is never borrowed. *Decided; measured in AC3
  (the DigiCert-timestamped Adobe ingredients are `Trusted` this way).*
- **`ingredient.manifest.missing` twice.** c2patool reports it once
  scoped and once unscoped; SPEC-020 reports it once. Unchanged here.
  *Non-blocker.*
- **A v3 record that disagrees with what this verifier finds.** The
  specification would have a claim generator merge both; a verifier that
  re-validates finds its own answer, and the dropping rule only removes
  what both say. Nothing is added for a record that claims a *success*
  this verifier does not find: the verifier's own failure stands.
  *Decided.*

## Amendments

1. **2026-09-22, step 56b, measured** — AC2's "one scope" was written for
   the variant and is wrong for the report: the *ingredient* manifest of
   `CACA` has an ingredient assertion of its own, so SPEC-020's
   `ingredient.unknownProvenance` gives the report a second delta. The
   criterion holds for the statuses this spec adds, which is what it was
   about; the test says so.
2. **2026-09-22, step 56b, measured** — AC8's `checks_performed` list
   holds `ingredients` only where the graph actually reached a manifest,
   which the Scope already said: `adobe-20220124-E-clm-CAICAI` names a
   manifest that is not in the store, so there is nothing to validate and
   the key is absent. The criterion's example is narrowed to the files
   that do reach one.
3. **2026-09-22, step 56b, consequences in the existing alarms** — three
   criteria of earlier specs had to change with this one, which AC3
   foresaw for the first: SPEC-013 AC11 no longer expects a refusal for a
   multi-manifest store (it expects c2patool's state and
   `checks_performed` with `ingredients`), SPEC-013 AC12's "stricter"
   list keeps only `update_manifest` of the `_MULTI` names, and SPEC-017's
   test helper reads the **active** manifest's statuses only, since an
   ingredient's timestamp is now checked too and its statuses are scoped.
   The `_MULTI` lists in `tests/Pest.php` stay as the enumeration of
   multi-manifest files, no longer as "expected `Invalid`".

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Verifier/IngredientManifestCheckTest.php :: AC1 / SPEC-021 | src/Verifier/IngredientManifestCheck.php (`hash()`); src/Report/StatusCode.php |
| AC2 | tests/Unit/Verifier/IngredientManifestCheckTest.php :: AC2 / SPEC-021 | src/Verifier/IngredientManifestCheck.php (`check()`, `manifest()`) |
| AC3 | tests/Unit/Verifier/IngredientManifestCheckTest.php :: AC3 / SPEC-021; tests/Unit/Verifier/VerifierTest.php :: AC11, AC12 / SPEC-013 | src/Verifier/Verifier.php (the refusal of SPEC-013 amendment 5 removed) |
| AC4 | tests/Unit/Verifier/IngredientManifestCheckTest.php :: AC4 / SPEC-021 | src/Verifier/IngredientManifestCheck.php (`recorded()`, `drop()`) |
| AC5 | tests/Unit/Verifier/IngredientManifestCheckTest.php :: AC5 / SPEC-021 | src/Verifier/IngredientManifestCheck.php (`drop()`, the active-manifest guard) |
| AC6 | tests/Unit/Verifier/IngredientManifestCheckTest.php :: AC6 / SPEC-021 | src/Hash/HashedUriCheck.php (the redaction refusal, kept) |
| AC7 | tests/Unit/Verifier/IngredientManifestCheckTest.php :: AC7 / SPEC-021 | src/Verifier/IngredientManifestCheck.php (`manifest()`: no data hash) |
| AC8 | tests/Unit/Verifier/IngredientManifestCheckTest.php :: AC8 / SPEC-021 | src/Verifier/Verifier.php (`checks_performed`); src/Report/ValidationResult.php |
| AC9 | tests/Unit/Verifier/IngredientManifestCheckTest.php :: AC9 / SPEC-021 | src/Verifier/IngredientManifestCheck.php (`check()`: the first assertion that named it) |
| AC10 | tests/Unit/Verifier/VerifierTest.php :: AC10–AC13 / SPEC-013; tests/Unit/Cli/CommandTest.php :: AC11 / SPEC-019; bin/fuzz.php | the whole verification path |

`src/Verifier/IngredientManifestCheck.php` maps to this spec; `StatusCode`'s two new cases are its. Measured 2026-09-22: 7 red (2 already true) → 9 green, `composer check` exit 0, 336 tests, 312 fuzz runs with no fault.
