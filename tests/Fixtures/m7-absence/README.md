# The M7 absence audit (step 60)

The method of step 48 — "for every rule of the form *the verifier does X
when Y is present*, a **signed** store in which Y is absent" — applied to
what SPEC-020, SPEC-021 and SPEC-022 added.

The store these need did not exist anywhere: a two-manifest store this
project made itself. `bin/make-m7-absence-variants.php <scratch>` builds
it from the PNG fixture — the manifest box copied under a second label
(and the label patched in the claim's absolute signature URI, which is
why the copy is re-signed), the copy placed **first** so that the active
manifest is the last in the store (C2PA 2.4 §11.1.4.2), a v3 ingredient
assertion added to the active claim naming the copy's manifest box and
signature box, the hard binding re-bound, and both claims re-signed with
a throw-away P-256 hierarchy (keys outside the repository, deleted at the
end of the run). The copy carries no thumbnail: without that the store
would pass 65535 bytes and its exclusion could no longer be a two-byte
CBOR integer, which is a different edit than the one under test.

| variant | what is absent | c2patool 0.27.22 | here |
|---|---|---|---|
| `two-manifests` | nothing — the control | `Trusted` | `Trusted`, the ingredient validated (`ingredient.manifest.validated`, its own signature, trust and hashed URIs in the delta) |
| `ingredient-no-actions` | the **ingredient manifest's** actions assertion | `Invalid`: `assertion.action.malformed` | the same, scoped to the assertion that named the ingredient |
| `unreferenced-broken` | the *reference*: a second manifest nobody names, whose signature is broken | `Trusted` | `Trusted` — §15.11.3.3 says a validator should ignore manifests it does not reach; both render it under `manifests` and neither validates it |
| `no-claim-signature` | the ingredient assertion's `claimSignature` hashed URI | `Trusted` | `Trusted` — §18.16.12.3 says both URIs shall be stored, and neither implementation refuses a missing one |

`both-roots.settings.json` carries the throw-away root and the fixture's
own anchors, so that only the absence under test can make a variant
`Invalid`. c2patool's JSON for each file is in
`../c2patool/m7-absence/`; the tests are
`tests/Unit/Verifier/M7AbsenceTest.php`.
