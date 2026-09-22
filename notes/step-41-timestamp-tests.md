# Step 41 — The SPEC-016 tests seen red (41a), then the DER reader and the timestamp token until they are green (41b)

*2026-09-22.* SPEC-016 approved; this is the tests-first half of step
41. Two files, `tests/Unit/Asn1/DerReaderTest.php` (AC1–AC2, 27 tests on
hand-made byte vectors) and `tests/Unit/Timestamp/TimeStampTokenTest.php`
(AC3–AC10, 37 tests on tokens cut out of the corpus files), plus the
`Asn1` layer in `deptrac.yaml`. Nothing under `src/` yet.

## Red, for the right reason

```
$ vendor/bin/pest --group=SPEC-016
Tests:    64 failed (23 assertions)
$ vendor/bin/pest
Tests:    64 failed, 206 passed (2276 assertions)
```

Every failure names a missing class: `Asn1\DerReader` (27 tests, and one
of the token tests that reads a certificate with it),
`Timestamp\TimeStampToken` (30), `Timestamp\TimestampHeader` (5) — the
three entry points of the spec. The 23 assertions that did run are the
preconditions the tests check *before* calling the code under test
(a header value is 5951 bytes and starts `3082173b300302010`, the
`eContentType` OID sits at offset 55, …): those pass now and pin the
offsets the patches rely on.

## No new fixtures

The tokens come out of the files already in the repository: a
test-side `spec016Cose()` runs the format detector, the three
extractors, the JUMBF parser and `ManifestStore` — all M1–M3 code — and
`CoseSign1::fromBytes()` gives the unprotected header. That is the same
road the verifier walks, so a token the tests read is a token the
verifier will meet.

## The patches of AC6/AC7, proven before the tests were run

AC7 needs eleven tokens each broken in one way. Editing DER by hand is
where a test lies without knowing it — shorten one field and every
enclosing length is wrong, and a "refusal" then proves nothing. So the
test file carries a small DER *walker* of its own (`spec016Element`,
`spec016Splice`: read tag and length, find the path of elements
enclosing an offset, replace bytes, re-encode every enclosing length
DER-minimally, descend into an `OCTET STRING` only when it wraps a
`SEQUENCE` — the `eContent` — never into a digest). It is deliberately
independent of the reader under test.

Measured: the same helpers, copied into a scratch script, produced the
twelve patched values (eleven for AC7, the `rejection` response for
AC6), each written to disk and run through `openssl asn1parse -inform
DER`. **All twelve parse**, and a structural diff against the original
(offsets and lengths stripped) shows exactly the intended change and
nothing else:

| patch | asn1parse shows | bytes |
|---|---|---|
| eContentType `…16.1.4` → `…16.1.5` | `id-smime-ct-TSTInfo` → `id-smime-ct-TDTInfo` (a real OID, OpenSSL names it) | ±0 |
| two SignerInfos | a second SignerInfo `SEQUENCE` under the `SET`, every enclosing length grown | +886 |
| zero SignerInfos | the `SET` empty | −888 |
| TSTInfo version 2 | `306E020102…` in the `eContent` hex dump | ±0 |
| imprint of 31 bytes | inside the `eContent` (`-strparse`): `OCTET STRING l=31`, `messageImprint l=48`, `TSTInfo l=109` | −1 |
| no signedAttrs | the `[0]` and its five attributes gone | −212 |
| no messageDigest | that one attribute gone | −49 |
| contentType attribute ≠ eContentType | the attribute's OID → `…TDTInfo`, the `eContentType` untouched | ±0 |
| `[1]` extraCert | the first certificate's `SEQUENCE` → `cont [ 1 ]` | ±0 |
| no certificates | the `[0]` with its three certificates gone; `SignedData` from 5919 to 1042 bytes | −4877 |
| a critical TSTInfo extension | inside the `eContent`: `cont [ 3 ]` → `SEQUENCE` → `SEQUENCE { OBJECT 1.2.3.4, BOOLEAN 255, OCTET STRING }` after the nonce; the `[0]` and `OCTET STRING` headers grow to long form (the TSTInfo passes 127 bytes) | +18 |
| PKIStatus 2, "bad request" | `INTEGER 02`, `SEQUENCE { UTF8STRING "bad request" }` in place of `INTEGER 00` | +15 |

