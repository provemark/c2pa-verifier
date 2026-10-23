# SPEC-073: A validation time given by the caller

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

Where a manifest has no trusted timestamp, C2PA 2.4 judges it at "the
current time":

- §15.8.2: without a valid `sigTst`/`sigTst2`, *"the C2PA Manifest is
  valid if the current time at validation is within the validity period
  of the signer's certificate and all CA certificates up to the trust
  anchor"*. With a trusted one, validators *"shall use the attested time,
  and not the current time"*.
- §15.9: a CA certificate revoked *"at the time indicated in a trusted
  time-stamp, or at the current time if no trusted time-stamp is
  present"* gives `signingCredential.untrusted`.
- §15.9.1: a stapled OCSP response is judged against the current time
  (`thisUpdate`, `nextUpdate`).
- §14.4.1 and §15.7: a trust anchor configuration's `notBefore` and
  `notAfter` are compared with the timestamp, *"or current time, if no
  timestamp is present"*.

This verifier takes that time from the clock, always. `Verifier::verify()`
accepts a stream and settings, nothing else. `ChainCheck`, `OcspCheck` and
`CertificateProfileCheck` each call `time()` when no trusted timestamp is
present.

The C2PA Conformance Program (v0.2, *Additional Conformance Requirements*,
"Support of crJSON format …") requires a validator's test harness to take
a validation time (RFC 3339) as its fourth input, next to the asset and
the two trust lists (step 367). The programme's test inputs are fixed;
without a fixed time their answers drift as the test certificates expire.
The same holds for this repository's own fixtures: `expired-signer-*`
already depends on the day the test runs.

A caller-given time is also a way to get a wrong `Trusted`: a time in the
past makes an expired signer valid again. That is the purpose in a test
and a mistake on a website. The default therefore stays the clock, and a
trusted timestamp keeps precedence, as §15.8.2 requires.

## Scope

**In scope**

- A validation time, optional, on `Verifier::verify()` and
  `FragmentedVerifier::verify()`, and on the command line as
  `--at <RFC 3339>`.
