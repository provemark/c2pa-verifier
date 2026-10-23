# Step 378 — SPEC-077 drafted: the two trust lists as input

*2026-10-23.* The harness's two list inputs, after Maurice agreed with
the outline and its five choices. `specs/SPEC-077-trust-lists-as-input.md`
is the draft. No repository code. Steps 376–377 were pushed first.

## What the draft says

`--trust-list <pem>` and `--tsa-trust-list <pem>` on `bin/c2pa-verify`,
and `TrustSettings::fromTrustLists()` for a library caller: one
`"manifest"` and one `"tsa"` entry (SPEC-031), exclusive with
`--settings`, an empty list refused. With `--at` and `--crjson` that is
the programme's harness, written down in `docs/conformance-harness.md`.

## Measured for the draft

- The official lists at `conformance-public` `da71a72`: 32 signer and 23
  TSA certificates, 16 in both. Swapped, the two fixtures `Trusted` under
  them stay `Trusted`, so they cannot show the separation.
- `timestamp/tsa-matrix/control.png`, whose signer and TSA have their own
  throw-away roots: `Trusted` with both entries; `Valid` with them
  swapped (`signingCredential.untrusted`, `timeStamp.untrusted`);
  `Trusted` with the signer entry alone (`timeStamp.untrusted`); `Valid`
  with the TSA entry alone (`signingCredential.untrusted`,
  `timeStamp.trusted`); still `Trusted` without its `trust_config`.