Two faults in the helper came out of this and were fixed before
anything was committed: the imprint's `OCTET STRING` was not on the
re-encoding path (its length stayed 32 while its contents shrank —
"Error in encoding" from `asn1parse`), and the hand-made `Extension`
`SEQUENCE` claimed 11 bytes for 10. A third fault showed up as a
runaway: the first walker descended into every `OCTET STRING`, digest
bytes included, and read garbage lengths until memory ran out; hence
the "only when it wraps a SEQUENCE" rule.

## Three measurements that amended the spec (amendment 1)

- **38 timestamped files, not 37.** The step-40 count was over JPEGs;
  the walk over all three formats (`glob *.{jpg,png,webp}` on the
  three corpus directories) adds `c2pa-rs/exp-test1.png`. Per file:
  one token each, `sigTst` on 36, `sigTst2` on `CACA.jpg` and
  `C_with_CAWG_data.jpg`; **no file carries both** — AC8's refusal of
  a double header is stricter than c2pa-rs and now known to cost
  nothing on the corpora. `prerelease.jpg` and `update_manifest.jpg`
  are refused earlier (M2 refusals, not this spec's) and skipped.
- **The bounds.** `openssl asn1parse` over the 38 tokens: the deepest
  nesting is `d=18` (nineteen levels) and the largest element count 311
  — both on the Truepic tokens (the `TSA` `GeneralName` and the
  `signingCertificateV2` attribute nest deeply). AC10 now pins
  `maxDepth` 20 and `maxElements` 512 as the ceiling every corpus token
  must fit; the defaults (32 / 65 536) leave room.
- **Truepic's signed attributes** carry `signingCertificateV2` as well
  as `CMSAlgorithmProtection`; the spec's parenthesis named only the
  latter.

`docs/adr/ADR-0004` had the same JPEG-only count in one sentence; the
sentence now says what was measured. The decision is untouched.

## What the tests pin, in numbers

- AC1: 8 tests, every value from X.690 by hand — including the decimal
  of `C.jpg`'s serial (`8265249780176541439333781366280615849`, via
  SPEC-015's `hexToDecimal`), the epoch of `240806215337Z`
  (1722981217) and the century rule (`99…` → 1999, `49…` → 2049).
- AC2: 16 malformed vectors + 3 bound tests; each message must name an
  offset that lies within the input.
- AC3–AC5: 5 × 3 tests over the five tokens of step 40 (policy,
  algorithm, imprint prefix, serial, `genTime`, nonce, accuracy, TSA;
  `SignedData` and `SignerInfo` field by field; `openssl_verify` = 1
  over the re-tagged attributes, 0 with one bit flipped).
- AC6: 3 tests, both wrappers cross-wise, `grantedWithMods`,
  `rejection`, no token, not-`signedData`.
- AC7: 12 tests (the eleven rules and the unpatched control).
- AC8: 5 tests (the header shape, `maxTokens`, `maxBytes` — with the
  reader's limit lowered to 64 bytes in the test, because a 1 MiB
  literal in a failing test's stack trace exhausted PHPUnit's 128 MB;
  the default 1 048 576 is asserted as a constant).
- AC9: 1 test — `CA_ct.jpg`: `genTime`, `63`, `offset 87`, and
  c2patool's `timeStamp.malformed` read from its JSON.
- AC10: 1 test over the 38.

---

# 41b — Green: `src/Asn1/` and `src/Timestamp/`

*2026-09-22, the same day.* Eleven classes, 270 tests green
(`composer check`: spec-check OK, Pint, PHPStan max 0 errors, Deptrac 0
violations, `Tests: 270 passed (2889 assertions)`).

## What was written

- `Asn1\DerReader` — one recursive `element()` over the input: identifier
  (class, constructed, tag ≤ 30), length (short form; long form of 1–4
  bytes, refused when not minimal, when indefinite, when `FF`), content
  octets, and for a constructed element its children read until the
  contents end exactly. Bounds first: `maxBytes` before anything,
  `maxDepth` and `maxElements` on entry to every element. 120 lines.
- `Asn1\Der` — the element with its typed accessors. `integer()` is a
  decimal string through `Bytes::hexToDecimal` (the routine moved from
  `Trust\Certificate` to `Support\Bytes`, because `Asn1` may depend on
  `Support` only; `Certificate::hexToDecimal` delegates). `oid()` folds
  the first two arcs as X.690 §8.19.4 says and refuses a leading `80`
  byte in a subidentifier. `time()` validates month, day (`checkdate`),
  hour, minute and second before `gmmktime` — minute 63 and 31 February
  are refusals naming the field. `encoded()` rebuilds the header from
  the fields and appends the contents, so a structure can hand
  `signedAttrs`, an issuer `Name` or a certificate on as bytes.
- `Timestamp\TimestampHeader` — the CBOR shape; both names refused.
- `Timestamp\TimeStampToken` — the wrapper decision by the first child,
  `PKIStatus` 0/1 or a refusal that names the status and the
  `statusString`, then `ContentInfo` → `SignedData` → `TSTInfo`.
- `Timestamp\SignedData` — the four required fields and the two optional
  tagged ones in order; `certificate` choices only; exactly one
  `SignerInfo`; and, from the first green run, `signerCertificate()`.
- `Timestamp\SignerInfo` — `sid` as either choice; `signedAttrs`
  required; the three named attributes typed, the rest kept by OID;
  `signedAttributesForVerification()` = `31` + the rest.
- `Timestamp\TstInfo` — the five required fields, the optional tail by
  tag in RFC 3161's order, the digest length against the algorithm
  (sha256/384/512 only; anything else refused), the critical-extension
  refusal, an unexpected trailing field refused.

## What the first green run corrected (SPEC-016 amendment 2)

Seven of 37 token tests failed on the first run with all classes in
place, and every one was a *test literal* or a *spec assumption*, not
the reader:

1. **The signer is not the first certificate.** Truepic's token lists
   `RootCA`, `TimestampingCA`, then the TSA; DigiCert's lists the TSA
   first. AC4 compared the wrong CN and AC5 verified against the root's
   key (`openssl_verify` → 0 — the falsification working as intended).
   The fix is in the code, not the test: `SignedData::signerCertificate()`
   finds the certificate the `sid` names, by issuer DER + serial or by
   subjectKeyIdentifier, reading each certificate's `TBSCertificate` with
   the same reader (the SKI path is reasoned: no corpus token uses it).
2. **Two certificates, not three**, in the `ocsp*.jpg` tokens — and a
   TSA this corpus had not shown yet: "Adobe SHA256 ECC256 Timestamp
   Responder 2025 1", an **ECDSA** timestamp signature under the 2025
   DigiCert CA. SPEC-017 gets `ecdsa-with-SHA256` from this.
3. `DigiCert Timestamp 2022 - 2` is the Adobe TSA's full CN (step 40's
   note had it truncated).
