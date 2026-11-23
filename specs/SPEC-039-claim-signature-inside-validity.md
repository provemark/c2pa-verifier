# SPEC-039: `claimSignature.insideValidity` — the success code c2patool reports beside a verified signature

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-24                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Both `c2patool` versions put `claimSignature.insideValidity` in the
success list of nearly every report. This verifier never does. No verdict
depends on it, but it is the most visible remaining difference between the
two reports: a reader comparing them finds it on the first line.

A sweep of the status codes `c2pa-rs` uses outside its tests, against this
verifier's `StatusCode` (read at `6c92bc3`), found 22 missing codes. The
other 21 belong to decisions already taken:
- `cawg.x509.*`: the 0.4 theme;
- `assertion.boxesHash.*`, `assertion.collectionHash.*` and
  `assertion.cloud-data.*`: refused by name, and no writer produces them;
- `assertion.timestamp.malformed`: issue #6;
- `manifest.inaccessible`: no network;
- `assertion.outsideManifest`: a code difference, `PRED-ASSE-004`, left
  for later.

**Measured over the `c2patool` reports this repository records** (every
JSON under `tests/Fixtures/c2patool/`, both versions; 85 of the reports
carrying the code are 0.28.0's):
- in the active manifest, `claimSignature.insideValidity` appears in
  **exactly** the 366 reports that hold `claimSignature.validated`. It is
  always a success, always on the manifest's `c2pa.signature` url, and
  always **directly before** `claimSignature.validated`;
- it appears in none of the 20 reports with `claimSignature.mismatch`;
- **it appears beside `signingCredential.expired`** in six reports
  (`profile/expired*`, `public-testfiles/nikon-20221019-building`,
  `writers/google-20250919-pixel10-npld-picnic-table`). The name promises
  validity, but the code does not deliver it: `c2pa-rs`'s
  `verify_internal` (`claim.rs`) logs it whenever the signature verified
  (`vi.validated`), with the explanation *"claim signature valid"*;
- in ingredient deltas the same holds: 39 deltas carry both codes, 106
  carry neither, and none carries one without the other.

**What C2PA 2.4 says** (read at `4eb2c67`):
- the §15 table: *"The claim signature referenced in the claim was created
  within the validity period of the signing credential"*;
- §15.8 (*Validate the Time-Stamp*) makes it an obligation. When the time
  of signing lies within the validity period of the signer's certificate
  and of every CA certificate up to the anchor, *"the validator shall
  return a success code of `claimSignature.insideValidity`. If it is not,
  the C2PA Manifest shall be rejected with a failure code of
  `claimSignature.outsideValidity`"*.

So the specification ties the code to validity, and `c2patool` ties it to
the signature alone. For an expired signer, this verifier already reports
`signingCredential.expired` instead of `claimSignature.outsideValidity`,
as `c2patool` does (`PRED-CRYP-016`). This spec copies `c2patool`,
subject to open question 1.

## Scope

**In scope**

1. `StatusCode` gains `ClaimSignatureInsideValidity =
   'claimSignature.insideValidity'`, a **success**.
2. `ClaimSignatureCheck` emits it wherever it emits
   `claimSignature.validated`: same url, explanation *"claim signature
   valid"*, placed directly before. Through the one check, this covers both
   the active manifest and the ingredient manifests `IngredientManifestCheck`
   validates.
3. No other change. The certificate-validity checks and their codes
   (`signingCredential.expired`, SPEC-015 and SPEC-017) stay as they are.

**Out of scope** (each needs its own spec before it may be built)

- `claimSignature.outsideValidity`: `c2pa-rs` reports an expired signer
  as `signingCredential.expired`, as this verifier does (`PRED-CRYP-016`).
- `assertion.outsideManifest` (`PRED-ASSE-004`).

## Behavior

- **AC1 — beside every verified signature, as c2patool**
  - Given every corpus file whose recorded `c2patool` report holds
    `claimSignature.validated` in the active manifest, verified under the
    settings that report was made with
  - When verified
  - Then this verifier's active success list holds
    `claimSignature.insideValidity` on the same `c2pa.signature` url,
    directly before `claimSignature.validated`.

- **AC2 — an expired signer does not get it, as c2patool 0.29.0** *(amendment 3)*
  - Given `profile/expired.png` (a signer outside its validity period, no
    timestamp)
  - When verified
  - Then `signingCredential.expired` appears and
    `claimSignature.insideValidity` does not, and the verdict is
    `Invalid`, as `c2patool` 0.29.0's recorded report shows.

- **AC3 — no verified signature, no code** *(error path)*
  - Given the corpus files whose report holds `claimSignature.mismatch`
    (`cose/claim-title-changed.png` among them)
  - When verified
  - Then no `claimSignature.insideValidity` appears.

- **AC4 — ingredient manifests alike**
  - Given the multi-manifest corpus files of SPEC-021
  - When verified
  - Then no ingredient delta holds `claimSignature.insideValidity` beside
    `signingCredential.expired`, and every delta that holds
    `claimSignature.validated` holds `claimSignature.insideValidity` too,
    as in `c2patool` 0.29.0's deltas (amendments 3 and 4).

- **AC5 — nothing else moves**
  - Given the whole corpus under the three standard settings, before and
    after, with ingredient deltas and informational codes compared
  - Then no verdict and no failure or informational code changes; the
    only difference is the new success code.

- **AC6 — the vocabulary grows by one code, verbatim**
  - Then `StatusCode::ClaimSignatureInsideValidity` exists with the value
    `claimSignature.insideValidity`, `isSuccess()` is true, and it is in
    the recorded surface (124 → 125).

- **AC7 — a signer kept in time keeps it** *(amendment 3)*
  - Given `timestamp/tsa-matrix/expired-signer-trusted-tsa.png` under its
    own settings (the signer expired, a trusted time-stamp from before)
  - When verified
  - Then `claimSignature.insideValidity` appears directly before
    `claimSignature.validated`, no `signingCredential.expired` appears,
    and the verdict is `Trusted`, as `c2patool` 0.29.0's recorded report
    shows.

## References

- Specification: C2PA 2.4 §15.8 and the §15 status-code table. Read in the 2.4
  HTML of `c2pa-org/specifications` at `4eb2c67`.
- Oracles: every `c2patool` report recorded under `tests/Fixtures/c2patool/`
  (0.27.22 and 0.28.0), counted for this draft. In the tests-first step,
  0.28.0 is also run on `profile/expired.jpg`, since the recorded expired
  reports are 0.27.22's.
- Reasoned: `c2pa` `claim.rs` `verify_internal`, read at `6c92bc3` (the
  code logged in the `vi.validated` branch).

## API sketch

Illustrative.

```php
// Report\StatusCode (contract): one case more, a success
case ClaimSignatureInsideValidity = 'claimSignature.insideValidity';

// Cose\ClaimSignatureCheck (@internal): where claimSignature.validated is returned
[new ValidationStatus(StatusCode::ClaimSignatureInsideValidity, $url, 'claim signature valid'),
 new ValidationStatus(StatusCode::ClaimSignatureValidated, $url, ...)]
```

## Open questions

*Answered on approval, 2026-09-24:* question 1 by the maintainer (copy
`c2patool`). Question 2 was settled by adopting its proposal.

1. **A code whose name says more than it means.** *(answered the other
   way by amendment 3, 2026-11-22: `c2patool` 0.29.0 follows §15.8 now)* `c2patool` reports
   `claimSignature.insideValidity` beside `signingCredential.expired`, so a
   report can say both *"inside validity"* and *"expired"* about the same
   signature. Two choices:
   - copy `c2patool`: emit it wherever the signature verifies. The reports
     compare line for line, and the explanation (*"claim signature
     valid"*) says what it means;
   - emit it only when the signer is also inside its validity period at
     the time judged. That is §15.8's *shall*, and differs from
     `c2patool` on expired signers (six recorded reports).

   Proposal: copy `c2patool`, and name the oddity in `docs/comparison.md`.
   *(blocker: your call)*
   *Status 2026-10-08 (step 279):* about the report's codes; the verdict is not affected.
2. **The position in the list.** `c2patool` always puts it directly
   before `claimSignature.validated`. Proposal: the same order. Tests that
   pick the first status of `ClaimSignatureCheck` are amended with this
   spec. *(not a blocker)*
   *Status 2026-10-08 (step 279):* about the report's order, no verdict.

## Amendments

1. **2026-09-24, step 135a, measured before the tests.** AC2's fixture is
   `profile/expired.png`; the draft wrote `.jpg`. Both `c2patool` versions
   were run on it, where the draft had only 0.27.22's recorded reports.
   Both report `claimSignature.insideValidity` directly before
   `claimSignature.validated`, beside `signingCredential.expired`, and
   `Invalid`. The reports are recorded under
   `tests/Fixtures/c2patool/inside-validity/`.

   Weight C: a file name corrected and a measurement added.

   Confirmed by Maurice van Loon, 2026-09-24 (step 135).

2. **2026-09-24, step 135b, measured.** AC1's *"every corpus file whose
   recorded report holds `claimSignature.validated`"* holds on 21 of
   `SPEC013_CORPUS`'s 22. The 22nd, `variants/json-broken`, stops this
   verifier at a parse fault before the signature is read, where
   `c2patool` goes on (`SPEC013_SUBSET_ONLY`, SPEC-013). It gets no
   `claimSignature.validated` here, and so no `insideValidity` either. The
   test names it and asserts that.

   Weight C: a named exception to a criterion's wording, no behaviour
   changed.

   Confirmed by Maurice van Loon, 2026-09-24 (step 135).

3. **2026-11-22, step 555, found by the corpus against `c2patool` 0.29.0
   (step 549, followed up in step 555)** *(approved by Maurice van Loon,
   2026-11-22)* — open question 1 is answered the other way. Since
   `c2pa` 0.91.11 (`c2patool` 0.28.6), `verify_internal`
   (`claim/claim_verify.rs`) no longer logs `claimSignature.insideValidity`
   when the same signature already carries `claimSignature.outsideValidity`
   or `signingCredential.expired`. That is C2PA 2.4 §15.8 (2.5 §14.8): the
   code says the signer was inside its validity period at the time
   judged. `c2patool` 0.29.0 and the specification now agree, so there is
   nothing left to copy the other way.

   - **The rule.** `claimSignature.insideValidity` is reported where the
     signature verifies, unless `signingCredential.expired` is reported on
     the same `c2pa.signature` url. This verifier has no
     `claimSignature.outsideValidity`; an expired signer is
     `signingCredential.expired`, as before (`PRED-CRYP-016`). It holds
     for the active manifest and for every ingredient manifest.
   - **The time judged is unchanged.** A signer kept in time by a trusted
     time-stamp is not `expired`, so it keeps the code
     (`timestamp/tsa-matrix/expired-signer-trusted-tsa.png` under its own
     settings, `Trusted` in 0.29.0 with the code).
   - **AC2 is rewritten:** *an expired signer does not get it, as
     `c2patool` 0.29.0*. `profile/expired.png` has `signingCredential.expired`
     and no `claimSignature.insideValidity`, and stays `Invalid`. The
     0.29.0 answer is recorded beside the 0.27.22 and 0.28.0 ones under
     `tests/Fixtures/c2patool/inside-validity/`.
   - **AC4 is read against 0.29.0:** an ingredient delta holds the code
     exactly when it holds `claimSignature.validated` and not
     `signingCredential.expired`.
   - **AC7 is new**, the control: the trusted-time-stamp probe above keeps
     the code, directly before `claimSignature.validated`, as 0.29.0's
     answer under `tests/Fixtures/c2patool/tsa-matrix/` shows.
   - `claimSignature.validated` is not touched here. `c2pa` withholds that
     one too on a refused credential; that is a separate step.

   Measured: over the 930 media files of step 549, without settings and
   with `trust/full.settings.json`, this verifier reported the code where
   0.29.0 did not in 26 runs on 13 files, each with
   `signingCredential.expired`. No verdict depends on the code.

   Weight B: a report code changes on expired signers; no verdict moves.

4. **2026-11-23, step 556, with SPEC-010 amendment 7** *(approved by
   Maurice van Loon, 2026-11-23, with that amendment)* — SPEC-010
   amendment 7 withholds `claimSignature.validated` on a refused
   credential. Beside `signingCredential.invalid` (or `ocsp.revoked`)
   `claimSignature.insideValidity` now stands without it, as in
   `c2patool` 0.29.0. AC4's *"exactly when it holds
   `claimSignature.validated` and not `signingCredential.expired`"* no
   longer holds there. It becomes two implications: never beside
   `expired`, and beside every `validated`. AC1 and AC7 compare files
   without a refusal and stay as they are.

   Weight C: a criterion's wording follows another spec's amendment; no
   behaviour of this spec changed.

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Cose/InsideValidityTest.php :: AC1: beside every verified signature, as c2patool / SPEC-039; tests/Unit/Report/ReportTest.php :: AC1 / SPEC-010 | src/Cose/ClaimSignatureCheck.php :: checkBytes() |
| AC2 | tests/Unit/Cose/InsideValidityTest.php :: AC2: an expired signer does not get it, as c2patool 0.29.0 / SPEC-039 | src/Cose/ClaimSignatureCheck.php :: withinValidity(); src/Verifier/Verifier.php :: check() (amendment 3) |
| AC3 | tests/Unit/Cose/InsideValidityTest.php :: AC3: no verified signature, no code / SPEC-039 | src/Cose/ClaimSignatureCheck.php :: checkBytes() (the mismatch branch) |
| AC4 | tests/Unit/Cose/InsideValidityTest.php :: AC4: ingredient manifests alike / SPEC-039 | src/Verifier/IngredientManifestCheck.php :: manifest() (through ClaimSignatureCheck, then withinValidity(), amendments 3 and 4) |
| AC5 | tests/Unit/Cose/InsideValidityTest.php :: AC5: nothing else moves / SPEC-039; the drift alarms (SPEC-013 AC10–AC13); the before/after run of step 135b | — |
| AC6 | tests/Unit/Cose/InsideValidityTest.php :: AC6: the vocabulary grows by one code, verbatim / SPEC-039 | src/Report/StatusCode.php :: ClaimSignatureInsideValidity, isSuccess(); tests/Fixtures/api/public-surface.txt |
| AC7 | tests/Unit/Cose/InsideValidityTest.php :: AC7: a signer kept in time keeps it / SPEC-039 | src/Cose/ClaimSignatureCheck.php :: withinValidity() (no expired, nothing withdrawn) |
