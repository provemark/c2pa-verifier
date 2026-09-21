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

Added 2026-09-21 (step 23, for SPEC-011/012):

| file | variant | what c2patool says |
|---|---|---|
| `pixel-changed.json` | `../../binding/pixel-changed.png` | `Invalid`; `assertion.dataHash.mismatch` with url `self#jumbf=/c2pa/urn:c2pa:488bf983-…/c2pa.assertions/c2pa.hash.data` and explanation `asset hash error, name: jumbf manifest, error: hash verification( Hashes do not match )`; under `success` three `assertion.hashedURI.match` (hash.data, thumbnail.claim, actions.v2), each with the assertion's absolute URI; `signingCredential.untrusted` |
| `exclusions-overlap.json` | `../../binding/exclusions-overlap.png` | `Invalid`; the only recorded `informational` entry so far: `assertion.dataHash.additionalExclusionsPresent`, explanation `extra data hash exclusions found` |
