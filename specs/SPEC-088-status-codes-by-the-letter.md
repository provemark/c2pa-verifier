# SPEC-088: Three status codes brought to the letter of C2PA 2.4

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Group F of the 2.4 reading (`docs/reading-c2pa-2.4.md`, step 426) holds
eight rows where this verifier reaches the right verdict under another
status code than the specification names. None can give a wrong `Valid`;
each is a less precise reason for a caller that filters on codes. This
spec takes the three where the letter's code is also what `c2pa-rs`
reports, or the nearest it reports, and records why the other five stay.

Measured on 2026-10-27 (the verifier at `0f96cc1`, `c2patool` 0.28.3 and
0.28.1, probes made in a scratch directory with throw-away keys, deleted):

| row | spec | this verifier | `c2patool` 0.28.3 | probe |
|---|---|---|---|---|
| F1 §15.10.3.1 | an assertion that is not well-formed CBOR: `assertion.cbor.invalid` | `general.error` | no report: the file is refused with "could not decode assertion c2pa.actions.v2 … Invalid CBOR value"; `c2pa-rs` logs that failure as `assertion.cbor.invalid` before it stops (read: `store.rs`, `get_assertion_from_jumbf_store`) | `fixture-signed.png` with the actions assertion's first CBOR byte made reserved (additional information 28), and made a top-level break (`0xFF`) |
| F3 §15.8.2 | a `messageImprint` hash algorithm outside §13.1: `timeStamp.untrusted`, the token ignored | `timeStamp.malformed`, ignored | `timeStamp.untrusted` (0.28.1: `timeStamp.trusted`, the hole of step 420) | `timestamp/tsa-matrix/imprint-sha1.png`, `expired-signer-imprint-sha1.png` |
| F4 §15.8.2 | the TSA certificate not in `certificates`: `timeStamp.untrusted`, ignored | `timeStamp.malformed`, ignored | no certificates at all: `timeStamp.untrusted`; the intermediate only, not the TSA certificate: no time-stamp code at all (the token is dropped without a word) | the TSA matrix's control, re-signed with `openssl cms -nocerts`, with and without the intermediate |

The verdict is the same in every probe, here and in `c2patool`: the file
`Invalid` (F1, here; refused there), the token ignored (F3, F4).

The five that stay:

