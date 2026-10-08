# SPEC-061: The trust matrix as a drift alarm — never more lenient than `c2patool` on a chain

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-08                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Most of the wrong `Trusted` verdicts this project has found sat in the
certificate chain and trust checks (SPEC-014, SPEC-015, SPEC-046, SPEC-048,
SPEC-049; `SECURITY.md`). Each was found by someone asking one specific
question. The corpus holds real files with valid chains, and the fuzzer runs
without trust anchors, so neither reaches those rules.

Step 283 built a generator, `bin/make-trust-matrix.php`: one valid chain
(leaf ← intermediate ← anchor) and variants in which exactly one property of
one certificate differs, each re-signed with throw-away keys and judged by
`c2patool` 0.27.22 and 0.28.1, by OpenSSL and by this verifier. Its first run
found a wrong `Trusted` (SPEC-014 amendment 7). Run once, it is a measurement;
it must become a test, so that a later change to the chain checks, or a new
`c2patool`, cannot reopen what it closed without a red test saying so.

ADR-0005 allows this verifier to be stricter than `c2patool` where the
strictness protects a verdict, never more lenient. This spec turns that rule
into an alarm over the matrix.

## Scope

**In scope**

- The 42 probes of step 283 committed as fixtures, through the generator's
  fixture mode (`… chain-matrix <probe>…`), under `tests/Fixtures/trust/chain-matrix/`:
  each PNG, its anchor's PEM, its settings file; both `c2patool` versions'
  JSON under `tests/Fixtures/c2patool/chain-matrix/`; OpenSSL's answer per probe
  recorded in the folder's README, as information.
- A test that verifies every probe under its settings and compares the state
  and the failure codes with `c2patool` 0.28.1's recorded answer.
- A named list of probes where this verifier is stricter, each with the rule
  that makes it so; a named list of probes whose failure codes differ while
  the state is the same.

**Out of scope** (each needs its own spec before it may be built)

- The timestamp authority's chain (its own matrix: tokens made with
  `openssl ts`, placed in the COSE header).
- Two properties changed at once.
- Name constraints and certificate policies (SPEC-046 has its own probes).
- Any change to the verifier's rules. A difference the alarm finds is a new
  amendment to the spec that owns the rule, as SPEC-014 amendment 7 was.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and
will be covered by a Pest test tagged `->group('SPEC-061')`.

- **AC1 — every probe judged as `c2patool` 0.28.1 judges it, or stricter by name**
  - Given each of the 42 probes under `tests/Fixtures/trust/chain-matrix/` with its
    own settings file
  - When verified
  - Then `validation_state` equals the recorded 0.28.1 answer, except for
    the probes in `SPEC061_STRICTER`, where this verifier's state is lower in
    the order `Trusted` > `Valid` > `Invalid` and the list names the rule
    (today `leaf-eku-time-stamping`, step 152, and `int-sha1`, SPEC-048)

- **AC2 — never more lenient** *(the alarm)*
  - Given the same probes
  - When verified
  - Then no probe's state is higher than 0.28.1's, and no probe is
    `Trusted` here unless it is `Trusted` in both recorded `c2patool`
    versions

- **AC3 — the named lists are exact**
  - Given `SPEC061_STRICTER` and `SPEC061_CODES_DIFFER`
  - When the matrix is verified
  - Then every listed probe still differs as listed, and every probe that
    differs is listed: an entry that no longer differs is a finding, as is a
    difference without an entry

- **AC4 — the failure codes agree where the state does**
  - Given the probes whose state equals 0.28.1's
  - When verified
  - Then the set of failure codes equals 0.28.1's, except the probes in
    `SPEC061_CODES_DIFFER` (today `leaf-expired` and `leaf-not-yet-valid`:
    0.28.1 adds `signingCredential.untrusted` to `signingCredential.expired`;
    0.27.22 and this verifier do not; and, amendment 1, `leaf-ku-cert-sign`:
    both `c2patool` versions add `signingCredential.untrusted` to
    `signingCredential.invalid`; this verifier does not)

- **AC5 — without settings nothing is trusted** *(required: error path)*
  - Given every probe verified without settings
  - When the state is read
  - Then none is `Trusted`, and every probe that carries a manifest reports
    `signingCredential.untrusted` or a failure that makes it `Invalid`

- **AC6 — the matrix is the generator's**
  - Given the probe names the generator defines (`bin/make-trust-matrix.php`,
    read by name, not run) and the fixture folder
  - When compared
  - Then every probe the generator defines has a fixture and both recorded
    answers, and no fixture is left without a probe

## References

