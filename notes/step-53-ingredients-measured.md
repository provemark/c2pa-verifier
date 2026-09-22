# Step 53 — Ingredient manifests measured, before M7

*2026-09-22.* M7 is the milestone that lifts SPEC-013 amendment 5 — "a
store with more than one manifest is `Invalid` until M7" — by validating
the other manifests: the ingredients. This note is the measurement that
the M7 specs rest on: what the specification says (read), what c2pa-rs
0.90.22 does (read, with the functions named), and what the eighteen
multi-manifest files of the corpora hold (measured with this verifier's
own parsers and against c2patool's recorded JSON). Nothing here is code.

## 1. What an ingredient is (C2PA 2.4, read)

An asset made from other assets records each of them as an *ingredient*:
an assertion `c2pa.ingredient` (v1, deprecated), `c2pa.ingredient.v2`
(deprecated) or `c2pa.ingredient.v3` (current) in the manifest that used
it (§18.16). The assertion holds a `relationship` — `parentOf` (this asset
is derived from the ingredient), `componentOf` (composed of it), `inputTo`
(fed to a process) — and, when the ingredient had Content Credentials of
its own, its whole active manifest is *copied into this asset's manifest
store* and referenced by a hashed URI: `c2pa_manifest` (v1/v2) or
`activeManifest` (v3), plus in v3 a second hashed URI `claimSignature` to
that manifest's signature box (§18.16.12.3). A v3 assertion with an
`activeManifest` must also carry `validationResults` — the claim
generator's own validation of the ingredient at the time it was used
(§18.16.12.4); v2 carries an optional `validationStatus` list.

So a manifest store with three manifests is one *active* manifest and two
that were carried along, each referenced from an ingredient assertion
somewhere in the tree. The validator's job (§15.11) is to walk that tree
and validate every referenced manifest — everything except the hard
binding, which an ingredient manifest cannot have checked because its
asset bytes are not here (§15.11.3.3.1: "content bindings are not
evaluated").

Two validation methods (§15.11.3.3):

- **Manifest hash method** (§15.11.3.3.2): hash the ingredient's manifest
  box per §8.4.2.3 — the superbox's *payload*, description box and content
  boxes, without the 8-byte header — and compare with the hashed URI; equal
  → `ingredient.manifest.validated` ("the ingredient is fully validated"),
  unequal → `ingredient.manifest.mismatch`; URI unresolvable →
  `ingredient.manifest.missing`.
- **Claim signature hash method** (§15.11.3.3.1): required when the
  ingredient manifest was *redacted* after signing (its box hash cannot
  match any more): hash the signature box against `claimSignature`
  (`ingredient.claimSignature.validated` / `.mismatch` / `.missing`), then
  validate the claim in full — signature, timestamp, every non-redacted
  assertion — as for the active manifest, minus the hard binding.

An ingredient without a manifest reference is `ingredient.unknownProvenance`
(informational) unless `inputTo`. A missing or unknown `relationship`, or
`activeManifest` next to `digitalSourceType`, is
`assertion.ingredient.malformed` (§15.11.3.2). Recursion follows every
referenced manifest (depth-first); manifests in the store that no
ingredient references "should be ignored".

The recorded results: "for each entry in `validationResults`, if an
equivalent entry was not returned as part of the validation process,
return it; entries returned that are not in `validationResults`, return
them" (§15.11.3.3) — and §18.16.12.4.1: a recorded *failure* "is
considered an explicit statement by the claim generator that an actor has
acknowledged validation errors in the ingredient's C2PA Claim itself and
has chosen to proceed".

**Update manifests** (§11.2.3): a manifest of type `c2um` that adds
assertions without touching the content — no hard binding, actions only
from {`c2pa.edited.metadata`, `c2pa.opened`, `c2pa.published`,
`c2pa.redacted`}, no thumbnail, exactly one `parentOf` ingredient naming
the manifest it updates. The asset's hard binding is then found by
following `parentOf` to the first standard manifest (§15.12); none found →
`claim.hardBindings.missing`. The data-hash exclusion for the store is
"treated as the current length" of the store, since an update manifest
lengthens it.

## 2. What c2pa-rs 0.90.22 does (read; `store.rs`, `validation_results.rs`)

`Store::verify_store` (store.rs 2020): the active claim is
`provenance_claim()`; `get_store_validation_info` walks
`get_claim_referenced_manifests` (every ingredient's `c2pa_manifest`
resolved in the store, cycles → `assertion.ingredient.malformed`, absent →
`ingredient.manifest.missing`, depth ≤ `MAX_INGREDIENT_DEPTH` = 200) and
finds `binding_claim` with `get_hash_binding_manifest` (3795: the claim
itself if not an update manifest and it has a hash assertion, else the
`parentOf` chain; none → `claim.hardBindings.missing`). Then
`Claim::verify_claim` on the active claim, `Store::ingredient_checks`
recursively, and `verify_hash_binding` **once, on the binding claim**.

`Store::ingredient_checks` (1604), per ingredient assertion of a claim:

1. zero-filled assertion data → skipped (a redaction placeholder);
2. unparsable → `assertion.ingredient.malformed`;
3. `push_ingredient_uri(<this assertion's URI>)` — every status logged
   from here on is scoped to this ingredient assertion;
4. with a manifest reference: v3 without `validationResults` →
   `assertion.ingredient.malformed`; the referenced claim looked up by
   label, absent → `ingredient.manifest.missing`; hash compared with
   `manifest_box_hash` (a *rebuild* of the manifest box, payload only —
   `calc_manifest_box_hash`), and when that differs, with the **legacy
   hash over the claim's CBOR bytes** (`verify_by_alg(alg, hash,
   ingredient.data())`, "pre 1.3"); a box-hash match →
   `ingredient.manifest.validated`; no match and no redactions →
   `ingredient.manifest.mismatch`; no match *with* redactions and a v2+
   claim → the signature-box hash against `claimSignature`
   (`ingredient.claimSignature.validated` informational / `.mismatch` /
   `.missing`); **then, always, `Claim::verify_claim` on the ingredient
   claim** with `check_ingredient_trust = settings.verify.verify_trust` —
   the manifest hash never short-circuits the full check, whatever the
   comment says; then recursion into that claim's ingredients, each label
   visited once;
5. without a manifest reference and not `inputTo` →
   `ingredient.unknownProvenance` informational.

`ValidationResults::from_store` (validation_results.rs 126) turns the log
into the report: a status logged outside any ingredient scope goes to
`activeManifest`; a scoped status goes to `ingredientDeltas[]` under its
assertion's URI — **after dropping every scoped status that equals (code,
url, kind) a status the ingredient assertions recorded** in their
`validationStatus` / `validationResults` (relative urls made absolute
with the ingredient's manifest label), *unless* the status's url names a
box of the active manifest — the guard against an ingredient assertion
cancelling an active-manifest failure (CAI-12751).

`validation_state` (241): **Valid** = active has `claimSignature.validated`
and `claimSignature.insideValidity`, active failures all
`signingCredential.untrusted`, and every delta's failures all
`signingCredential.untrusted`; **Trusted** = Valid, active has
`signingCredential.trusted`, no failure anywhere; else **Invalid**.
`validation_status` (the flat list) = active failures + every delta's
failures, in order, duplicates kept.

## 3. The eighteen files (measured)

`m7-measure.php` (scratch, this verifier's `JumbfParser`, `Manifest`,
hashed-URI comparison by `Superbox::payload()`), against the recorded
c2patool JSON (`full.settings.json`). Every file's active manifest is
the **last** manifest box in the store, as this verifier already assumes.

| file | manifests | ingredient assertions with a reference | reference hash equals | c2patool |
|---|---|---|---|---|
| public `CACA`, `CAICA`, `CAICAI`, `CICA`, `CICACACA` | 2 | 1 each, v1 `c2pa.ingredient` | **the claim's CBOR bytes** (legacy), not the box payload | `Trusted`; delta = full validation, no `ingredient.manifest.validated` |
| public `CACAICAICICA` | 4 | 4 (v1) | legacy | `Trusted` |
| public `CAIAIIICAICIICAIICICA` | 6 | 6 (v1) | legacy | `Trusted`; 14 deltas |
| public `CIE-sig-CA` | 2 | 1 (v1), **`validationStatus` records `claimSignature.mismatch`** | legacy | **`Trusted`** — the ingredient's signature *is* broken (E-sig-CA); the failure is dropped because the assertion attested it; `timeStamp.mismatch` stays (its recorded url is `Cose_Sign1`, not a JUMBF url, so it is not equal) |
| public `E-uri-CIE-sig-CA` | 2 | same ingredient, plus a tampered `c2pa.actions` in it | legacy | `Invalid`: `assertion.hashedURI.mismatch` on the ingredient's actions (not attested) — the file SPEC-013 amendment 5 was made for |
| public + c2pa-rs `E-clm-CAICAI` | 2 | 1 (v1) referencing `contentbeef:…` | **not in the store** | `Invalid`: `ingredient.manifest.missing` (twice in `validation_status`) + `assertion.hashedURI.mismatch` on the ingredient assertion itself |
| c2pa-rs `CACA` | 2 | 1, **v3** with `validationResults`, `activeManifest` + `claimSignature` | **box payload** (both) | `Trusted`; delta = `ingredient.manifest.validated`, `signingCredential.trusted` — the rest attested and dropped |
| c2pa-rs `CACAE-uri-CA` | 3 | 2 (v1, one with `validationStatus` = 1 entry) | box payload | `Trusted`; `ingredient.manifest.validated` + full validation |
| c2pa-rs `CIE-sig-CA` | 2 | 1, **v2**, `validationStatus` = `claimSignature.mismatch` | box payload | `Trusted` (same mechanism as the public file) |
| c2pa-rs `legacy_ingredient_hash` | 2 | 1 (v1) | legacy | `Trusted` |
| c2pa-rs `ocsp`, `ocsp_with_assertion` | 2, 3 | 1 each (v1, `validationStatus` = `[]`, a `metadata` field) | box payload | `Valid`: untrusted active *and* untrusted ingredient |
| c2pa-rs `exp-test1` | 6 | 8 (v1, all `validationStatus` = `[]`) | legacy | `Invalid`: an ingredient signer `signingCredential.invalid` (expired, no timestamp) |
| c2pa-rs `update_manifest` | 2 | 1, v3, `parentOf`, in a **`c2um`** box | — (this verifier's JUMBF parser refuses `c2um`, SPEC-005 AC13) | `Trusted`; active = the update manifest; delta = `ingredient.manifest.validated`; `assertion.dataHash.match` under the active manifest though the binding lives in the parent |
| writers `cawg_ica` | 2 | 1 of 3, v2 (the other two have no reference) | box payload | `Valid` (untrusted) |

Also measured: no corpus file has a non-empty `redacted_assertions`
(c2pa-rs `CACA` has `[]`), no ingredient assertion is zero-filled, no
reference carries an `alg` (the claim's `alg` or SHA-256 applies), every
hashed URI is `self#jumbf=/c2pa/<label>` (absolute, no `/c2pa.claim`
suffix — c2patool's `ingredient.manifest.validated` url adds
`/c2pa.claim`), and the c2pa-rs "rebuild" of the manifest box equals the
bytes as found in every 2023+ file — hashing the payload as it lies is the
same check.

