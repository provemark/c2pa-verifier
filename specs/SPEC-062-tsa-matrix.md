# SPEC-062: The timestamp matrix as a drift alarm — never more lenient than `c2patool` on a timestamp

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-09                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A trusted timestamp is what keeps a file `Trusted` after its signer's
certificate has expired (SPEC-017, ADR-0004). A timestamp check that
is too lenient therefore gives a wrong `Trusted` that no chain check can catch.

Steps 289 and 292 built `bin/make-tsa-matrix.php`: one valid signer chain
and one valid timestamp authority chain, and 29 probes in which one property
of the timestamp differs. The changed property is a certificate of the TSA
chain, its extended key usage, the trust settings, the token, or the
signer's validity. Each probe is judged by `c2patool` 0.27.22 and 0.28.1,
by `openssl ts -verify` and by this verifier. Its first run found three TSA
leaves that were `Trusted` here and `Invalid` in 0.28.1 (SPEC-017
amendment 8). Its second run, on the EKU, found nothing more lenient.

Run once, the matrix is a measurement. SPEC-061 made the trust matrix a
test. This spec does the same for the timestamp matrix, so that a later
change to `TimestampCheck`, the TSA profile or the trust settings, or a new
`c2patool`, cannot reopen what it closed without a red test.

## Scope

**In scope**

- The 29 probes committed as fixtures through the generator's fixture mode
  (`… tsa-matrix <probe>…`). Each PNG and its settings file go under
  `tests/Fixtures/timestamp/tsa-matrix/`, and both `c2patool` versions' JSON,
  with the probe's settings and without any, under
  `tests/Fixtures/c2patool/tsa-matrix/`. The folder's README records
  OpenSSL's answer per probe, as information.
- A test that verifies every probe under its settings and compares three
  things with `c2patool` 0.28.1's recorded answer: the state, the
  `timeStamp.*` codes and the failure codes.
- A named list of probes where this verifier is stricter on the state, and
  a named list of probes whose codes differ while the state is the same,
  each entry with the rule that makes it so.

**Out of scope** (each needs its own spec before it may be built)

- The signer's chain (SPEC-061).
- Two properties changed at once; a TSA chain longer than three.
- `sigTst` tokens from other TSAs than `openssl ts` and `openssl cms` (the
  real tokens of SPEC-016 and SPEC-017 cover those).
- Any change to the verifier's rules. A difference the alarm finds becomes
  an amendment to the spec that owns the rule, as SPEC-017 amendment 8 was.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and
will be covered by a Pest test tagged `->group('SPEC-062')`.

- **AC1 — every probe judged as `c2patool` 0.28.1 judges it, or stricter by name**
  - Given each of the 29 probes under `tests/Fixtures/timestamp/tsa-matrix/`
    with its own settings file
  - When verified
  - Then `validation_state` equals the recorded 0.28.1 answer, except for
    the probes in `SPEC062_STRICTER`, where this verifier's state is lower
    in the order `Trusted` > `Valid` > `Invalid` and the list names the rule
    (today: none)

- **AC2 — never more lenient** *(the alarm)*
  - Given the same probes
  - When verified
  - Then no probe's state is higher than 0.28.1's, and no probe reports
    `timeStamp.trusted` here unless 0.28.1 reports it too.
    0.27.22 is recorded but is no part of this rule: it does not read
    `trust.anchors` (SPEC-031), so it trusts no TSA on all but one probe.

- **AC3 — the named lists are exact**
  - Given `SPEC062_STRICTER` and `SPEC062_CODES_DIFFER`
  - When the matrix is verified
  - Then every listed probe still differs as listed, and every probe that
    differs is listed. An entry that no longer differs is a finding, and so
    is a difference without an entry.

