# Step 41a — The SPEC-016 tests, seen red: 64 tests on the DER reader and the timestamp token, and the patches proven with `asn1parse`

*2026-09-22.* SPEC-016 approved; this is the tests-first half of step
41. Two files, `tests/Unit/Asn1/DerReaderTest.php` (AC1–AC2, 28 tests on
hand-made byte vectors) and `tests/Unit/Timestamp/TimeStampTokenTest.php`
(AC3–AC10, 36 tests on tokens cut out of the corpus files), plus the
`Asn1` layer in `deptrac.yaml`. Nothing under `src/` yet.

## Red, for the right reason

```
$ vendor/bin/pest --group=SPEC-016
Tests:    64 failed (23 assertions)
$ vendor/bin/pest
Tests:    64 failed, 206 passed (2276 assertions)
```

Every failure names a missing class: `Asn1\DerReader` (28 tests),
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

Next: step 41b — `src/Asn1/` and `src/Timestamp/` until the 64 are
green and the 206 stay green.