- Specification: RFC 5280 §4.2.1.3, §4.2.1.9, §4.2.1.12, §6.1; C2PA 2.4
  §14.5 (the signer's certificate profile), §14.4 (trust lists).
- Oracle: `c2patool` 0.28.1 (the alarm), 0.27.22 (recorded beside it);
  `openssl verify -x509_strict -partial_chain` 3.6 (information);
  `tests/Fixtures/trust/chain-matrix/`, `c2patool <probe>.png --settings
  <probe>.settings.json`.
- Measured: step 283, `notes/step-283-trust-matrix.md`.
- Reasoned: that a future `c2patool` that changes a verdict on a probe is
  a reason to re-record and amend, not to follow silently.

## API sketch

No new code in `src/`. The test reads the fixtures and the recorded JSON:

```php
// tests/Unit/Trust/TrustMatrixTest.php
const SPEC061_STRICTER = [
    'leaf-eku-time-stamping' => 'step 152: a leaf with only the Time Stamping EKU signs nothing',
    'int-sha1' => 'SPEC-048: no SHA-1 signature between anchor and leaf',
];
const SPEC061_CODES_DIFFER = [/* probe => [0.28.1's codes, 0.27.22's, this verifier's] */];
```

## Open questions

- Non-blocker: the probes' validity. Certificates valid "now" are made valid
  for ten years from the day they are built, so the control and most probes
  stop being `Trusted` around 2036, as the other probe sets do. The validity
  variants use fixed dates (2020, 2090). Proposal: accept, and name it in the
  README.
  *Status 2026-10-08 (step 288):* decided by Maurice van Loon with the approval: as proposed.
- Non-blocker: the expired-leaf code difference (AC4). Follow 0.28.1 and add
  `signingCredential.untrusted`, or keep 0.27.22's list. Proposal: keep, and
  list it; the state is the same and the alarm watches it.
  *Status 2026-10-08 (step 288):* decided by Maurice van Loon with the approval: as proposed.
- Non-blocker: size. 42 PNGs of about 48 KB, 2.0 MB in all, beside 85 MB of
  fixtures today; `tests/` is not in the package.
  *Status 2026-10-08 (step 288):* decided by Maurice van Loon with the approval: as proposed.
- Non-blocker: re-recording. When `c2patool` moves past 0.28.1, the generator
  is run again in fixture mode and every changed answer is named before the
  pinned version moves.
  *Status 2026-10-08 (step 288):* decided by Maurice van Loon with the approval: as proposed.

## Amendments

1. **2026-10-08, step 288, found while writing the tests, before they were
   green.** Two corrections.
   - **The folder.** `tests/Fixtures/c2patool/matrix/` already holds the
     coverage matrix of step 59, so this spec's probes are the generator's
     set `chain-matrix`: `tests/Fixtures/trust/chain-matrix/` and
     `tests/Fixtures/c2patool/chain-matrix/`. Nothing was overwritten; the
     generator refused the first run before it wrote a file.
   - **A third code difference.** AC4 found `leaf-ku-cert-sign`, a leaf
     whose keyUsage holds `keyCertSign`: `Invalid` in both `c2patool`
     versions and here, but both versions report `signingCredential.invalid`
     and `signingCredential.untrusted`, and this verifier only `.invalid`.
     The `untrusted` comes from OpenSSL, which refuses the chain ("Key usage
     keyCertSign invalid for non-CA cert"); this verifier refuses the leaf
     through its profile (SPEC-015), and its chain walk does not judge a
     leaf's keyUsage. The state is the same, so it is named in
     `SPEC061_CODES_DIFFER`, not changed. Step 283's note said there was one
     code difference; it compared states only, and is corrected.

   **Weight C: no verdict moves.**

   Confirmed by Maurice van Loon, 2026-10-08 (step 288).

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Trust/TrustMatrixTest.php :: AC1, AC2, AC3: every probe as c2patool 0.28.1 judges it, stricter only by name, never more lenient / SPEC-061 | SPEC061_STRICTER; the fixtures from bin/make-trust-matrix.php (set chain-matrix) |
| AC2 | tests/Unit/Trust/TrustMatrixTest.php :: AC1, AC2, AC3: every probe as c2patool 0.28.1 judges it, stricter only by name, never more lenient / SPEC-061 | SPEC061_RANK; src/Trust/ChainCheck.php, src/Trust/CertificateProfileCheck.php (the rules the alarm watches) |
| AC3 | tests/Unit/Trust/TrustMatrixTest.php :: AC1, AC2, AC3: …; AC4, AC3: where the state agrees, the failure codes agree, except the named probes / SPEC-061 | SPEC061_STRICTER, SPEC061_CODES_DIFFER |
| AC4 | tests/Unit/Trust/TrustMatrixTest.php :: AC4, AC3: where the state agrees, the failure codes agree, except the named probes / SPEC-061 | SPEC061_CODES_DIFFER (amendment 1) |
| AC5 | tests/Unit/Trust/TrustMatrixTest.php :: AC5: without settings no probe is trusted / SPEC-061 | src/Trust/ChainCheck.php :: check() (no anchors: untrusted) |
| AC6 | tests/Unit/Trust/TrustMatrixTest.php :: AC6: every probe the generator defines has a fixture and both answers, and no fixture is left over / SPEC-061 | bin/make-trust-matrix.php ($variants, fixture mode) |