- **AC4 — the codes agree where the state does**
  - Given the probes whose state equals 0.28.1's
  - When verified
  - Then the set of `timeStamp.*` codes and the set of failure codes each
    equal 0.28.1's, except for the probes in `SPEC062_CODES_DIFFER`. Today,
    measured in steps 289 and 292, those are:
    - `tsa-root-as-manifest`: `timeStamp.untrusted` here, `trusted` in
      0.28.1. A `"manifest"` anchor vouches for signers only (SPEC-031).
    - `header-both`: `timeStamp.malformed` here, `validated` and `trusted`
      in 0.28.1, which reads `sigTst2`. Two headers are refused (SPEC-016).
    - `tsa-leaf-eku-email-only`: `timeStamp.untrusted` here, `trusted` in
      0.28.1, while both report `signingCredential.invalid`. A leaf without
      `timeStamping` is no TSA (SPEC-017 AC7).
    - `expired-signer-untrusted-tsa` and `expired-signer-no-timestamp`:
      0.28.1 adds `signingCredential.untrusted` to
      `signingCredential.expired`; this verifier does not, as in SPEC-061.

- **AC5 — without settings nothing is trusted** *(required: error path)*
  - Given every probe verified without settings
  - When the state and the codes are read
  - Then none is `Trusted` and none reports `timeStamp.trusted`

- **AC6 — an expired signer is kept only by a trusted timestamp**
  - Given the three probes whose signer expired after it was stamped
  - When verified under their settings
  - Then `expired-signer-trusted-tsa` is `Trusted`, and
    `expired-signer-untrusted-tsa` and `expired-signer-no-timestamp` are
    `Invalid` with `signingCredential.expired`. This criterion holds
    whatever the recorded answers say: it is ADR-0004's rule, and the
    oracle agrees with it today.

- **AC7 — the matrix is the generator's**
  - Given the probe names the generator defines (`bin/make-tsa-matrix.php`,
    read by name, not run) and the fixture folder
  - When compared
  - Then every probe the generator defines has a fixture and four recorded
    answers (two versions, with and without settings), and no fixture is
    left without a probe

## References

- Specification: RFC 3161 §2.3 (the TSA certificate), §2.4.2 (the
  response); RFC 5816 (signingCertificateV2); C2PA 2.4 §14.5 (the
  certificate profile), §14.6 (the timestamp and the CounterSignature),
  §14.4 (trust lists).
- Oracle: `c2patool` 0.28.1 (the alarm), 0.27.22 (recorded beside it);
  `openssl ts -verify` 3.6 (information).
- Measured: steps 289 and 292, `notes/step-289-tsa-matrix.md`,
  `notes/step-292-tsa-eku.md`.
- Reasoned: that a future `c2patool` that changes a verdict on a probe is
  a reason to re-record and amend, not to follow silently.

## API sketch

No new code in `src/`. The test reads the fixtures and the recorded JSON:

```php
// tests/Unit/Timestamp/TsaMatrixTest.php
const SPEC062_STRICTER = [];
const SPEC062_CODES_DIFFER = [
    'tsa-root-as-manifest' => 'SPEC-031: a "manifest" anchor vouches for signers only',
    'header-both' => 'SPEC-016: two timestamp headers are malformed',
    'tsa-leaf-eku-email-only' => 'SPEC-017 AC7: a leaf without timeStamping is no TSA',
    'expired-signer-untrusted-tsa' => '0.28.1 adds signingCredential.untrusted to .expired',
    'expired-signer-no-timestamp' => '0.28.1 adds signingCredential.untrusted to .expired',
];
```

## Open questions

- Non-blocker: overlap with SPEC-017 AC14. Its four probes in
  `tests/Fixtures/timestamp/tsa-profile/` (control and three off-profile
  leaves) were made by the same generator. Proposal: keep both. AC14 tests
  the amendment's rule, this spec watches the oracle, and the four PNGs
  cost 190 KB.
  *Status 2026-10-09 (step 294):* decided by Maurice van Loon with the approval: as proposed.
- Non-blocker: the expired-signer probes. Their signers live for 2.5
  minutes and the generator waits until they have expired before
  `c2patool` records its answer. Once built, they stay expired, so the
  fixtures are stable. Their tokens are stamped at build time, inside the
  TSA's validity of ten years. Proposal: accept, and name it in the README.
  *Status 2026-10-09 (step 294):* decided by Maurice van Loon with the approval: as proposed.
