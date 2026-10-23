# SPEC-079: The programme's JSON trust lists, with their dates

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-23                      |
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
  *Status 2026-10-23 (step 385):* settled; approved without changes.

## Amendments

1. **2026-10-23, step 386, decided by Maurice van Loon ("optie 2")** *(confirmed by Maurice van Loon, 2026-10-23: "ja")* — only an `untrusted` status limits an anchor. On 2026-09-24 Maurice parked issue #9 (its comment on GitHub): reading `StatusStartingTime` of a `trusted` status as a `notBefore` is this project's own interpretation (the schema extension calls it "the date a certificate was added to the trust list", not an anchor's `notBefore`), it would make signatures untrusted that `c2patool` trusts, and the issue would reopen on an `untrusted` entry or a `ServiceHistory` in the official list. SPEC-079 was drafted without reading that comment; this amendment brings it back to what the parking left uncontested.
   - **The timeline.** An anchor is trusted at every time except inside an `untrusted` stretch: from an `untrusted` status's starting time to the next status's, or for good. A `trusted` status bounds nothing by itself; after an `untrusted` one it ends that stretch.
   - **What cannot be placed.** An empty or absent status is read as the PEM bundle reads the anchor, without dates (the three NETLOCK entries). A `trusted` status with an absent or malformed time likewise. An `untrusted` status with an absent or malformed time, a status that is neither `trusted` nor `untrusted`, and a history entry naming other certificates stay unusable and named, since each could hide the end of trust.
   - **AC1** becomes: the JSON lists give exactly the PEM bundles' anchors (32 and 23), and the Pixel 10 and OpenAI reports are the same through both.
   - **AC2** becomes: an anchor whose `trusted` status starts after the signature is still used (`control.png`, `Trusted`), as through the PEM bundle.
   - **AC3** stays (an anchor untrusted after the signature counts; untrusted before, it does not).
   - **AC4** becomes: `no-timestamp.png` with its root `untrusted` from 2026-10-12T00:00:00Z (history: `trusted` from 2026-10-01): `--at 2026-10-11` trusted, `--at 2026-10-13` untrusted.
   - **AC5** becomes: `control.png` with its TSA root `untrusted` from before the token's `genTime` gives `timeStamp.untrusted`; from after, `timeStamp.trusted`.
   - **AC6** becomes: an empty status, an absent status, and a `trusted` status without a usable time give an undated anchor (`Trusted`); an unknown status, an `untrusted` status without a usable time, and a history entry with other certificates give an unusable anchor, named; a list with no usable anchor is refused.
   - **AC7** stays.
   - Against `c2patool`, the verdicts now differ only once the programme untrusts an anchor; none is untrusted today.

2. **2026-10-23, steps 391 (timeline) and 392 (EKU choice), approved by Maurice van Loon ("ja"; found by the review of 2026-10-23)** — the timeline fails closed where the first build did not. Confirmed by running a reviewer's LoTE documents through `bin/c2pa-verify` on `control.png`: each gave `Trusted` where the criteria give untrusted.
   - **A certificate in two services**, with different timelines, is read under both: an anchor counts at a time only where every service that holds it trusts it then, and not at all where any of them is unusable. (The first build kept only the last service read.)
   - **Equal starting times**: an `untrusted` and a `trusted` status starting at the same instant leave the anchor untrusted from then on. (The first build let the input order decide.)
   - **Fractions of a second** are kept when the statuses are ordered; the stretches still bound at whole seconds, an `untrusted` one rounded down, a `trusted` one rounded up. (The first build dropped them, so `.9` and `.1` within a second could swap.)
   - **A `ServiceHistory` that is not a list** makes the anchor unusable, named. (The first build read it as no history.) **An entity, a service or a `ServiceInformation` that is not an object, or a `TrustedEntityServices` that is not a list**, refuses the list, naming the place: no certificate can be read from it, so the entry is not an anchor that could be marked unusable, and dropping it could hide a removal of a certificate another service holds. (The first build skipped it.)
   - **The "no usable anchor" refusal** counts certificates once each, and so applies when every anchor is unusable under the rules above (a list whose only anchor sits in a service with a `ServiceHistory` that is not a list is refused, not read as a list with one untrusted anchor).
   - **A byte order mark** before the JSON is skipped. **Certificates in a history entry** count against the same certificate limit as the rest.
   - **The EKU choice and `kindNote()`** judge whether a chain reaches an entry at the same time as the trust check, with the entry's dates (SPEC-031 AC8 read with SPEC-073 and this spec): the first build asked at the clock, without dates.
   - **AC8 (new)** — each of these, as a LoTE document on `control.png` or a unit test, gives the fail-closed answer; the official lists still give 32 and 23 anchors, and the reports of AC1 are unchanged.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Trust/TrustListDatesTest.php :: AC1: the JSON lists give exactly the PEM bundles' anchors, and the same reports / SPEC-079 | src/Trust/TrustSettings.php :: fromTrustLists(), fromLote(), loteCertificates(); tests/Fixtures/trust/conformance-public/*.json |
