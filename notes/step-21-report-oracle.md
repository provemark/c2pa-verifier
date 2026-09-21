# Step 21 — c2patool's words for three broken files, before SPEC-010 is approved

*2026-09-21.* SPEC-010 compares not only codes but urls with the oracle.
The four fixtures' c2patool JSON was recorded in step 14; three variants
were not. Three runs, saved as they came, under
`tests/Fixtures/c2patool/variants/`. No code.

## Measured

- `claim-title-changed`, `signature-changed`: `Invalid`;
  `validation_status` holds `signingCredential.untrusted` and
  `claimSignature.mismatch`, both with url
  `self#jumbf=/c2pa/urn:c2pa:488bf983-…/c2pa.signature` — the absolute
  form SPEC-010 builds. AC2 can compare urls exactly.
- `json-broken`: `Invalid`; `assertion.json.invalid` with url
  **`stds.schema-org.CreativeWork`** — a bare label. Two lines further,
  the `assertion.hashedURI.mismatch` for the *same* box carries the
  absolute `self#jumbf=/c2pa/contentauth:urn:uuid:…/c2pa.assertions/stds.schema-org.CreativeWork`.
  c2patool is inconsistent with itself here. SPEC-010's url is the
  absolute one, and AC7 now compares the code only, with the reason.
  The same file also gets `assertion.required.missing` ("Failed to load
  manifest") and, because the Adobe store was carried in the PNG,
  `assertion.dataHash.mismatch` — expected, and not SPEC-010's concern.

## What this settles

SPEC-010's blocking open question is resolved; AC7 was corrected by the
measurement before a line of code depended on it — the third time this
project's "measure before approval" caught a criterion that would have
been wrong (SPEC-001 AC7's fixture, SPEC-009 AC10's DER length, now this
url).

## Reasoned, not measured

- That the bare label is an oversight in c2pa-rs's JSON-assertion error
  path rather than a convention: from the two urls side by side, not from
  reading the source.