- Non-blocker: the probes' validity. As in SPEC-061, certificates valid
  "now" are made valid for ten years, so the control stops being `Trusted`
  around 2036. Proposal: accept, and name it in the README.
  *Status 2026-10-09 (step 294):* decided by Maurice van Loon with the approval: as proposed.
- Non-blocker: a non-critical `timeStamping` EKU (`tsa-leaf-eku-not-critical`).
  RFC 3161 §2.3 says the extension MUST be critical. 0.28.1 and this
  verifier accept it, and OpenSSL refuses it. The alarm records today's
  equality. Refusing it would be a choice to be stricter than `c2patool`
  (ADR-0005) and an amendment to SPEC-017, not part of this spec.
  Proposal: out of scope here; decide separately.
  *Status 2026-10-09 (step 294):* decided by Maurice van Loon with the approval: as proposed.
- Non-blocker: size. 29 PNGs of about 47 KB, 1.4 MB in all, plus 116
  small JSON files; `tests/` is not in the package.
  *Status 2026-10-09 (step 294):* decided by Maurice van Loon with the approval: as proposed.
- Non-blocker: re-recording. When `c2patool` moves past 0.28.1, the
  generator is run again in fixture mode, and every changed answer is named
  before the pinned version moves. A rerun makes new keys and tokens, so
  the PNGs change byte for byte while the answers should not.
  *Status 2026-10-09 (step 294):* decided by Maurice van Loon with the approval: as proposed.

## Amendments

1. **2026-10-09, step F1, with SPEC-017 amendment 9** *(confirmed by Maurice van Loon, 2026-10-09)* —
   Two new probes put two tokens from the trusted TSA into `sigTst2`'s
   `tstTokens`: `two-tokens` (a valid signer) and
   `expired-signer-two-tokens` (a signer that expired after it was
   stamped). The matrix holds 31 probes; AC7 counts 31. With SPEC-017
   amendment 9 both agree with 0.28.1 on the state. `expired-signer-two-tokens`
   joins `SPEC062_CODES_DIFFER`, because 0.28.1 adds
   `signingCredential.untrusted` to `signingCredential.expired`, as for the
   other expired signers.

   **Weight C: no verdict of the existing probes moves.**

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Timestamp/TsaMatrixTest.php :: AC1, AC2, AC3: every probe as c2patool 0.28.1 judges it, stricter only by name, never more lenient / SPEC-062 | SPEC062_STRICTER; the fixtures from bin/make-tsa-matrix.php (set tsa-matrix) |
| AC2 | tests/Unit/Timestamp/TsaMatrixTest.php :: AC1, AC2, AC3: every probe as c2patool 0.28.1 judges it, stricter only by name, never more lenient / SPEC-062 | SPEC062_RANK; src/Timestamp/TimestampCheck.php, src/Trust/CertificateProfileCheck.php (the rules the alarm watches) |
| AC3 | tests/Unit/Timestamp/TsaMatrixTest.php :: AC1, AC2, AC3: …; AC4, AC3: where the state agrees, the timeStamp and failure codes agree, except the named probes / SPEC-062 | SPEC062_STRICTER, SPEC062_CODES_DIFFER |
| AC4 | tests/Unit/Timestamp/TsaMatrixTest.php :: AC4, AC3: where the state agrees, the timeStamp and failure codes agree, except the named probes / SPEC-062 | SPEC062_CODES_DIFFER |
| AC5 | tests/Unit/Timestamp/TsaMatrixTest.php :: AC5: without settings no probe is trusted and no timestamp is trusted / SPEC-062 | src/Timestamp/TimestampCheck.php :: judge() (no TSA anchors: timeStamp.untrusted) |
| AC6 | tests/Unit/Timestamp/TsaMatrixTest.php :: AC6: an expired signer is kept only by a trusted timestamp / SPEC-062 | src/Trust/ChainCheck.php (the signer judged at the trusted timestamp's time, ADR-0004) |
| AC7 | tests/Unit/Timestamp/TsaMatrixTest.php :: AC7: every probe the generator defines has a fixture and four answers, and no fixture is left over / SPEC-062 | bin/make-tsa-matrix.php ($variants, fixture mode) |
