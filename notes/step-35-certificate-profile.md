# Step 35 — `CertificateProfileCheck` and `signature_info`: ten tests red → green; M5 complete

*2026-09-21.* SPEC-015 implemented. `Trusted` now means what §14 means:
a chain of signatures to a supplied anchor *and* a leaf that is a C2PA
signing certificate. And the verifier's report carries who signed —
`signature_info` — as c2patool prints it.

## What was built

- `Trust\Certificate` grew: version, validity, signature algorithm, key
  type / bits / curve, KU as OpenSSL names, EKU as OIDs (OpenSSL's eight
  long names mapped, a dotted string kept), the two key identifiers,
  the O, the serial in decimal (`hexToDecimal()`: base-16 → base-10 on
  strings, twenty lines, no `gmp`). `fromParsed()` takes the parse
  arrays as given — the seam the tests use for rules no re-signed file
  can show. One thing measured on the way: an RSASSA-PSS public key is
  type −1 to `openssl_pkey_get_details()` and carries no `rsa` array;
  the SPKI algorithm OID in the key's DER (`1.2.840.113549.1.1.10`) says
  it is RSA — a byte search, not ASN.1 — and without that the Adobe
  fixture's PS256 leaf was "key of type other".
- `Trust\CertificateProfileCheck` — `check()` on the manifest's leaf,
  `checkLeaf()` on any `Certificate`; the eight rules in the spec's
  order, one `signingCredential.invalid` per fault, `.expired` for
  validity at `$at` (now until M6), every message with the numbers.
- `Verifier`: the profile check always, right after the signature (a
  property of the signature's certificate, measured in step 34a); the
  trust check with no settings runs against no anchors and says
  `untrusted`, as c2patool does — SPEC-014 amendment 1, found by
  SPEC-015 AC9 (M5's "done when" says exactly that: "test cert without
  trust file → `signingCredential.untrusted`"); `checksPerformed` is
  now `signature, certificate, trust, hashedUris, dataHash`.
- `VerificationReport`: `signature_info` under the active manifest —
  `{alg: Es256|…|Ps256|Ed25519, issuer: <leaf O>, common_name, cert_serial_number: <decimal>}`.
- `StatusCode` at 24.

## Measured

- Before: `10 failed (15 assertions)` — step 34b.
- First run of the implementation: `5 failed, 5 passed`. Three causes:
  the RSA-PSS key type (above); c2patool's `signature_info` carrying
  `time` on the Adobe file (from the timestamp — M6's field, excluded
  from AC7 until then); and the two design consequences that the
  SPEC-013/014 tests then also felt — `certificate` and `trust` in
  every `checksPerformed`, `untrusted` on every file verified without
  settings. Ten older tests adjusted, three amendments recorded
  (SPEC-013 #4, SPEC-014 #1, SPEC-015 #2).
- After: `composer check` → Pint passed, PHPStan `[OK] No errors`,
  Deptrac `Violations 0`, Pest **`203 passed (2071 assertions)`**.
- AC9, M5's "done when", through the front door: the four fixtures
  without settings `Valid` with `signingCredential.untrusted`; with the
  full settings `Trusted` with `.trusted`; with `verify_trust: false`
  `Valid` with no credential code — each equal to c2patool's state and
  code set.
- AC10: 22 corpus files with the full settings and 12 profile variants
  with the throw-away root — state and credential-code set equal to
  the oracle's on every one (the parse-fault file compared on state
  only, as SPEC-013's subset rule allows).
- AC7: `signature_info` equal to c2patool's four fields on the four
  fixtures and on `good`; the PNG's serial
  `640229841392226413189608867977836244731148734950` from hex
  `7024E6247605F1D65F1B477551D4FAFCB5ED91E6`; the sister parser's
  `signer()` reads issuer, CN and algorithm from our JSON.

## M5 complete

| "done when" (the brief) | measured |
|---|---|
| verdicts equal `bin/verify.sh`'s, with and without the trust file | AC9: `Valid`/`untrusted` without, `Trusted`/`trusted` with, on all four fixtures — c2patool's state and codes |
| test cert without trust file → `signingCredential.untrusted` | AC9, and SPEC-013's corpus: every file verified without settings carries it |

What `Trusted` still does not cover: the signing *time* (validity is
judged at now; M6 reads the timestamp and passes `$at`), unknown critical
extensions on the leaf (the one rule `ext-openssl` cannot see; M6's
ASN.1 reader closes it), ingredients' signers (M7).

## Next

M6, RFC 3161: parse `sigTst` / `sigTst2` (a CMS SignedData holding a
`TSTInfo` — ASN.1 that `ext-openssl` does not expose), verify the TSA's
signature over the timestamp token, check that the token's message
imprint is the hash of the claim signature, take `genTime` as the
signing time for the certificate's validity, and report `hasTimestamp`
/ `timeStamp.*` as c2patool does on the Adobe fixture (which has one:
`claimSignature.insideValidity`, `signature_info.time`). It starts with
the ADR ADR-0003 left open: a small own DER reader, or `phpseclib`.
