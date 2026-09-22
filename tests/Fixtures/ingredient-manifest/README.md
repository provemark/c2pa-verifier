# The ingredient-manifest variants (step 56, SPEC-021)

Three signed variants for the rules that decide whether an ingredient
manifest can hide a fault. `bin/make-ingredient-manifest-variants.php
<scratch>` builds them and re-signs the *active* claim with a throw-away
P-256 hierarchy (keys outside the repository, deleted at the end of the
run; the public root in `throw-away-root.pem` and, together with the C2PA
test anchors, in `throw-away-root.settings.json` — the ingredient
manifests of `CACA` are signed by the test hierarchy, and only the fault
under test may make a variant `Invalid`). Regenerating changes the root
and every signature, so the c2patool JSON under
`../c2patool/ingredient-manifest/` is regenerated with them.

| variant | what it is | c2patool 0.27.22 (with the settings) |
|---|---|---|
| `ingredient-signature-broken` | `c2pa-rs/CACA.jpg`: one byte of the **ingredient** manifest's COSE signature flipped, then the referring `activeManifest` hash and the active claim's hashed URI recomputed and the active claim re-signed — so the ingredient's **box hash still matches** and only its signature is wrong | `Invalid`; the delta holds `ingredient.manifest.validated` *and* `claimSignature.mismatch` (plus `signingCredential.trusted`, `timeStamp.mismatch`) — a matching box hash does not stand in for validating the manifest |
| `records-active-fault` | the PNG fixture with an ingredient assertion whose `validationStatus` records two faults, both with urls inside the **active** manifest (`claimSignature.mismatch` on its signature box, `ingredient.unknownProvenance` on the assertion itself), and the active signature then broken | `Invalid` with `claimSignature.mismatch`, and the recorded `ingredient.unknownProvenance` still in the delta: a status whose url names the active manifest is never dropped (CAI-12751) |
| `redacted` | the PNG fixture whose claim carries `redacted_assertions` naming its own actions assertion | `Invalid`: `assertion.selfRedacted`, `assertion.action.redacted`. Here: `general.error` — redactions are refused until a fixture with a real redaction exists (SPEC-021) |

The JPEG variant is written back into its APP11 segments piece by piece
(CI `JP`, box instance, packet sequence, LBox, TBox, then the data); the
script asserts that putting the *unchanged* store back gives the original
file byte for byte before it writes the edited one.
