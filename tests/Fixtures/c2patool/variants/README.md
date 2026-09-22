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

Added 2026-09-21 (step 24, for SPEC-011), all `../../binding/<name>.png`:

| file | what c2patool says |
|---|---|
| `hashed-uri-changed.json` | `Invalid`; `assertion.hashedURI.mismatch` with url `…/c2pa.assertions/c2pa.hash.data`, explanation `hash does not match assertion data: self#jumbf=c2pa.assertions/c2pa.hash.data`; two `hashedURI.match`; `claimSignature.mismatch`, `signingCredential.untrusted`; `dataHash.match` |
| `hashed-uris-two-changed.json` | `Invalid`; `assertion.hashedURI.mismatch` for `c2pa.hash.data` **and** `c2pa.thumbnail.claim`, one `match` |
| `hashed-uri-truncated.json` | `Invalid`; `assertion.hashedURI.mismatch` for `c2pa.hash.data`; `dataHash.mismatch` (the store is one byte shorter, the exclusion no longer fits) |
| `uri-alg-sha384.json` | `Invalid` (signature, data hash); three `assertion.hashedURI.match` — the entry-level `alg` honoured |
| `claim-alg-sha1.json` | `Invalid`; three `assertion.hashedURI.mismatch`, no `algorithm.unsupported` |
| `claim-redacted.json` | `Invalid`; `assertion.action.redacted` with url `…/c2pa.assertions/c2pa.actions.v2`, explanation `redaction of action assertions disallowed`; three `hashedURI.match` |

No JSON for `assertion-duplicate-label`, `assertion-undeclared-unknown-uuid`
(`Error: assertion missing: url = …`) and `claim-alg-missing` (`Error:
unknown algorithm`): c2patool exits 1 without a report.

Added 2026-09-21 (step 26, for SPEC-012), all `../../binding/<name>.png`:

| file | what c2patool says |
|---|---|
| `exclusion-extra.json` | `Invalid` (signature only); `assertion.dataHash.match` with url `…/c2pa.assertions/c2pa.hash.data`, explanation `data hash valid`; the informational `assertion.dataHash.additionalExclusionsPresent`, same url, `extra data hash exclusions found`; three `hashedURI.match` |
| `exclusions-unsorted.json` | the same |
| `hash-as-text.json` | `Invalid`; `assertion.hashedURI.mismatch` + `assertion.dataHash.mismatch` on `c2pa.hash.data` — c2patool reads a text string as the hash |
| `exclusions-too-many.json` | `Invalid`; `assertion.hashedURI.mismatch` + `assertion.dataHash.mismatch` + the informational — 1,025 ranges accepted |
| `alg-missing.json` | `Invalid` (signature only); `assertion.dataHash.match` — the claim's `alg` applied |
| `alg-sha384.json` | `Invalid` (signature only); `assertion.dataHash.match` — the assertion's own `alg` applied |
| `hard-bindings-two.json` | `Invalid`; `assertion.multipleHardBindings` with url **`self#jumbf=/c2pa/urn:c2pa:488bf983-…`** (the manifest), explanation `claim has multiple data bindings`; two `dataHash.match`, four `hashedURI.match` |

No JSON for `exclusions-not-list`, `exclusion-start-negative`,
`exclusion-length-text` (`Error: could not decode assertion c2pa.hash.data`),
`hard-binding-missing` (`Error: claim missing hard binding`) and
`hard-binding-bmff` (`Error: could not decode assertion c2pa.hash.bmff.v2`):
c2patool exits 1 without a report.

Step 47: `no-hard-binding` (`../../binding/no-hard-binding.png`, a signed
manifest without any hard binding) — `Error: claim missing hard binding`,
exit 1, no report, with and without `no-hard-binding-root.settings.json`.

