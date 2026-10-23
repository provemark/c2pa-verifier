# Step 376 — SPEC-076 drafted: the signature in crJSON

*2026-10-23.* The third crJSON spec of step 370, after Maurice agreed
with its outline and six choices. `specs/SPEC-076-crjson-signature.md`
is the draft. No repository code. Steps 374–375 were pushed first.

## What the draft says

`algorithm` by the crJSON page's names (`ES256` … `PS512`, `Ed25519`,
else `Unknown`); `certificateInfo` of the `x5chain` leaf (lower-case hex
serial, the schema's eight DN keys, times with `Z`); `timeStampInfo`
wherever the judged token can be read, its `genTime` even when it
failed; `{}` for a signature that cannot be read. Then the whole
document against the schema, with only the v1-claim and `{}` exceptions.

## Measured for the draft

- `c2patool` 0.28.2 on 92 manifests: `ps256` 70, `es256` 21, `es384` 1;
  hex serials; `+00:00` times; `timeStampInfo` on 75, 6 of them without
  `timestamp` (5 `timeStamp.mismatch`, 1 `timeStamp.malformed`).
- `c2pa-rs` 0.91.1 `extract_dn_components()`: six DN keys, others
  skipped, the last repeat wins.
- The schema's `distinguishedName`: eight keys, nothing else.
- 193 distinct leaf certificates in the fixtures: only attributes the
  schema names; one repeated attribute.
- C2PA 2.4 §13.2.1, the allowed algorithms.
