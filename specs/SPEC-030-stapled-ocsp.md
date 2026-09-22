# SPEC-030: stapled OCSP — revocation without the network

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-22                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Step 90 laid all 111 applicable conformance obligations next to this
verifier (`docs/conformance.md`). Four of its 22 gaps have one cause:
**OCSP responses stapled into the manifest are not read.**

Revocation has been out of scope since SPEC-014, with a reason that is
still right: an OCSP query is a network call, and there is no network in
the verification path. That reasoning covers the *online* obligations and
they stay out of scope here too. It does not cover a response the signer
put **inside the file**, which needs no network at all.

This is measured, not argued. `tests/Fixtures/c2pa-rs/ocsp.jpg`:

```
rVals: ocspVals[0] = 2264 bytes of DER
$ openssl ocsp -respin ocsp.der -resp_text -noverify
  Responder Id: 17299372FFA7FB5832FFB2E08EB0EDA85006FAAD
  Produced At:  Aug 11 21:51:18 2025 GMT
  Cert Status:  good
  This Update:  Aug 11 21:51:18 2025 GMT
  Next Update:  Aug 18 21:51:18 2025 GMT
  Responder:    CN=Adobe Product Services G3 OCSP Responder 2025-07-15…
```

`CoseSign1` already parses that header — it lands in
`$otherHeaders['rVals']` — and nothing reads it. On this file the answer is
`good`, so no verdict in this repository is wrong today. A response saying
`revoked` would be ignored exactly as completely, and this verifier would
answer `signingCredential.trusted` about a certificate whose own manifest
carries the evidence against it. That is the one gap in the conformance
table that can produce a wrong `Trusted`.

The second half of the same problem is quieter and is fixed by the same
work: a validator that does not check revocation **must say so**
(`signingCredential.ocsp.skipped`). This one says nothing, in a project
where `checksPerformed` exists precisely so a caller can see what ran. A
skipped check that leaves no trace is the shape of silence this project
refuses everywhere else.

## The measurement that shapes everything: `rVals` is unprotected

```
protected keys:   1, 33          (alg, x5chain)
unprotected keys: sigTst, rVals, pad
```

The header is **not covered by the signature**. Anyone holding the file can
add an `rVals`, alter the one that is there, or strip it out, and every
other check still passes. Four consequences follow, and together they are
this specification's spine:

1. **A stapled response may never raise trust.** A `good` answer that an
   attacker could have written is worth nothing as evidence of not being
   revoked. `signingCredential.ocsp.notRevoked` may be recorded as an
   informational fact, but it must never turn a `Valid` into a `Trusted`,
   or stand in for a check that was not made.
2. **A response that cannot be verified may never fail the file.** If a
   corrupt `rVals` made a file `Invalid`, editing one unsigned byte would
   be a denial-of-service against any valid asset. Unreadable, unverifiable
   and non-matching responses are all *skipped*, with the reason named.
3. **Only a cryptographically verified response may lower trust.** A
   `revoked` answer counts when, and only when, it verifies under a
   responder this verifier can tie to the signer's own issuer. Then it is
   evidence no attacker could have forged, and the file is `Invalid`.
4. **Absence proves nothing.** A missing `rVals` may mean "never revoked"
   or "the revocation was stripped". That is exactly why the informational
   code matters: what this verifier did not check must be visible.

## Scope

**In scope**

- Reading `rVals` from the COSE unprotected header: a map with an
  `ocspVals` key holding a list of DER-encoded `OCSPResponse` byte strings.
- Parsing RFC 6960 `OCSPResponse`: `responseStatus`, and `responseBytes`
  whose `responseType` is `id-pkix-ocsp-basic` (1.3.6.1.5.5.7.48.1)
  carrying a `BasicOCSPResponse` — `tbsResponseData`, `signatureAlgorithm`,
  `signature`, and the optional `certs`.
- Verifying the response's signature: the responder is either the signer's
  own issuer, or a certificate in `certs` that is issued by that issuer and
  carries the `id-kp-OCSPSigning` EKU (1.3.6.1.5.5.7.3.9 — already a
  constant in `CertificateProfileCheck`).
- Matching a `SingleResponse`'s `CertID` — `issuerNameHash`,
  `issuerKeyHash`, `serialNumber` under the named hash algorithm — to the
  **signer's leaf certificate** and its issuer.
- Applying `certStatus` at the same judged time the certificate profile
  uses: a trusted timestamp's attested time, else now (SPEC-017).
- Four status codes, and the rules above deciding which:
  `signingCredential.ocsp.revoked` (failure),
  `signingCredential.ocsp.notRevoked` (success, informational in weight),
  `signingCredential.ocsp.unknown` and `signingCredential.ocsp.skipped`
  (informational).
- `revocation` in `checksPerformed` whenever the step ran at all.
- The resource bounds of SPEC-024: a response list and a response body both
  have limits, and exceeding one is a skip with the reason, never a read.

**Out of scope** (each needs its own spec before it may be built)

- **Any network access, in any form** — online OCSP, an AIA fetch, a CRL
  download, a trust list retrieval. This is not a milestone away; it is a
  rule of the project (SPEC-013, SPEC-014).
