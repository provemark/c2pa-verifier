# Step 332 — Probes for the time-stamp assertion (SPEC-064)

*2026-10-09. Probes and a builder only; no change to `src/`.*

SPEC-064 was approved with its two proposals: a second time-stamp
assertion in one manifest is malformed, and a token from an assertion is
reported with the assertion's url. This step builds the probes:
`bin/make-timestamp-assertion-variants.php` and ten fixtures under
`tests/Fixtures/timestamp/assertion/`; the README there has the table.

## How

A parent is signed by `c2patool` 0.28.1 with a signer valid for two and
a half minutes and no header timestamp. The child is signed with that
parent as its parent (or, for `update-*`, as an update manifest on the
parent itself). It carries a `c2pa.time-stamp` assertion with a
placeholder of the token's length. The placeholder becomes the token, as
a byte string, and the child's claim is re-hashed and signed again. The
token comes from a throw-away TSA through `openssl ts`. The builder waits
until the parent's signer has expired, then has both `c2patool` versions
judge each probe.

`c2pa-rs` writes the token over the parent's COSE `signature` field itself
(`assertions/timestamp.rs`, `refresh_timestamp`; `verify_time_stamp`
hashes that field). §18.18.3 describes a Sig_structure whose payload is
that field. The probes carry both: `raw`, and `structure` (the bytes a
`sigTst2` header token covers).

## What was measured

| probe | 0.28.1 | 0.27.22 | here |
|---|---|---|---|
| `raw`, `structure`, `two-assertions`, `update-raw` | `Trusted` | `Invalid` (parent `expired`) | `Invalid` (parent `expired`) |
| `other-label`, `wrong-data`, `untrusted`, `update-other-label` | `Trusted` | `Invalid` | `Invalid` |
| `array` | error: timestamp assertion malformed | error | `Invalid` |
| `header-wins` | `Trusted` | `Valid` | `Trusted` |

The finding: `c2patool` 0.28.1 does not judge an earlier manifest's
signer again once the ingredient recorded its validation at signing. It
is `Trusted` with a valid token, a useless one and none at all, in a
standard and in an update manifest. So 0.28.1 cannot show what a token
changes, and SPEC-064's AC5 difference (`c2pa-rs` letting an assertion's
token replace a passed header token) cannot be seen through it either.
This verifier judges the parent again (SPEC-021, SPEC-022), which makes
it stricter than 0.28.1 on every probe but `header-wins` today. 0.27.22
reports the expired parent on every probe.

This changes what SPEC-064 can say about the oracle (AC5, AC6). The rules
stay as approved. An amendment follows with the implementation.