- It replaces "the current time" and nothing else. It is used wherever a
  manifest has no trusted timestamp: the signer's and CA certificates'
  validity, the certificate profile check, the trust chain (including an
  anchor's validity), the stapled and assertion OCSP responses, and CA
  revocation. That applies to the active manifest and to every ingredient
  manifest.
- The explanations that now say "checked at now" name the given time
  instead.

**Out of scope** (each needs its own spec before it may be built)

- The time used, reported as a field (crJSON's `validationTime`): the
  crJSON spec.
- A time for the TSA's own certificate. §15.8.2 judges it at the attested
  time, and so does this verifier. The given time does not touch it.
- `trust.trust_anchors` configurations with `notBefore`/`notAfter`
  (§14.4.1). The shared settings format cannot express them. Where an
  anchor's own certificate validity is checked, the given time applies.
- A setting for the time in the trust settings file. The time belongs to
  the call, not to the trust policy.

## Behavior

- **AC1 — an expired signer without a timestamp, judged at a time inside
  its validity**
  - Given `tests/Fixtures/timestamp/tsa-matrix/expired-signer-no-timestamp.png`
    with its settings (signer valid from `2026-10-08T05:30:24Z` to
    `2026-10-09T05:32:54Z`, measured 2026-10-23)
  - When verified without a time, and with `2026-10-09T05:31:00Z`
  - Then without a time the outcome is today's: `Invalid`,
    `signingCredential.expired`. With the time it is `Trusted`, with no
    `signingCredential.expired`, and the certificate explanation names
    `2026-10-09T05:31:00Z` as the time checked.

- **AC2 — the edges of the validity, and fractions**
  - Given the same file
  - When verified at `2026-10-09T05:32:54Z`, `2026-10-09T05:32:54.9Z`,
    `2026-10-09T05:32:55Z` and `2026-10-08T05:30:23Z`
  - Then the first two are `Trusted`: `notAfter` is inclusive (RFC 5280
    §4.1.2.5) and a fraction is rounded down to the second. The last two
    are `Invalid`, with the same codes as today for an expired and a
    not-yet-valid signer.

- **AC3 — a trusted timestamp wins**
  - Given `tests/Fixtures/timestamp/tsa-matrix/expired-signer-trusted-tsa.png`
    with its settings (`Trusted` today through the attested time)
  - When verified at `2099-01-01T00:00:00Z`, at `2020-01-01T00:00:00Z`
    and without a time
  - Then all three reports are equal, status for status and explanation
    for explanation.

- **AC4 — the OCSP response and the ingredient follow the given time**
  - Given `tests/Fixtures/c2pa-rs/ocsp.jpg`, a real file (no settings).
    Measured 2026-10-23: the active signer is valid `2024-10-15T00:00:00Z`
    to `2025-10-15T23:59:59Z`. Its stapled OCSP response is current from
    `2025-08-11T21:51:18Z` to `2025-08-18T21:51:18Z`. The ingredient's
    signer is valid `2025-05-08T18:14:36Z` to `2026-05-08T18:14:36Z`. The
    TSA is not trusted, so both manifests are judged at the current time.
  - When verified without a time, and at `2025-08-14T00:00:00Z`
  - Then without a time the outcome is today's: the response is "not
    current" (`signingCredential.ocsp.skipped`), and both signers are
    `signingCredential.expired`. At the given time the response is used
    (a status other than `ocsp.skipped`, named when the test is written),
    and neither the active manifest nor the ingredient carries
    `signingCredential.expired`.

- **AC5 — a time that is not RFC 3339 is refused** *(required: error path)*
  - Given `--at` with `2026-10-09` (no time), `2026-10-09T05:31:00` (no
    offset), `2026-02-30T00:00:00Z` (no such day), `yesterday`, an empty
    string, or `--at` as the last argument
  - When `bin/c2pa-verify` runs
  - Then it prints no report, names the fault on standard error and exits
    with 2. The API takes a `DateTimeInterface`, so it has no parsing to
    refuse. Offsets other than `Z` are accepted and converted
    (`2026-10-09T07:31:00+02:00` gives AC1's answer).

- **AC6 — nothing moves without a time**
  - Given every fixture and the drift-alarm corpora
  - When verified before and after the change, without a time
  - Then no state, status code or explanation changes. The comparison
    runs on a copy of `vendor/`, never a symlink.

## References

- Specification: C2PA 2.4 §15.8.2, §15.9, §15.9.1, §15.7, §14.4.1 (quoted
  above, read from the published 2.4 HTML on 2026-10-23); RFC 3339 §5.6;
  RFC 5280 §4.1.2.5.
- Programme: C2PA Conformance Program v0.2, *Additional Conformance
  Requirements Against the Content Credentials Specification*,
  `c2pa-org/conformance-public` `da71a72` (step 367).
- Oracle: none for a time other than now. `c2patool` 0.28.2 has no time
  option (`--help`), and `c2pa-rs` 0.91.1's settings have no validation
  time (searched in `src/settings/`). The criteria rest on the
  specification text, and AC6 pins the agreement with today's answers.
- Measured (2026-10-23): the validity windows and statuses named in AC1
  and AC4, from `bin/c2pa-verify` on those files.
- Reasoned: that rounding a fraction down is safe for certificates, whose
  validity is stated in whole seconds.

## API sketch

```php
// src/Verifier/Verifier.php
public function verify($stream, ?TrustSettings $settings = null, ?\DateTimeInterface $validationTime = null): VerificationReport

// src/Verifier/FragmentedVerifier.php
public function verify($init, iterable $fragments, ?TrustSettings $settings = null, ?\DateTimeInterface $validationTime = null): VerificationReport

// inside: one int, worked out once
$now = $validationTime?->getTimestamp() ?? time();   // getTimestamp() drops the fraction
$at = $timestamp->trustedTime() ?? $now;             // §15.8.2: the attested time first
```

The three checks stop calling `time()`. They receive the judged time as
an `int`, no longer `?int`, and the `$reason` text says "at the given
validation time" where it now says "now". `IngredientManifestCheck` gets
`$now` the same way.

Command line: `--at <time>` and `--at=<time>`, parsed with
`DateTimeImmutable::createFromFormat()` against RFC 3339 (with and
without a fraction). The parsed fields are checked to round-trip, so
`2026-02-30` is refused rather than rolled over to March.

The contract keeps 136 symbols (`public-surface.txt` lists names, not
parameters). An optional parameter is an addition: 0.8.0.

## Open questions

- None blocking. The four choices of step 367's follow-up were made by
  Maurice on 2026-10-23: a `?DateTimeInterface` third parameter, fractions
  rounded down, no report field yet, this number.
  *Status 2026-10-23 (step 369):* settled; approved without changes.

## Amendments

1. **2026-10-23, step 396, found by the review of 2026-10-23 (the low items, "doe 1 nog", Maurice van Loon).** Without a given time, `verify()` recorded `time()` once, and each check read `time()` again for itself (`CertificateProfileCheck`, `ChainCheck`, `OcspCheck`), as the API sketch above did not intend ("computed once"). Over a second boundary the checks could disagree with each other and with the recorded `validationTime`. Now `verify()` reads the clock once and passes it down: `$at = $trusted ?? $now ?? $clock`, for the active manifest and every ingredient manifest. The certificate explanation still says "checked at now" where no time was given: it is named, no longer inferred from a null time.
   - **AC6 — one clock reading**: with `inspect()` given a fixed clock and no validation time, the active signer of `c2pa-rs/ocsp.jpg` (valid 2024-10-15 to 2025-10-15) and the ingredient signer of `c2pa-rs/video1.mp4` (valid 2022-04-04 to 2023-04-04) are not expired at a clock inside their validity and are expired at one after it, "expired at" that clock and "checked at now"; the report's `validationTime` is the reading the checks used.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Verifier/ValidationTimeTest.php :: AC1: an expired signer without a timestamp is judged at the given time / SPEC-073 | src/Verifier/Verifier.php :: verify(), check() (`$at = $trusted ?? $now`); src/Trust/CertificateProfileCheck.php :: check(), checkLeaf() (`$timeName`) |
| AC2 | tests/Unit/Verifier/ValidationTimeTest.php :: AC2: the edges of the validity, and a fraction rounded down (4 datasets) / SPEC-073 | src/Verifier/Verifier.php :: verify() (`getTimestamp()`); src/Trust/CertificateProfileCheck.php :: checkLeaf() |
| AC3 | tests/Unit/Verifier/ValidationTimeTest.php :: AC3: a trusted timestamp wins over the given time / SPEC-073 | src/Verifier/Verifier.php :: check(); src/Verifier/IngredientManifestCheck.php :: manifest() |
| AC4 | tests/Unit/Verifier/ValidationTimeTest.php :: AC4: the stapled OCSP response and the ingredient follow the given time / SPEC-073 | src/Verifier/IngredientManifestCheck.php :: check(), manifest(); src/Verifier/Verifier.php :: check() (the revocation call) |
| AC5 | tests/Unit/Verifier/ValidationTimeTest.php :: AC5: a time that is not RFC 3339 is refused on the command line (5 datasets); AC5: --at as the last argument is refused; AC5: an offset other than Z is converted / SPEC-073 | src/Cli/Command.php :: run() (`--at`), rfc3339() |
| AC6 | the corpus before and after (notes/step-369-validation-time.md); every existing test unchanged | — |
| AC6 (amendment 1) | tests/Unit/Verifier/ValidationTimeTest.php :: AC6: without a given time, every check judges at the one clock reading verify() took, and says "now" (2 datasets); AC6: the report records the clock reading the checks used / SPEC-073 | src/Verifier/Verifier.php :: verify(), inspect(), check(); src/Verifier/IngredientManifestCheck.php :: check(), manifest() |

`src/Verifier/FragmentedVerifier.php :: verify()` passes the time on; no
criterion names it.

AC1's last clause (the explanation names the given time) has nothing to
say on a `Trusted` report, which carries no validity explanation. It is
tested where the explanation exists: AC2's two refused times, whose
`signingCredential.expired` says `checked at the given validation time`.

The tests were run red first: 7 failed, 7 passed. The five AC5 refusals
passed only because `--at` was an unknown option, so they were tightened
to require "RFC 3339" in the message. Then 13 failed and 1 passed: AC3
pins behaviour that already held, since PHP ignores an extra argument.
Three mutations were watched failing: the given time put before the
timestamp's (AC3 fails), the fraction rounded up (AC2 "nine tenths"
fails), and the ingredient check not given the time (AC4 fails).
`composer check` is green: 1,131 passed, 1 skipped.

The usage line (SPEC-019 AC9) is unchanged. `--at` is named in the lines
below it only, since this spec does not amend SPEC-019.

Amendment 1 (step 396): the clock tests red on the first build (2 of
3; the third, the recorded reading, held on a run within one second).
The first fix passed the clock on but let the certificate explanation say
"checked at the timestamp's time", because it inferred "now" from a null
time; the tests were sharpened to read the wording and the name is now
passed. Mutations, each failing: the active manifest ignoring the clock,
an ingredient ignoring it, "now" not named.