## 4. What this means for M7 (reasoned; for Maurice's decisions)

1. **Three specs, in this order.**
   - *SPEC-020 — the ingredient assertion and the manifest graph*: parse
     v1/v2/v3 (`relationship`, title/format/instanceID, the two hashed
     URIs, `validationStatus` / `validationResults`, `digitalSourceType`),
     the malformed rules of §15.11.3.2, the walk from the active manifest
     with a depth bound and cycle detection, `ingredient.manifest.missing`,
     `ingredient.unknownProvenance`. Data and graph only; no verdict change
     yet (the amendment-5 refusal stays until SPEC-021).
   - *SPEC-021 — ingredient validation and the report*: the manifest-hash
     method with the legacy fallback (both measured), the full validation
     of each ingredient claim through the existing checks (signature,
     profile, trust, timestamp, hashed URIs, actions — not the data hash),
     `ingredientDeltas` in `validation_results`, the drop of attested
     statuses with the active-manifest guard, the three-state rule as
     c2pa-rs computes it, `ingredients` in the manifest rendering; lifts
     SPEC-013 amendment 5 and closes `_MULTI`.
   - *SPEC-022 — update manifests*: `c2um` read as a manifest (lifting
     SPEC-005 AC13 for `c2um` only; `c2cm` stays refused — Brotli is not
     in PHP), the §11.2.3 rules as checks, the binding claim through the
     `parentOf` chain, the store-length exclusion rule; closes
     `update_manifest`.