- Revocation of any certificate other than the signer's leaf. The CA
  obligations (`PRED-STRU-010`, `PRED-CRYP-020`) are AIA-based and need
  the network.
- Revocation of the TSA certificate.
- CRLs, in the manifest or out of it.
- `rVals` variants other than `ocspVals` (`crlVals` is defined and no
  fixture here carries one; unknown keys are ignored, not refused, because
  the header is unsigned and refusing would be a denial vector).

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-030')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

- **AC1 — a stapled `good` response is read and reported** *(happy path)*
  - Given `tests/Fixtures/c2pa-rs/ocsp.jpg`, whose signature carries one
    2264-byte OCSP response for its own signing certificate
  - When it is verified
  - Then `signingCredential.ocsp.notRevoked` is among the statuses, with an
    explanation naming the responder and `producedAt`, **and the
    `validation_state` is exactly what it was before this spec** — `Valid`
    without trust settings, as `tests/Fixtures/c2patool/c2pa-rs/ocsp.json`
    records. A stapled `good` changes no verdict.

- **AC2 — a response whose signature does not verify is skipped, not
  believed and not fatal** *(required: error / malformed input)*
  - Given that file with one byte of the OCSP response's signature flipped
    — the header is unprotected, so this is what an attacker can do
  - When it is verified
  - Then `signingCredential.ocsp.skipped` names the failure, no
    `notRevoked` is recorded, and the file's `validation_state` is
    unchanged from AC1. Editing an unsigned header must not be able to
    fail a valid asset.

- **AC3 — a verified `revoked` response fails the file**
  - Given a fixture whose stapled response says `revoked` with a
    revocation reason other than `removeFromCRL` and a `revocationTime`
    at or before the judged time, signed by a responder that chains to
    the signer's issuer
  - When it is verified
  - Then `signingCredential.ocsp.revoked` is a **failure**, the state is
    `Invalid`, and the explanation names the serial number, the
    revocation time and the reason.

- **AC4 — a response for another certificate is not applied**
  - Given a response whose `CertID` matches a different serial number or a
    different issuer
  - When it is verified
  - Then `signingCredential.ocsp.skipped` says the response matches no
    certificate in this chain, and nothing is concluded from it.

- **AC5 — a file without `rVals` says what was not checked**
  - Given any fixture whose signature carries no `rVals` — every other
    fixture in this repository
  - When it is verified
  - Then exactly one `signingCredential.ocsp.skipped` is recorded, and
    `checksPerformed` contains `revocation`. What was not done is visible.

- **AC6 — a stale response is not evidence**
  - Given a response whose `nextUpdate` lies before the judged time — as
    `ocsp.jpg`'s does today, 2025-08-18 against now
  - When it is verified
  - Then it is `signingCredential.ocsp.skipped` naming both dates, unless
    Open question 1 is decided the other way.

- **AC7 — malformed input is skipped by name, never an exception**
  - Given, each in turn: `rVals` that is not a map; `ocspVals` that is not
    a list; a response that is not DER; a `responseStatus` other than
    `successful`; a `responseType` that is not `id-pkix-ocsp-basic`; a
    `BasicOCSPResponse` with no `SingleResponse`
  - When each is verified
  - Then each is one `signingCredential.ocsp.skipped` naming what was
    wrong, no exception escapes the verifier, and the verdict is what the
    same file gives with no `rVals` at all.

- **AC8 — `removeFromCRL` is not a revocation**
  - Given a `revoked` response whose `revocationReason` is
    `removeFromCRL` (8)
  - When it is verified
  - Then it is **not** `signingCredential.ocsp.revoked`; RFC 6960 §4.2.1
    makes that reason a re-instatement, and treating it as a revocation
    would fail a file wrongly.

- **AC9 — nothing that passed stops passing**
  - Given every fixture this repository holds
  - When each is verified with and without trust settings
  - Then every `validation_state` and every failure code is what it was
    before this spec, plus the one informational line of AC5 or AC1.

- **AC10 — bounded, like every other parser here**
  - Given an `ocspVals` list longer than the limit, or a response larger
    than the limit, or DER nested past the limit
  - When it is verified
  - Then the step stops at the limit with a skip naming it, before the
    bytes are read (SPEC-024).

## References

- Specification: C2PA 2.4, the revocation steps of the signature
  validation procedure — numbered `VAL-STRU-0025`…`VAL-STRU-0032` and
  `VAL-CRYP-0033`…`VAL-CRYP-0034` in the conformance catalogue
  (`PRED-STRU-011`, `-012`, `-014`, `-015`, `PRED-CRYP-021`), and §15 for
  the four `signingCredential.ocsp.*` codes.
- Specification: **RFC 6960** (OCSP: `OCSPResponse`, `BasicOCSPResponse`,
  `CertID`, `CertStatus`, `RevokedInfo`; §4.2.1 for `removeFromCRL`),
  and **RFC 5019 §3.2** requirements 1–4, which the catalogue names as the
  acceptance rules for a response.
