# Step 92 — SPEC-030 tests first, and the fixtures that had to be made

*2026-09-22.* Ten criteria, nine of them red.

```
Tests: 9 failed, 412 passed (7509 assertions)
```

Nine fail on the same two absences: `Trust\OcspCheck` and four
`StatusCode` cases. AC9 passes from birth, by design — it is the alarm,
not a criterion that waits for code.

## An amendment before a test was written

AC1 asked for `ocsp.jpg` to be verified **without** trust settings and for
its state to be `Valid`. Measured, both halves are wrong, and they are
wrong in the same place.

```
no settings:            Invalid   timeStamp.untrusted, signingCredential.expired
full-plus-digicert-g4:  Valid     timeStamp.trusted (attested 2025-08-13)
```

Without an anchor for the Adobe TSA this verifier cannot trust the
timestamp, so it judges the 2025 signer at *now* and calls it expired,
where c2patool says `Valid` because it falls back to the operating
system's trust store. **That is the same divergence step 87b measured on
`video1.mp4`, on a second file** — design, not fault: trust here comes
from the settings file and from nowhere else.

And the judged time is exactly what decides this spec's own question. The
stapled response ran from 2025-08-11 to 2025-08-18. At *now* it is stale,
so SPEC-030's rule gives `skipped` — the opposite of what AC1 asked for.
Under the anchor the judged time is the attested 2025-08-13 and the
response is fresh.

So **the same file is both criteria**: AC1 with the anchor, AC6 without
it. Which one a run gets depends on the anchor, not on the bytes. That is
a better pair of tests than the spec originally asked for, and it came
from measuring rather than from writing what sounded right.

AC3 also changed shape, and that one costs something — see below.

## The fixtures nobody could download

No public file carries a `revoked` stapled response; step 90 looked.
`bin/make-ocsp-variants.php` makes four:

```
revoked.der     1390 bytes   keyCompromise
good.der        1368 bytes
removed.der     1390 bytes   removeFromCRL, which is not a revocation
other-good.der  1368 bytes   about a different certificate — AC4
ca.crt, signer.crt, other.crt
```

**Keys never enter this repository, not even test keys.** The script
generates a CA and two end-entity certificates in a temporary directory,
signs the responses with them, overwrites every `.key` file with zeroes,
unlinks them, removes the directory, and then refuses to finish if a key
is anywhere in the fixtures. What is committed is DER responses and three
**public** certificates.

One detail is worth the line it takes: the responses are made with
`-ndays 3650`. `openssl ocsp` sets `thisUpdate` to now and gives no way to
fix it, so a realistic seven-day window would make these tests fail on a
date nobody chose. The stale case is not simulated at all — AC6 uses a
real one, the Adobe response that expired on 2025-08-18.

## What AC3 gives up

It was written as a whole asset carrying a `revoked` response. Building
that would mean **encoding** a COSE unprotected header, and this verifier
has no CBOR writer: it decodes and never emits. AC3 is therefore measured
at the check's seam, against a DER response and the certificates it was
issued under.

What is lost is end-to-end coverage of the revoked path. AC1, AC2, AC5 and
AC6 drive a real file into the check, and AC3 proves the rule, but no test
here takes a whole asset to `Invalid` through revocation. It is written
down rather than assumed, and it is why AC3 also asserts `isFailure()` on
the code itself.

And AC3 is **the first acceptance criterion in this project with no second
implementation behind it**. c2patool emits no OCSP code of its own on
either file that carries a stapled response, so there is nothing to
compare against: AC3 is measured against `openssl ocsp -resp_text` and the
specification text alone. That is weaker than every other criterion here
and should be read as weaker.

## AC9 is the alarm, and it is green on purpose

Twelve verdicts — six files, with and without the anchor — recorded as
they stand the moment before the implementation:

```
fixture-signed.{jpg,png,webp,mp4}   Valid / Trusted
c2pa-rs/ocsp.jpg                    Invalid / Valid
c2pa-rs/ocsp_with_assertion.jpg     Invalid / Valid
```

If any of the twelve moves in 92b, a stapled response changed a verdict,
which is the one thing SPEC-030 promises never to do. The first draft of
this test asserted nothing until the codes existed, and Pest called it
risky — correctly. A test that cannot fail today is not an alarm.

## Red on purpose