2. **Attested failures.** The specification and c2pa-rs let a claim
   generator's recorded failure silence the same failure found now
   (`CIE-sig-CA`: a broken ingredient signature, `Trusted`). Options: (a)
   copy exactly — drift-alarm equality, and it is the specification's
   stated meaning ("acknowledged … chosen to proceed"); (b) stricter —
   report it anyway and make the file `Invalid`, a named difference.
   **Proposal: (a)**, with the CAI-12751 guard verbatim (nothing about the
   active manifest is ever dropped) and a corpus test that shows both
   files: `CIE-sig-CA` `Trusted`, `E-uri-CIE-sig-CA` `Invalid`. The
   verdict then says what the standard says; a caller who wants to see
   acknowledged failures reads the ingredient's recorded
   `validation_status`, which the report will render.
3. **Redactions.** No corpus file has one, so the claim-signature-hash
   method cannot be measured. **Proposal:** a store whose claims carry a
   non-empty `redacted_assertions` is refused with `general.error` until a
   fixture exists — fail closed, named in `docs/comparison.md`. (A
   variant could be made with `c2patool` … `--redact`? To be measured
   when the spec is drafted.)
4. **The legacy hash** (claim CBOR bytes): all ten Adobe 2022 files and
   two c2pa-rs files need it; without it they are `ingredient.manifest.mismatch`.
   **Proposal:** accept as c2pa-rs does — no `ingredient.manifest.validated`
   on that path, the full validation of the ingredient decides.
5. **Bounds.** c2pa-rs allows a depth of 200; this verifier's bounds are
   its own. **Proposal:** depth 32, at most 256 ingredient assertions per
   store, beyond → `general.error`; a manifest referenced twice is
   validated once.
6. **The exit status and the deltas.** SPEC-019 needs nothing: the state
   decides, and the state rule is in SPEC-021.

## Measured / reasoned

- Measured: `m7-measure.php` over the 18 files (the table's columns 2–4);
  the c2patool JSON (column 5); the active-manifest-is-last check on 17 of
  18 (the 18th is refused before the check).
- Read: C2PA 2.4 §8.4.2.3, §11.2.3, §15.11 (all of it), §15.12, §18.16;
  c2pa-rs 0.90.22 `store.rs` (`verify_store`, `ingredient_checks`,
  `get_hash_binding_manifest`, `get_claim_referenced_manifests`,
  `calc_manifest_box_hash`), `claim.rs` (`calc_sig_box_hash`),
  `validation_results.rs` (`from_store`, `validation_state`,
  `validation_errors`), `validation_status.rs` (`PartialEq`,
  `make_absolute`).
- Reasoned: §4 entirely.
