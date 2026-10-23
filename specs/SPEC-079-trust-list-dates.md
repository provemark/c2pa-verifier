# SPEC-079: The programme's JSON trust lists, with their dates

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

C2PA 2.4 §14.4.1: a trust anchor configuration "should include a
"notBefore" date/time, before which there is no trust for that trust
anchor configuration", and once its trust has ended a "notAfter"; such a
configuration "shall not be used for claim signatures whose timestamp
(or current time, if no timestamp is present) is" outside them. §15.7
says the same for validation. This verifier cannot be told those dates:
`PRED-CRYP-006`, issue #9, the one open gap that can give a `Trusted`
the specification refuses.

Step 383 measured where the dates live (`c2pa-org/conformance-public`
`da71a72`). The programme publishes each list as a PEM bundle and as
JSON in ETSI TS 119 602's "List of Trusted Entities" form (LoTE). Each
`TrustedEntityService` has a `ServiceInformation` with the
certificate(s) (`ServiceDigitalIdentity.X509Certificates[].val`), a
`ServiceStatus` and a `StatusStartingTime`, and may have a
`ServiceHistory` of earlier statuses, each with its own starting time.
The programme's schema extension allows two statuses: `…/trusted` ("the
date a certificate was added to the trust list") and `…/untrusted` ("the
date a certificate was untrusted"), which "maybe different from the
notBefore and notAfter dates of the certificate itself". The PEM bundle
drops all of it; so does `c2pa-rs` 0.91.1, which reads neither form's
dates.

Today: 50 of 55 anchors are listed from their certificate's own
`notBefore`; Castlabs C2PA ECC P-384 Root CA is listed a month later
(2026-08-10, valid from 2026-07-08) in both lists; three NETLOCK entries
have an empty status and time, outside the schema; none is untrusted.
No fixture moves.

## Scope

**In scope**

- `--trust-list` and `--tsa-trust-list`, and
  `TrustSettings::fromTrustLists()`, read a LoTE JSON document as well as
  a PEM bundle, told apart by content.
- From each service: its certificates, and a timeline of statuses, the
  current one and the history, each lasting from its starting time to
  the next one's. The anchor is trusted at a time that falls in a
  `trusted` stretch, and not at any other.
- The time judged: a trusted time-stamp's, else the validation time
  (SPEC-073) or now, for a signer anchor (§14.4.1, §15.7); the token's
  `genTime` for a TSA anchor, the time the TSA's chain is already judged
  at (SPEC-017).
- An anchor that cannot be placed on a timeline is not used, and the
  explanation says so: an empty or unknown status, a missing or
  malformed starting time, a history entry naming other certificates.

**Out of scope** (each needs its own spec before it may be built)

- Fetching a list, or checking a signature on it.
- `X509SubjectNames`, `X509SKIs`, `PublicKeyValues` as identities: only
  `X509Certificates`.
- Dates in `--settings` documents: the format shared with `c2patool`
  has no field for them.

## Behavior

- **AC1 — the JSON lists give the PEM bundles' anchors, minus the unplaceable**
  - Given the official JSON lists and PEM bundles at `da71a72`
  - When each is read
  - Then the JSON gives every PEM certificate except the three NETLOCK
    ones (signer list 30 of 32, TSA list 22 of 23), and the Pixel 10 and
    OpenAI fixtures give the same report through either form (with one
    fixed `--at`).

- **AC2 — a signer anchor listed after the signature is not used**
  - Given `timestamp/tsa-matrix/control.png` (time-stamp
    2026-10-09T05:30:24Z) and a LoTE document for its signer root whose
    `trusted` starts after that time, and one whose starts before
  - When verified with it as `--trust-list` and its TSA root as
    `--tsa-trust-list`
  - Then the first is `Valid` with `signingCredential.untrusted`, whose
    explanation names the anchor, its listed stretch and the time judged;
    the second is `Trusted`.

- **AC3 — a signer anchor untrusted after the signature still counts**
  - Given LoTE documents for the same root that are `untrusted` now,
    with a `ServiceHistory` `trusted` from before the time-stamp, and the
    `untrusted` starting after it, then before it
  - When verified
  - Then after it: `Trusted`; before it: `Valid`,
    `signingCredential.untrusted`.

- **AC4 — without a time-stamp, the validation time decides**
  - Given `timestamp/tsa-matrix/no-timestamp.png` (its signer valid from
    2026-10-09T05:30:24Z, measured) and a LoTE signer list whose root is
    `trusted` from 2026-10-12T00:00:00Z
  - When verified with `--at 2026-10-11T00:00:00Z` and
    `--at 2026-10-13T00:00:00Z`
  - Then on the 11th: `signingCredential.untrusted`, the certificate
    itself valid; on the 13th: `signingCredential.trusted`.

- **AC5 — a TSA anchor is judged at the token's time**
  - Given `control.png` and a LoTE TSA list whose root is `trusted` from
    after the token's `genTime`
  - When verified
  - Then the time-stamp is `timeStamp.untrusted`, and the signer, judged
    at now since no trusted time remains, keeps its own answer.

- **AC6 — what cannot be placed is not used, and said** *(required: error path)*
  - Given LoTE documents whose one service has: an empty status; an
    unknown status URI; no starting time; a malformed one; a history
    entry with other certificates
  - When verified with it as the signer list
  - Then the anchor is not used and the report says
    `signingCredential.untrusted` naming why; a document in which no
    service is usable is refused like a PEM file without a certificate
    (exit 2, `TrustException`).

- **AC7 — malformed input is refused** *(required: error path)*
  - Given a file that starts as JSON but is not; JSON without `LoTE`; a
    certificate value that is not base64 or not a certificate
  - When given as a list
  - Then exit 2 naming the option and the fault; the library throws
    `TrustException`.

## References

- Specification: C2PA 2.4 §14.4.1, §14.4.2, §15.7; ETSI TS 119 602
  v1.1.1 (the LoTE JSON schema in `conformance-public`); the programme's
  `c2pa-trust-list-extensions.schema.json`.
- Oracle: none implements this; `c2pa-rs` 0.91.1 reads no anchor dates.
  The criteria rest on the specification's text and the schema
  extension's descriptions; AC1 ties the undated case to the PEM route.
- Measured: `notes/step-383-anchor-dates-measured.md`; `control.png`'s
  time-stamp time.
- Reasoned: applying the TSA list's dates at the token's `genTime`
  (§14.4.2 names no time; SPEC-017 judges the TSA chain there); refusing
  an entry that cannot be placed (the maintainer's choice, 2026-10-23).

## API sketch

```php
// src/Trust/TrustAnchorSet.php: per anchor, the stretches it is trusted in
//   @param array<string, list<array{from: int, until: int|null}>> $windows  by certificate SHA-256; absent = no dates (PEM)
// src/Trust/TrustSettings.php: fromTrustLists() reads PEM or LoTE JSON
// src/Trust/ChainCheck.php: an anchor reached outside its windows does not count
```

The contract does not grow: `fromTrustLists()` keeps its signature.

## Open questions

- None blocking. The empty status: not used, and said (Maurice,
  2026-10-23).

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