| row | why it stays |
|---|---|
| F2 §15.8.2: a CMS signature that does not verify is `timeStamp.mismatch` | `timeStamp.untrusted` here and in `c2patool` 0.28.3 and 0.28.1 (probe: the control's signature with one bit flipped) |
| F5 §15.4.3: a `hashed_ext_uri` without `alg` is `algorithm.unsupported` | `assertion.external-reference.malformed` here; nothing is fetched, so no `alg` is ever used, and `c2pa-rs` emits neither |
| F6 §15.4.4: a BMFF hash with an algorithm outside §13.1 is `algorithm.unsupported` | `assertion.bmffHash.mismatch` here and in `c2patool` 0.28.3 and 0.28.1 (probe: a BMFF hash's `alg` made `sha224`, the claim re-hashed and re-signed) |
| F7 §15.10.3.3: a `hashed_uri` without a destination is `hashedURI.missing` | `assertion.missing` here; `c2pa-rs` 0.91.1 emits `hashedURI.missing` nowhere |
| F8 §15.2.2: the codes the enum does not hold | the sum of the other rows and of features not read (the list under the §15.2.2 table) |

Over the corpus (1,022 files, no settings, `trust/full.settings.json` and
the programme's lists) only the two SHA-1 imprint probes carry one of the
three cases; no file carries an assertion that is not CBOR or a token
without its TSA certificate.

## Scope

**In scope**

- F1, F3 and F4 report the letter's code. One new contract symbol:
  `StatusCode::AssertionCborInvalid`.
- Probes for F1 and F4 as fixtures, with `c2patool`'s answers stored.
- `docs/comparison.md`, `docs/conformance.md` and
  `docs/reading-c2pa-2.4.md`: the rows moved, and the five that stay with
  their reason.

**Out of scope**

- F2, F5, F6, F7, F8 (above).
- `claimSignature.outsideValidity`. §15.8.2's prose names it; the §15.2.2
  table has not held it since 2.2, and `c2pa-rs` reports
  `signingCredential.expired`, as this verifier does (step 453).
- Any change to a verdict, to which token is used, or to what is refused.

## Behavior

- **F1.** An assertion whose CBOR content box does not decode is
  `assertion.cbor.invalid` (failure), at the same url and with the same
  explanation, where it was `general.error`; the store is refused as
  before. It covers every fault of the CBOR decoder, the item and depth
  limits included, as `claim.cbor.invalid` does for the claim (SPEC-013).
  A JSON assertion's limit (SPEC-045) stays `general.error`; bad JSON stays
  `assertion.json.invalid`.
- **F3.** A token whose `messageImprint` names a hash algorithm other than
  SHA-256, SHA-384 or SHA-512 is `timeStamp.untrusted`, the explanation
  naming the algorithm, where it was `timeStamp.malformed`. Every other
  fault of reading the TSTInfo stays `timeStamp.malformed`.
- **F4.** A token whose `certificates` hold no certificate matching the
  SignerInfo's `sid` — none at all, or only others — is
  `timeStamp.untrusted`, where it was `timeStamp.malformed`. A certificate
  that matches but cannot be read stays `timeStamp.malformed`.
- **Unchanged.** In F3 and F4 the token is ignored, as before: no time is
  taken from it, and a signer that only it could keep valid is judged at
  the validation time. The evidence (SPEC-084) keeps its entries; only the
  code in them follows.

### Acceptance criteria

- **AC1** — Given the two F1 probes, when verified, then each reports
  exactly `assertion.cbor.invalid` at the actions assertion's url, with an
  explanation naming the CBOR fault, and the state is `Invalid`; and
  `c2patool` 0.28.3's stored answer is a refusal naming the actions
  assertion.
- **AC2** — Given the two SHA-1 imprint probes under their settings, then
  each reports `timeStamp.untrusted` naming `1.3.14.3.2.26`, not
  `timeStamp.malformed`; `imprint-sha1` stays `Trusted` and
  `expired-signer-imprint-sha1` stays `Invalid` with
  `signingCredential.expired` — the codes 0.28.3 gives, beside its
  `signingCredential.untrusted`.
- **AC3** — Given the F4 probes under their settings, then each reports
  `timeStamp.untrusted` naming the `sid`, and stays `Trusted`; the stored
  answers of `c2patool` 0.28.3 are `timeStamp.untrusted` (no
  certificates) and no time-stamp code (the intermediate only).
- **AC4** — A token whose matching certificate is not a readable
  certificate, and a TSTInfo with another version than 1, still report
  `timeStamp.malformed`.
- **AC5** — Over the corpus under the three settings, only the two SHA-1
  imprint probes and the new probes differ from a run before the change
  (a worktree at the commit before the build, with its own `vendor/`
  copy), and in each only the code and explanation of the status named
  above; every `validation_state` is the same.
- **AC6** — `StatusCode::AssertionCborInvalid` is a failure code with the
  value `assertion.cbor.invalid`; the public surface grows by that one
  symbol (209).

## References

- Specification: C2PA 2.4 §15.2.2, §15.4.3, §15.4.4, §15.8.2,
  §15.10.3.1, §15.10.3.3; RFC 3161 §2.4.2; RFC 5652 §5.3; RFC 8949 §3.
- Decision: ADR-0005 (stricter than `c2patool` only where it protects a
  verdict; here no verdict moves).
- Measured: the probes above, with `c2patool` 0.28.3 and 0.28.1.
- Read: `c2pa` 0.91.1 `store.rs` (`ASSERTION_CBOR_INVALID`),
  `crypto/time_stamp/verify.rs`.
- Earlier specs whose pinned codes follow, to be named as amendments at the
  build: SPEC-062 (the SHA-1 imprint, amendment 3), and any test that pins
  `general.error` for an assertion's CBOR or `timeStamp.malformed` for a
  missing TSA certificate.

## API sketch

```php
// Report\StatusCode
case AssertionCborInvalid = 'assertion.cbor.invalid';   // failure
```

## Open questions

1. F4's second case: `c2patool` drops a token whose TSA certificate is
   missing without any code. Proposed: `timeStamp.untrusted` here, the
   letter's code, since a silent drop tells the reader nothing; recorded
   in `docs/comparison.md` as a code `c2patool` does not give.

## Amendments

None yet.

## Traceability

To be filled at the build.