4. `genTime` sits at offset **86** of the TSTInfo, not 87.
5. The `TimeStampResp` head literal had lost a hex digit.
6. The OID test vector `06 0a …` claimed ten bytes for nine — the very
   fault AC2 exists to catch, caught by the reader in AC1.
7. The critical-extension patch used `[3]` with an EXPLICIT wrapper;
   RFC 3161 says `extensions [1] IMPLICIT Extensions`. The reader refused
   the `[3]` too — as an unexpected field — but a test must break what
   it claims to break.

Measured, not assumed: the counts above come from the failing run; the
certificate order from `openssl pkcs7 -print_certs` on the Truepic
token; the ECDSA TSA from the same on `ocsp.jpg`'s token.

## Test bookkeeping

The two files first used `describe()` blocks with one `->group()` each;
PHPStan (Pest's stubs) does not know `DescribeCall::group()`, and the
other sixteen test files tag every `test()` — so these do too, the
criterion's title folded into each test name. `$this->fail()` was
replaced by a `$thrown` variable and `toBeInstanceOf`, as the other
files do. Nothing about what is asserted changed.

## Where M6 stands

The bytes are data. What is still to come is SPEC-017: the CMS
signature (RSA PKCS#1 v1.5 and, new, ECDSA), `messageDigest` against
`eContent`, the imprint against the countersigned bytes, the TSA's
profile and chain through M5, the six `timeStamp.*` codes,
`signature_info.time`, and the time handed to SPEC-015.

