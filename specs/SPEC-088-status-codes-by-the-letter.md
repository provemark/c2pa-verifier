# SPEC-088: Three status codes brought to the letter of C2PA 2.4

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-27                      |
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
   letter's code, since a silent drop tells the reader nothing; recorded in
   `docs/comparison.md` as a code `c2patool` does not give.
   *Status 2026-10-27 (step 480):* decided by Maurice: the proposal.

## Amendments

1. **2026-10-27, step 485, found by the review of step 482; measured** *(approved by Maurice van Loon, 2026-10-27)* —
   F1's *Behavior* made every fault of the CBOR decoder
   `assertion.cbor.invalid`, "the item and depth limits included". A limit
   of this decoder (items, depth, the store-wide budget of SPEC-043) or an
   item outside the subset it decodes (an integer or tag above 2^63 − 1, a
   simple value other than false, true and null, a map key that is not an
   integer or text, a text key that would collide with an integer) says
   nothing about the CBOR: it may be valid. Reporting
   `assertion.cbor.invalid` there tells a caller the file is malformed
   when only this verifier stops. Measured with six new variants of
   `bin/make-claim-variants.php` (the actions assertion's content
   overwritten at its start, same length): `c2patool` 0.28.3 decodes depth
   33, `undefined` and 2^64 − 1 as CBOR and refuses them only against the
   actions schema ("invalid type"); `c2pa-rs` 0.91.1 logs
   `assertion.cbor.invalid` only when the bytes do not decode as a generic
   CBOR value (read: `store.rs`, `get_assertion_from_jumbf_store`).

   Now an assertion's CBOR is `assertion.cbor.invalid` only when it is not
   well-formed or not valid (RFC 8949 §5.3): a reserved or misplaced byte,
   truncation, an indefinite length or chunk where none may be, bytes
   after the item, text that is not UTF-8, a duplicate map key. A limit or
   an item outside the subset stays `general.error`, as before SPEC-088,
   its explanation reading "CBOR beyond what this verifier decodes". The
   store is refused in every case, as before. `CborException::LIMIT`
   (internal) marks the second kind. The claim keeps
   `claim.cbor.invalid` for every fault (SPEC-013); a guard pins it. No
   contract symbol changes.

   **Weight C: a code, no verdict.**

2. **2026-10-28, step 491, found by the review of steps 483–489; measured** *(approved by Maurice van Loon, 2026-10-28)* —
   A decoder limit can be met before the end of the input is: an array
   declaring 2^64 − 1 items in nine bytes, or a million items followed by
   one byte, was `general.error` (a limit), while a byte string declaring
   2^63 bytes was `assertion.cbor.invalid` (truncation). Every item takes
   at least one byte, every map entry two, so a definite count that the
   remaining bytes cannot hold is truncation: the CBOR is not well-formed,
   whatever this decoder's limits. Such a count is now
   `assertion.cbor.invalid`, checked before the item limit.

   Where the limit is met first and the input could still go on — the
   depth (33 nested arrays, then the bytes stop) or a map key outside the
   subset (a one-entry map whose byte-string key is followed by nothing;
   the reviewer's two-entry map in three bytes is now truncation, by the
   count) — the first fault met
   decides, as everywhere in the decoder: reading past a limit to look
   for a later fault is what the limit exists to prevent. These stay
   `general.error`. The store is refused in every case; no verdict
   changes. Measured with the reviewer's inputs (scratch, not committed):
   four classified as a limit before; the two count cases and the
   two-entry map are invalid now, the depth case stays a limit. The
   message names where the bytes run out, as SPEC-006 AC10 asks.
   Tests: `StatusCodesByTheLetterTest.php`, group `SPEC-088`; the
   dataset row "a declared count above the limit" now carries the items
   it declares.

   **Weight C: a code, no verdict.**

## Traceability

All in `tests/Unit/Report/StatusCodesByTheLetterTest.php`, group `SPEC-088`. Before the implementation 7 of 11 tests
failed (AC1, AC2, AC3's codes, AC6); 4 passed already: AC4's three guards
that other faults stay `timeStamp.malformed`, and AC3's check that the
explanation names what is missing — both true before this spec. One test
for a token offered by a time-stamp assertion was added after the build,
when mutation M6 survived. Four earlier tests followed, as amendments:
SPEC-017 amendment 11, SPEC-062 amendment 4, SPEC-084 amendment 2.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | AC1: an assertion that is not well-formed CBOR is assertion.cbor.invalid (reserved, break) | src/Manifest/Manifest.php :: assertionData(); src/Report/StatusCode.php :: AssertionCborInvalid |
| AC2                  | AC2: a SHA-1 messageImprint is timeStamp.untrusted (both probes); scope: a time-stamp assertion's token with a SHA-1 imprint is timeStamp.untrusted too | src/Timestamp/TstInfo.php; src/Timestamp/TimestampException.php :: UNTRUSTED, statusCode(); src/Timestamp/TimestampCheck.php :: forManifest(), checkHeader() |
| AC3                  | AC3: a token without its TSA certificate is timeStamp.untrusted (no certificates, the intermediate only); AC3: the explanation names what is missing | src/Timestamp/SignedData.php; src/Timestamp/TimestampCheck.php :: judge() |
| AC4                  | AC4: other faults of a token stay timeStamp.malformed (genTime, two tokens, both headers) | src/Timestamp/TimestampException.php :: statusCode() |
| AC5                  | 3,042 corpus runs before (worktree at `1ad620d`, own `vendor/` copy) and after: 18 differ, the six files named, only in code and explanation, every `validation_state` equal (`notes/step-481-status-codes-by-the-letter.md`) | — |
| AC6                  | AC6: assertion.cbor.invalid is a failure code; `ApiSurfaceTest` (209) | src/Report/StatusCode.php |
| Amendment 1          | amendment 1: an assertion that is not valid CBOR is assertion.cbor.invalid; one beyond this decoder is general.error (eight probes); amendment 1: the decoder tells a limit or an item outside its subset from CBOR that is not valid (ten inputs); amendment 1: the claim keeps claim.cbor.invalid (added after mutation M2 survived). Before the build 9 failed, the cases that stay `assertion.cbor.invalid` passed; 3,102 corpus runs before (`fa6914d`) and after: only the three limit probes differ, in code and explanation | src/Cbor/CborException.php :: LIMIT; src/Cbor/CborDecoder.php; src/Cbor/CborBudget.php; src/Manifest/Manifest.php :: decodeCbor() |
| Amendment 2          | amendment 1: the decoder tells a limit or an item outside its subset from CBOR that is not valid (rows for a count the bytes cannot hold, and a count above the limit with its items); amendment 2: a count the remaining bytes cannot hold is not well-formed | src/Cbor/CborDecoder.php :: array(), map(), holds() |
