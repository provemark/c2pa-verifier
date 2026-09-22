# Step 60 — The absence audit for M7: four signed stores, no new hole

*2026-09-22.* Step 48's method, applied to what M7 added: for every rule
of the form "the verifier does X when Y is present", a **signed** store
in which Y is absent. An unsigned edit is `Invalid` for its signature
alone and proves nothing about the absence.

## 1. The inventory (from the code, then measured)

Every place SPEC-020, SPEC-021 or SPEC-022 returns early, skips, or
treats a field as optional:

| where | what may be absent | what happens | measured with a file? |
|---|---|---|---|
| `IngredientAssertion` | `relationship` | `assertion.ingredient.malformed` | yes (step 54) |
| `IngredientAssertion` | the manifest reference | `ingredient.unknownProvenance`, unless `inputTo` | yes (step 54) |
| `IngredientAssertion` | `validationResults` on a v3 with a reference | malformed | yes (step 54) |
| `IngredientAssertion` | **`claimSignature`** on a v3 with a reference | **nothing** — §18.16.12.3 says both shall be stored | **`no-claim-signature`** |
| `IngredientAssertion` | `thumbnail`, `digitalSourceType`, `documentID` | nothing; all optional | n/a |
| `ManifestGraph` | the referenced manifest (not in the store) | `ingredient.manifest.missing` | yes (`E-clm-CAICAI`) |
| `ManifestGraph` | **any reference at all to a manifest in the store** | it is **never validated** (§15.11.3.3: "should ignore") | **`unreferenced-broken`** |
| `IngredientManifestCheck` | the reference's `alg` | the claim's, else sha256 | yes (the corpora) |
| `IngredientManifestCheck` | the recorded `validationStatus`/`validationResults` | nothing is dropped | yes (the corpora) |
| `IngredientManifestCheck` | **the ingredient's own actions assertion** | `assertion.action.malformed`, scoped | **`ingredient-no-actions`** |
| `IngredientManifestCheck` | the ingredient's hard binding | nothing — by design (§15.11.3.3.1) | yes (`update_manifest`) |
| `UpdateManifestCheck` | the `parentOf` chain's standard manifest | `claim.hardBindings.missing` | yes (step 57) |
| `UpdateManifestCheck` | an update manifest's actions assertion | nothing; §11.2.3 requires none | n/a |

Three rows had no file. This step built them — and the store they need
did not exist anywhere: **a two-manifest store this project made
itself**, from the PNG fixture (the manifest box copied under a second
label, the copy first so that the active manifest is last, a v3
ingredient assertion in the active claim naming the copy's manifest and
signature boxes, the binding re-bound, both claims re-signed with a
throw-away hierarchy). Two details the build taught us:

- the fixture's claim names its **signature box by an absolute URI**, so
  relabelling the copy changes its claim — hence the copy is re-signed
  too;
- the copy carries **no thumbnail**: with it the store passes 65535 bytes
  and the exclusion can no longer be a two-byte CBOR integer, which is a
  different edit than the one under test.

## 2. What the four files say (measured against c2patool 0.27.22)

| variant | c2patool | here |
|---|---|---|
| `two-manifests` (control) | `Trusted` | `Trusted`; the ingredient really is validated — `ingredient.manifest.validated`, its own `claimSignature.validated`, `signingCredential.trusted` and six hashed URIs in the delta |
| `ingredient-no-actions` | `Invalid`, `assertion.action.malformed` | the same, scoped to the assertion that named the ingredient |
| `unreferenced-broken` | `Trusted` | `Trusted` |
| `no-claim-signature` | `Trusted` | `Trusted` |

**No new hole.** The two rows that end in `Trusted` are both the
specification's own answer, and both deserve to be said out loud:

- **A manifest nobody references is never validated.** Its signature is
  genuinely broken in `unreferenced-broken` — `ClaimSignatureCheck` on it
  returns `claimSignature.mismatch` when asked directly — and the file is
  `Trusted` all the same, here and at c2patool, because §15.11.3.3 says a
  validator "should ignore any additional C2PA Manifests that appear in
  the C2PA Manifest Store but are not in the list of ingredient
  manifests". Both still **render** it under `manifests`, so a reader who
  displays that map shows something nobody vouched for. Named in
  `docs/comparison.md`; a status code of our own is not an option (§15's
  vocabulary has none, and this project invents none).
- **A v3 ingredient assertion without `claimSignature`** is read by both,
  though §18.16.12.3 says both hashed URIs shall be stored. c2pa-rs needs
  the second one only when a redaction forces the claim-signature method.

## 3. What this step also produced

The two-manifest builder. Until now every multi-manifest measurement
depended on files Adobe or c2pa-rs happened to publish; this project can
now make its own, which is what made these three absences answerable —
and will make the next ones cheaper.

## Measured / reasoned

- Measured: the four stores, each through this verifier and c2patool with
  the same settings; the broken signature proven broken by checking it
  directly; `composer check` exit 0, 353 tests.
- Reasoned: the inventory's rows that need no file (optional fields the
  specification allows to be absent).