- Pest: 9 failed on the missing `OcspCheck` and the four missing
  `StatusCode` cases; 412 pass.
- Pint and `bin/spec-check.php` (31 specs, 36 test files) are clean.
- PHPStan is expected to fail on the same absences until 92b, as in every
  tests-first step of this project.

92b writes `Trust\OcspCheck`, the four status codes, the wiring in the
trust layer after the chain and the timestamp, and — because `StatusCode`
is one of the ten contract classes — a SPEC-025 amendment, since the
recorded public surface grows by four lines.

---

# Step 92b — SPEC-030 implemented: revocation without a network

*2026-09-22.* Ten green, 421 in the suite (7584 assertions), PHPStan max
clean, Deptrac at zero.

```
Tests: 421 passed (7584 assertions)
```

## What was built

`Trust\OcspCheck` reads `rVals.ocspVals`, parses RFC 6960, matches a
`SingleResponse`'s `CertID` to the signer's certificate, verifies the
response under a responder tied to the signer's own issuer, and applies
`certStatus` at the judged time — a trusted timestamp's, else now.
`StatusCode` grew by four cases. `Verifier` calls it after the chain (which
supplies the issuer) and after the timestamp (which supplies the time), and
`checksPerformed` gained `revocation`.

## Two layer lines moved, deliberately

The trust layer may now depend on `Asn1` and on `Cbor`. An RFC 6960
response is DER, and it arrives as a CBOR byte string; both are low-level
decoders that `Cose` already depends on, so no cycle appears. Deptrac
refused the build until each was written down, which is what it is for.

## Three things the code got wrong first, and how they surfaced

**The OID.** The spec said `id-pkix-ocsp-basic` is `1.3.6.1.5.5.7.48.1`.
It is not: that arc is *id-pkix-ocsp*, the access method in an AIA
extension, and the response type is one deeper, `…48.1.1`. The fixture
said so — "is of type 1.3.6.1.5.5.7.48.1.1, not id-pkix-ocsp-basic" — and
the fixture was right. Had the code followed the spec as written, **every
stapled response would have been skipped and the whole feature would have
been silently inert**, with all ten criteria passing for the wrong reason
on the two that matter. SPEC-030 amendment 2 records it rather than
quietly correcting it.

**Two tests passed while the OID was wrong.** AC7 and AC8 were green
before the fix and red after it. That is worth saying plainly: they were
passing because everything was being skipped, which is exactly the failure
mode "assert that something specific is PRESENT" exists to catch.

**An empty CBOR map.** PHPStan objected to an always-true ternary, and
following it led somewhere real: an empty CBOR map and an empty list are
the same PHP value, so `rVals: {}` was being reported as "a list, not a
map". It now reads as a header that carries nothing.

## The report grew on every file, and 24 tests said so

`signingCredential.ocsp.skipped` appears on every asset without a stapled
response, and `revocation` in every `checksPerformed`. Twenty-four tests
asserted exact lists and failed — the alarms working, not breaking. Each
was updated to record the growth rather than to stop looking:

- the drift alarms that count `StatusCode::cases()`: 41 → 45;
- the alarms that walk every code weighing success against failure: the
  `signingCredential.ocsp.` family added to their skip lists, as
  SPEC-017's `timeStamp.` and SPEC-020's `ingredient.` families were;
- two helpers that filtered statuses by the prefix `signingCredential`,
  which now catches a family that is not theirs — narrowed, with the
  reason in the code;
- `bin/api-check.php` refused the run before any of this was written down:
  "public but not recorded" on all four cases. 95 symbols → 99, and
  SPEC-025 amendment 3.

## A second divergence from c2patool, by design

c2patool 0.27.22 emits no OCSP status code of its own on either file that
carries a stapled response. This verifier emits one on **every** file. So
`VerifierTest` AC1, which asserts that every status we report is one
c2patool also reports, now skips this family — next to the `timeStamp.
untrusted` exception of ADR-0004, and for a related reason: where c2patool
is quiet about what it did not do, this project is not.

## What is still open

SPEC-030's own limits are unchanged: no online OCSP, no AIA, no CRL, and
no revocation for any certificate but the signer's leaf. And AC3 still has
no second implementation behind it. `docs/conformance.md` is updated —
five gaps closed, **17 left**, none of which can produce a wrong `Trusted`.