| AC2 | tests/Unit/Trust/TrustListDatesTest.php :: AC2: an anchor whose trusted status starts after the signature is still used, as through the PEM bundle / SPEC-079 | src/Trust/TrustSettings.php :: loteTimeline() (a trusted status bounds nothing) |
| AC3 | tests/Unit/Trust/TrustListDatesTest.php :: AC3: a signer anchor untrusted after the signature still counts; untrusted before, it does not / SPEC-079 | src/Trust/TrustSettings.php :: loteTimeline(); src/Trust/ChainCheck.php :: listedFault(), checkCertificates() |
| AC4 | tests/Unit/Trust/TrustListDatesTest.php :: AC4: without a time-stamp, the validation time decides whether a removal has happened / SPEC-079 | src/Trust/ChainCheck.php :: listedFault() (the judged time) |
| AC5 | tests/Unit/Trust/TrustListDatesTest.php :: AC5: a TSA anchor removed before the token's time does not vouch for it; removed after, it does / SPEC-079 | src/Timestamp/TimestampCheck.php :: tsaSettings() (the operator's "tsa" entries with their dates) |
| AC6 | tests/Unit/Trust/TrustListDatesTest.php :: AC6: an entry that cannot be placed is undated, or, where it could hide a removal, not used and named (8 datasets); AC6: a list in which no entry is usable is refused, like a PEM file without a certificate / SPEC-079 | src/Trust/TrustSettings.php :: loteTimeline(), fromLote(); src/Trust/TrustAnchorSet.php :: $windows, $reasons |
| AC7 | tests/Unit/Trust/TrustListDatesTest.php :: AC7: malformed JSON lists are refused, naming the option and the fault (4 datasets) / SPEC-079 | src/Trust/TrustSettings.php :: fromLote(), loteCertificates() |
| AC8 (amendment 2, timeline) | tests/Unit/Trust/TrustListDatesTest.php :: AC8: a timeline the first build misread leaves the signer untrusted (9 datasets); AC8: the guards against those misreadings still trust what is trusted (3); AC8: a list whose shape could hide a removal, or with no usable anchor counted once each, is refused (4) / SPEC-079 | src/Trust/TrustSettings.php :: fromTrustLists() (byte order mark), fromLote(), loteTimeline(), outside(), bothTrust(), rfc3339() |

The contract grew by two symbols, `TrustAnchorSet::$windows` and
`$reasons`, to 142; the API sketch said it would not grow, wrongly:
`TrustAnchorSet` is a contract class. Both are optional constructor
parameters with defaults, an addition.

AC6's documents hold the unplaceable service and one usable service: a
document whose only service cannot be placed is refused as a whole (the
same criterion's last sentence), so "whose one service has" is read as
"one of whose services has".

The tests were run red first: 16 of 16. Five mutations were watched
failing: the dates ignored (10 tests); the TSA entries' dates dropped
(AC5); an empty status read as trusted (AC6); the history ignored (AC3);
no end to a trusted stretch (AC3). The corpus before and after: 0 of
1,854 runs moved. `composer check`: 1,238 passed, 1 skipped.

Amendment 1 (step 386): the tests were rewritten to it first and run:
6 of 18 failed, the criteria it changes (AC1, AC2, the four undated
cases of AC6); AC3, AC4, AC5 and AC7 held under the first reading too.
Three mutations were watched failing: a trusted date read as a
`notBefore` again (AC2); an empty status not used (AC1, AC6); an
`untrusted` status without a time ignored (AC6). The corpus before and
after: 0 of 1,854 runs moved. `composer check`: 1,240 passed, 1 skipped.

Amendment 2, timeline (step 391): the reviewer's sixteen LoTE probes run
first, five giving `Trusted` where the criteria give untrusted; the 16
new tests red against the first build (11 at first; the rounding case,
added after a mutation survived, red too). Green after. Mutations, each
failing: the last service wins (2), fractions and ties ignored (3), a
trusted status's time rounded down (1, after the rounding case), a
`ServiceHistory` string read as none (1), the BOM kept (1). AC1 (the
official lists' 32 and 23 anchors, the same reports as the PEM bundles)
unchanged.
