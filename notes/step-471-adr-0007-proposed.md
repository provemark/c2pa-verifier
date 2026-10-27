# Step 471 — ADR-0007 proposed: revocation data from an injected source

*2026-10-27.* Docs only; nothing in `src/` or `tests/` changed. The ADR is
`proposed`, not yet decided.

## Why

Candidate C3 of the revocation proposal would let a caller hand the
verifier OCSP responses fetched online. That touches a fixed design rule,
*no network in the verification path*: the letter survives (the package
still opens no connection), but data from outside could change a verdict.
A shift like that belongs in an ADR before any spec.

## Measured

`c2patool` 0.28.3 (`c2pa` 0.91.6) on the eight files under
`tests/Fixtures/writers/`, with the programme's trust lists as
`trust_anchors`, `verify.ocsp_fetch` off, on, and absent (live network
requests to the CAs' responders, with Maurice's consent):

| file | off / absent | on |
|---|---|---|
| Google Pixel 10 | `Trusted`, `ocsp.skipped` | `Trusted`, `ocsp.notRevoked` (about 3 s) |
| Microsoft Bing | `Invalid`, `ocsp.skipped` | `Invalid`, `ocsp.inaccessible`, plus a second `timeStamp.validated` and a `timeStamp.untrusted` |
| OpenAI | `Trusted`, stapled `ocsp.notRevoked` | the same |
| TrustNXT | `Valid`, `ocsp.skipped` | **`Invalid`**: `ocsp.inaccessible`, a second time-stamp validation, `timeStamp.untrusted`, and `signingCredential.invalid` ("certificate missing mandatory EKU") |
| Adobe (2), Amazon | not on those lists; no `ocsp.*` code | the same |

This verifier, with the same lists, gives the off-column for Google,
TrustNXT and Bing.

## Read

In the `c2pa` 0.91.1 source: `verify.ocsp_fetch` defaults to `false`
(`settings/mod.rs`); a stapled response that proves something ends the
check; with fetching on, each CA of the chain is queried against its own
AIA responder before the signer (`crypto/cose/ocsp.rs`,
`fetch_and_check_ocsp_response`); no answer for the signer gives
`signingCredential.ocsp.inaccessible`, informational.

## What the ADR proposes

An interface in `Trust`, default an implementation that returns nothing,
so every report is byte-identical without a source; no network code in
the package; a fetched response marked as such in the report and the
SPEC-085 evidence; the same tests as a stapled response; deterministic
tests with recorded responses; and an online lookup changes revocation
statuses only. Two questions are left open for Maurice: whether the
opt-in path may skip `c2patool`'s side effect (TrustNXT: `Invalid` there
with nothing fetched), and where the source is passed.
