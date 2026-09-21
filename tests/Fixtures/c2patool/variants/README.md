# c2patool's JSON for three variants (SPEC-010)

The default output of `c2patool 0.27.22` on three variant PNGs, unchanged,
recorded 2026-09-21 (step 21) so that SPEC-010 can compare codes *and
urls* with the oracle:

| file | variant | what c2patool says |
|---|---|---|
| `claim-title-changed.json` | `../../cose/claim-title-changed.png` | `Invalid`; `validation_status`: `signingCredential.untrusted`, `claimSignature.mismatch`, both with url `self#jumbf=/c2pa/urn:c2pa:488bf983-…/c2pa.signature` |
| `signature-changed.json` | `../../cose/signature-changed.png` | the same two codes and urls |
| `json-broken.json` | `../../claim/json-broken.png` | `Invalid`; `assertion.json.invalid` with url **`stds.schema-org.CreativeWork`** — the bare label, not a JUMBF URI, although the `assertion.hashedURI.mismatch` on the same box two lines down carries the absolute `self#jumbf=/c2pa/contentauth:urn:uuid:…/c2pa.assertions/stds.schema-org.CreativeWork`; also `assertion.required.missing`, `assertion.dataHash.mismatch` (the carrier PNG's hash binding), `signingCredential.untrusted` |

The bare-label url is c2patool's inconsistency; this verifier's url for
`assertion.json.invalid` is the absolute JUMBF URI, the form every other
status uses. SPEC-010 AC7 therefore compares the code, not the url.