- Oracle, measured 2026-09-22: `openssl ocsp -respin <der> -resp_text
  -noverify` on the response extracted from `ocsp.jpg`, which prints
  `Cert Status: good`. **c2patool 0.27.22 emits no OCSP status code of its
  own** on `ocsp.jpg` or `ocsp_with_assertion.jpg`
  (`tests/Fixtures/c2patool/c2pa-rs/ocsp.json`, recorded step 39): the one
  `signingCredential.ocsp.skipped` anywhere in this repository's oracles
  sits inside a `validationResults` block that a *claim generator* wrote
  into an ingredient assertion, explanation "OCSP fetch skipped". **There
  is therefore no oracle for the positive path**, and none for AC3 unless
  one is constructed — see Open question 2.
- Measured: `rVals` is in the unprotected bucket (`protected: 1, 33` /
  `unprotected: sigTst, rVals, pad`), which is what §"The measurement that
  shapes everything" above is built on.
- Reasoned: the four consequences of the header being unsigned, and with
  them the whole shape of this spec — that a stapled response may lower
  trust but never raise it, and may never fail a file it cannot prove
  anything about. Nothing was measured about how c2pa-rs weighs a stapled
  `revoked`, because no fixture here carries one.

## API sketch

Illustrative only. `strict_types=1`, `final`, `readonly` as everywhere.

```php
// namespace Provemark\C2paVerifier\Trust;

/**
 * Revocation as far as it can be known without a network: the OCSP responses
 * a signer stapled into its own signature (SPEC-030).
 *
 * @internal SPEC-025: not part of the public API.
 */
final readonly class OcspCheck
{
    public const DEFAULT_MAX_RESPONSES = 4;

    public const DEFAULT_MAX_RESPONSE_BYTES = 64 * 1024;

    /**
     * @param  array<int|string, mixed>  $unprotected  the COSE unprotected header
     * @param  list<Certificate>  $chain  the signer's chain, leaf first
     * @param  int|null  $at  the judged time: a trusted timestamp's, else null for now
     * @return list<ValidationStatus> exactly one, always
     */
    public function check(array $unprotected, array $chain, ?int $at, string $url): array;
}

/** One decoded response, or a reason it cannot be used. */
final readonly class OcspResponse
{
    public function __construct(
        public string $status,          // good | revoked | unknown
        public ?int $revokedAt,
        public ?int $revocationReason,
        public int $producedAt,
        public int $thisUpdate,
        public ?int $nextUpdate,
        public string $serialNumber,
        public string $responderName,
    ) {}
}
```

`StatusCode` grows by four cases. That enum is one of the ten contract
classes, so the recorded public surface grows by four lines and SPEC-025
needs an amendment with this spec — the same shape as when
`FragmentedVerifier` joined in step 83.

## Open questions

All three were answered on approval (Maurice van Loon, 2026-09-22) by
taking the draft's recommendations. They are kept here with their
resolutions rather than deleted, because the reasoning is what the next
reader needs.

1. **What a stale response means** — **decided: asymmetric.** A response
   whose `nextUpdate` lies before the judged time is `skipped` when it says
   `good`, and still counts when it says `revoked`. A revocation does not
   expire; an assurance does. This is the same asymmetry as everywhere else
   in this spec: the unsigned header may lower trust and never raise it.
   RFC 5019 §3.2's freshness requirement is what makes a stale `good`
   unusable, and the specification's own answer to that case is the online
   fallback (`PRED-STRU-013`) this verifier will never make. AC6 asserts
   the `good` half; a stale `revoked` belongs to AC3.

2. **The fixture for AC3** — **decided: construct it.** No public file
   carries a `revoked` stapled response and c2patool emits no OCSP code of
   its own on the two fixtures that carry one, so waiting for a real file
   would leave the only failure path of this spec untested for an unknown
   length of time. `bin/make-ocsp-variants.php` generates a small CA, a
   signer and a `revoked` response with `openssl ocsp -index`, in the shape
   the other `bin/make-*-variants.php` scripts already use: **keys never
   enter the repository, not even test keys** — the script signs with
   throw-away keys and deletes them before it ends, and only the resulting
   asset is committed.

   What this fixture cannot have is a second implementation's verdict.
   AC3 is therefore measured against `openssl ocsp -resp_text` and the
   specification text alone, and **the note for the step must say so in
   those words**: it is the first acceptance criterion in this project with
   no independent oracle behind it.

3. **Whether `notRevoked` should be recorded** — **decided: record it,
   with the caveat in the explanation itself.** The catalogue names the
   code (`PRED-CRYP-021`) and a caller reading a status list should be able
   to tell "there was a response and it said good" from "there was none".
   The risk — that a green line about revocation invites exactly the
   conclusion this spec spends four paragraphs refusing — is answered where
   a reader will actually meet it: the explanation text says the response
   came from an unsigned header and is not evidence that the certificate
   was never revoked.

4. **Where the check runs** — **decided: the trust layer**, next to
   `CertificateProfileCheck`, after the chain (it needs the issuer) and
   after the timestamp (it needs the judged time). `Verifier` gains one
   call and no new ordering rule.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
| AC10                 | —                           | —                    |
