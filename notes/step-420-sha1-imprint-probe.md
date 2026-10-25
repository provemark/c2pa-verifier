# Step 420 — A time-stamp with a SHA-1 imprint (SPEC-062 amendment 3)

*2026-10-25.* The first of three probes for the `c2pa` changes between
`c2patool` 0.28.2 and 0.28.3 that the corpus does not cover (step 419).
Maurice approved the probe ("akkoord, begin met probe 1") and then the
amendment ("akkoord, leg vast en push").

## Why this one first

`c2pa-rs` PR 2830 (in `c2pa` 0.91.4): *"`verify_time_stamp` accepted an RFC
3161 token whose `messageImprint` used SHA-1. … An expired claim credential
was then evaluated at the attested time and reported as `Trusted`."* C2PA
2.4 §15.8.2: an imprint hash algorithm outside §13.1 gives
`timeStamp.untrusted` and the time-stamp is ignored. SHA-1 is not in
§13.1. Read before the probe: `TstInfo::DIGEST_LENGTHS` knows only
SHA-256/384/512, so a SHA-1 imprint makes the token `timeStamp.malformed`
and it is ignored.

## What was built

`bin/make-tsa-matrix.php` gains an `imprint` option (the query is made with
`openssl ts -query -sha1`; the TSA configuration allows `sha1`) and two
probes: `imprint-sha1` (a valid signer) and `expired-signer-imprint-sha1`
(a signer valid for 2.5 minutes, judged after it expired). It also labels
each `c2patool` by the version it reports instead of by position, so a
scratch run with other versions prints the right column names. The keys
lived in a scratch directory and were deleted.

## Measured

A scratch run of the whole matrix with 0.28.2 and 0.28.3, then the two
probes as fixtures with 0.28.1 and 0.27.22, then 0.28.3 on the fixtures:

| probe | 0.27.22 | 0.28.1 | 0.28.2 | 0.28.3 | here |
|---|---|---|---|---|---|
| `imprint-sha1` | `Valid` | `Trusted`, `timeStamp.trusted` | `Trusted`, `timeStamp.trusted` | `Trusted`, `timeStamp.untrusted` | `Trusted`, `timeStamp.malformed` |
| `expired-signer-imprint-sha1` | `Invalid` | **`Trusted`**, `timeStamp.trusted` | **`Trusted`**, `timeStamp.trusted` | `Invalid`, `timeStamp.untrusted`, `signingCredential.expired` | `Invalid`, `timeStamp.malformed`, `signingCredential.expired` |

- `openssl ts -verify` accepts both tokens: they are well-formed and
  signed; only the imprint is SHA-1.
- 0.27.22 trusts no TSA in this matrix (it does not read `trust.anchors`),
  so its answers say nothing about the imprint.
- The other 31 probes give the same answers in 0.28.2 and 0.28.3.
- Without settings, here: `Valid` and `Invalid`, explanation
  "messageImprint: hash algorithm 1.3.14.3.2.26 is not supported".
- A first scratch run used the unedited generator (the edit had not been
  written to disk); it showed no new probes and was repeated.

## Tests

The alarm compares with 0.28.1, which has the hole, so
`expired-signer-imprint-sha1` joins `SPEC062_STRICTER` and `imprint-sha1`
joins `SPEC062_CODES_DIFFER`. AC7 counts 33. AC8 (new) pins this
verifier's answer and 0.28.3's.

Red first, by mutation: with SHA-1 added to `TstInfo::DIGEST_LENGTHS` and
`digestName()`, `expired-signer-imprint-sha1` becomes `Trusted` and three
tests fail (AC1–3, AC4, AC8). Restored, all six pass.

## What it means (reasoned)

No code changes: the verifier already ignored the token. The only
difference with 0.28.3 is the code, `malformed` where §15.8.2 and 0.28.3
say `untrusted` (P05-9 in `docs/reading-c2pa-2.4.md`); the verdict is the
same.

## Next

Probe 2: an Ed25519 certificate with algorithm parameters (`c2pa` 0.91.3).
