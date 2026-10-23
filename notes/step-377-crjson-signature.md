# Step 377 — SPEC-076: the signature in crJSON, built

*2026-10-23.* Approved unchanged, built tests-first, one amendment on
the way (approved). With it, the crJSON output is complete: every
document passes the whole crJSON schema but for errors inside v1 claims
(SPEC-075 amendment 1) and `{}` for a signature that cannot be read.

## What changed

- `CrJsonDocument::signature()`: `algorithm` by its C2PA name,
  `certificateInfo` of the `x5chain` leaf (lower-case hex serial, the
  schema's eight DN keys with a repeat's last value, times with `Z`),
  `timeStampInfo` wherever the judged token can be read.
- `TimestampCheck::readToken()`: the header's one token, else a
  `c2pa.time-stamp` assertion's, read but not judged.
- `Certificate::$serialHex`.
- SPEC-075's AC7 test no longer excepts the signature.
- More SPEC-075 test helpers moved to `tests/Shared.php`.

## Measured

- Against `c2patool` 0.28.2 on 92 manifests: algorithms equal once
  upper-cased (`PS256` 70, `ES256` 21, `ES384` 1); certificate info equal
  with times as instants and `E` removed where `c2pa-rs` drops it (11
  names); `timeStampInfo` on the same 74 manifests, plus two by name
  (amendment 1): `c2pa-rs/CA_ct.jpg` (a header token this verifier cannot
  read, `timeStamp.malformed` in `c2patool` too) and
  `c2pa-rs/update_manifest.jpg` (a `c2pa.time-stamp` assertion's token,
  which `c2pa-rs` does not use here). Times equal on 69; added on 5.
- Red 8 of 12, then green; five mutations caught.
- The corpus before and after: 0 of 1,842 runs moved.
- `composer check`: 1,194 passed, 1 skipped.
