# Step 40 — The timestamp measured before M6: five TSAs in bytes, c2patool's `timeStamp.*`, and what `ext-openssl` reaches

*2026-09-22.* M6 is the RFC 3161 timestamp: the `sigTst` / `sigTst2`
header of the COSE signature (C2PA 2.4 §14.6), a CMS `SignedData` from
a time-stamping authority whose signed content, the `TSTInfo`, holds a
`genTime` and a digest of what was countersigned. Without it a
certificate that has expired since signing cannot be judged (§14.6.1:
validity is judged at the timestamp's time when a trusted timestamp is
present; at the current time otherwise). Two corpora already show the
cost: the three Truepic files carry one-day certificates and are
`expired` here and `Valid` at c2patool (step 37). This step is the
measurement, as for M4 (step 23) and M5 (step 30): nothing is
implemented; the material feeds ADR-0004 and the specs after it.

Everything below labelled *measured* was run on 2026-09-22 with
OpenSSL 3.6.3, PHP 8.5, c2patool 0.27.22 (which reports `c2pa/0.90.22`
inside its binary — `strings`, measured) and the c2pa-rs sources at
tag `c2pa-v0.90.22` (fetched raw from GitHub for the files not in the
sparse clone) and at `main` (58eac79, the clone). *Reasoned* is what
was read from the specification and the code.

## 1. Which fixtures carry a timestamp, and where

| fixture | header | shape of the value | TSA (leaf CN) | genTime | imprint alg |
|---|---|---|---|---|---|
| `fixture-signed.{jpg,png,webp}` (own) | — | no timestamp | — | — | — |
| `nikon-20221019-building.jpg` | — | no timestamp; stays `expired` at now, as c2patool | — | — | — |
| `adobe-20220124-*.jpg` (20 files) | `sigTst` | `{tstTokens: [{val: bstr}]}`, the bstr a full `TimeStampResp` (`30 82 … 30 03 02 01 00` — status Granted, then the token) | DigiCert Timestamp 2022 | 2023-01-24 | sha256 |
| `truepic-20230212-*.jpg` (3) | `sigTst` | same, `TimeStampResp` | Truepic Lens Time-Stamping Authority (private root in the token) | 2023-02-12 | sha384 |
| `C.jpg`, `CA.jpg`, `boxhash.jpg`, `exp-test1.jpg`, `CA_ct.jpg`, … (c2pa-rs) | `sigTst` | same | DigiCert Timestamp 2023 | 2024-08-06 | sha256 |
| `C_with_CAWG_data.jpg`, `CACA.jpg`, `ocsp*.jpg` (c2pa-rs, claim v2) | `sigTst2` | `{tstTokens: [{val: bstr}]}`, the bstr the `TimeStampToken` itself (`ContentInfo`, `30 82 … 06 09 2a 86 48 86 f7 0d 01 07 02`) | DigiCert SHA256 RSA4096 Timestamp Responder 2025 1 | 2025-07-29 / 2025-10-16 | sha256 |

Measured with a scratch script over the project's own `CoseSign1`
(the unprotected header is decoded since M3) and `openssl ts -reply
-text` on the extracted bytes. Two shapes, then: the v1 header carries
the whole *response* (RFC 3161 §2.4.2, `TimeStampResp ::= SEQUENCE
{status PKIStatusInfo, timeStampToken TimeStampToken OPTIONAL}`) and
the v2 header carries the *token* (`ContentInfo` with `signedData`).
c2pa-rs reads both by trying the response first and falling back to
the token (`time_stamp/verify.rs`, reasoned from the code); C2PA 2.4
§14.6 says `sigTst2` holds the `TimeStampToken`.

## 2. What is countersigned — proven by hand on four tokens

c2pa-rs (`crypto/cose/sigtst.rs` at main, lines 185–195) builds the
bytes the TSA digested as `cose_countersign_data(data, protected)`:
the COSE `Sig_structure` (RFC 9052 §4.4) with context
`"CounterSignature"`, the protected header bytes, an empty
`external_aad`, and a payload. The payload differs by version:

- `sigTst` (v1): the payload is the **claim bytes** (the CBOR of the
  claim, the same bytes the signature covers);
- `sigTst2` (v2): the payload is the **signature bytes, wrapped as a
  CBOR byte string** (§14.6: the timestamp covers the signature, so
  that the time proves the signature existed).

Measured (a scratch script over the project's `CoseSign1` and
`Manifest::claimBytes()`, then `openssl ts -reply -text` for the
imprint):

| file | tbs | sha of tbs | `TSTInfo.messageImprint` |
|---|---|---|---|
| `C.jpg` | v1 | sha256 `64d055da…` | `64d055da…` ✓ |
| `adobe-20220124-C.jpg` | v1 | sha256 `0432594d…` | `0432594d…` ✓ |
| `truepic-20230212-camera.jpg` | v1 | sha384 `d8160464…` | `d8160464…` ✓ |
| `C_with_CAWG_data.jpg` | v2 | sha256 `37ab305c…` | `37ab305c…` ✓ |
| `adobe-20220124-E-sig-CA.jpg` | v1 | sha256 `c0a664eb…` | `d08b4bf6…` ✗ — c2patool: `timeStamp.mismatch` |

Four TSAs, two versions, two digest algorithms, one file tampered on
purpose — all agree with the reconstruction. The imprint's algorithm
comes from the `TSTInfo` itself (`messageImprint.hashAlgorithm`), not
from the COSE `alg`: Truepic signs ES256 and stamps sha384, DigiCert
sha256 regardless of the signer.

## 3. What c2patool 0.27.22 reports — the whole corpus

`jq` over the 41 oracle JSONs of the two external corpora (24 official,
17 c2pa-rs; our own three fixtures have no header; measured):

| code | count | where |
|---|---|---|
| `timeStamp.validated` + `timeStamp.trusted` | 31 files | DigiCert 2022/2023 and Truepic tokens |
| the same, plus `timeStamp.mismatch` on an ingredient | 3 | `CIE-sig-CA` (both corpora), `E-uri-CIE-sig-CA` — the tampered *ingredient* manifest next to the active manifest's `validated` |
| `timeStamp.validated` + `timeStamp.untrusted` | 2 | `CACA.jpg`, `C_with_CAWG_data.jpg` — the DigiCert **2025** responder |
| `timeStamp.mismatch` alone | 2 | `E-sig-CA` (both corpora) |
| `timeStamp.malformed` | 1 | `CA_ct.jpg` — "timestamp response had no TstInfo" |
| none | 2 | `nikon-20221019-building.jpg` (no header), `update_manifest.jpg` |

Three things to hold on to:

1. **`timeStamp.*` never makes a file `Invalid`.** Every `timeStamp`
   failure in `time_stamp/verify.rs` (0.90.22 and main) is logged
   `.informational(…)`; only `.validated` and `.trusted` are
   `.success(…)`. c2patool: `E-sig-CA` is `Invalid` for its signature,
   not for its timestamp; `CACA.jpg` with an `untrusted` timestamp is
   `Trusted`. The store's comment says why: *"Timestamps failures are
   not fatal according to C2PA spec"* (`cose_validator.rs`). What a
   failed timestamp does cost is the *time*: the certificate is then
   judged at now — that is how `exp-test1.jpg` is `Invalid` at
   c2patool with a `validated`, `trusted` timestamp (the signer's
   certificate had expired before the stamp; measured in step 39).
2. **`signature_info.time`** is the token's `genTime` rendered ISO
   (`2024-08-06T21:53:37+00:00` for `C.jpg`); absent when there is no
   header. SPEC-015 AC7 excludes this key until M6; M6 brings it in.
3. **`CA_ct.jpg`** ("ct": corrupt time) has a `genTime` of
   `20240806216337Z` — minute 63. OpenSSL prints "Bad time value" and
   otherwise parses the token; rasn refuses the `TSTInfo` outright,
   hence "no TstInfo" and `timeStamp.malformed`. A `GeneralizedTime`
   must therefore be *validated* as a date, not just pattern-matched
   — a fail-closed rule for the spec.

## 4. What the c2pa-rs verifier does, in order (`time_stamp/verify.rs`, 0.90.22)

Reasoned from the code, line-referenced in the scratch copy; the
status code after the arrow:

1. parse the response, else the token; no `SignedData` → `malformed`;
2. the token must carry certificates; find the signer by
   `SignerIdentifier` — `issuerAndSerialNumber` (all four TSAs here)
   or `subjectKeyIdentifier` (lines 129–135) → `malformed` if absent;
3. `TSTInfo` from `eContent`, imprint present → `malformed`;
4. the time: `genTime`, **replaced by a signed `signingTime`
   attribute when the signer carries one** (lines 183–216) —
   all five tokens do (`1.2.840.113549.1.9.5`, `UTCTime`), and
   `signingTime` equals `genTime` on every one of them (measured);
5. signed attributes: the `messageDigest` attribute must equal the
   digest of `eContent` → `malformed` when missing, `mismatch` when
   different;
6. the CMS signature over the signed attributes with the signer's
   public key → `untrusted` on failure (the code's word; the spec has
   no `timeStamp.invalid`);
7. the signer's certificate must be valid **at the token's time**
   (line 484) → `timeStamp.outsideValidity`;
8. the imprint must equal the digest of the countersigned bytes of
   §2 → `validated` / `mismatch`;
9. only when `verify_timestamp_trust` (settings, default `true`):
   the end-entity profile with the EKU list replaced by
   `timeStamping` (1.3.6.1.5.5.7.3.8) alone, then
   `check_certificate_trust` against the anchors at the token's time
   → `untrusted`; then `trusted`.

The order matters for us in one place: c2pa-rs checks the *signer
certificate's* validity (7) before the imprint (8), so a `mismatch`
is only reported for tokens whose TSA certificate was valid.

## 5. Unresolved: `timeStamp.trusted` without anchors

The 0.90.22 sources say that with no trust anchors configured,
`check_certificate_trust` returns `CertificateNotTrusted`
(`raw_signature/openssl/check_certificate_trust.rs` line 32 and the
rust-native twin, line 34 — both fetched at the tag), which would log
`timeStamp.untrusted` for every token. Measured instead: c2patool
0.27.22 logs `timeStamp.trusted` for the DigiCert 2022/2023 and
Truepic TSAs with **no** settings, with the full test trust settings
(an EC test root that can anchor none of them), and with
`verify.verify_timestamp_trust` `true` or `false`; and `untrusted`
for the DigiCert 2025 responder in every one of those runs. The
binary embeds seven PEM blocks, all the C2PA *test* certificates (for
`c2patool sign`); the strings "Truepic", "DigiCert Assured ID Root
CA" and both DigiCert TimeStamping CA names do not occur in it. The
2023 and 2025 leaf certificates differ only in issuer, dates and URLs
(`diff` of `openssl x509 -text`); the 2025 token's CMS signature
verifies with `openssl_verify` (§6). So the distinction is not the
profile and not the signature, and I cannot derive it from the source
I read. Two consequences, for the ADR and the spec:

- **c2patool's `timeStamp.trusted` is not evidence that a TSA is on
  any list.** The drift alarms must not be made to demand it.
- Our own rule will follow §14.6 and the code as written: a TSA is
  trusted only when its chain reaches a configured anchor (the same
  `trust_anchors`, as c2pa-rs — *"the C2PA trust anchors and
  timestamping trust anchors can be added separately"*,
  `certificate_trust_policy.rs`), `untrusted` otherwise — and the
  divergence from c2patool on 34 files is of the "same verdict,
  different informational code" kind, named in the alarm like the
  others.

## 6. What `ext-openssl` reaches, and what it does not

Measured on the `C.jpg` token (5938 bytes), the CAWG and CACA tokens:

| need | `ext-openssl` | measured |
|---|---|---|
| `TSTInfo` fields (`genTime`, imprint, alg, serial, nonce) | **not exposed** by any `openssl_*` function; the CLI's `openssl ts -reply -text` prints them, but the CLI is `exec` | a DER reader is needed |
| the token's certificates | `openssl_pkcs7_read()` fails on the DER (`no start line`) but returns all three as PEM when the same bytes are base64-wrapped as `-----BEGIN PKCS7-----` (measured, `C.jpg`) | reachable without a DER reader; or `openssl_x509_read` on each DER once cut out, as in M5 |
| CMS signature over `signedAttrs` | `openssl_cms_verify($file, OPENSSL_CMS_NOVERIFY, …, OPENSSL_ENCODING_DER)` → `true` — **file-based only** (a temp file per token); `openssl_cms_verify(…, 0, null, [$anchor])` → `certificate verify error`: with the real anchor the reason is `unsuitable certificate purpose` (OpenSSL's CMS verify demands the S/MIME purpose; the TSA leaf has EKU `timeStamping` only, and PHP exposes no `-purpose` switch) | signature yes, chain **no** |
| the same by hand | cut `signedAttrs` out of the DER, replace its `[0]` tag (`A0`) by `SET` (`31`, RFC 5652 §5.4), `openssl_verify($attrs, $sig, $leafKey, OPENSSL_ALGO_SHA256)` → `1`; one bit flipped → `0`; `messageDigest` attribute `==` `sha256(eContent)` → `true`; the 2025 RSA-4096 token → `1` likewise | **works without a file** |
| TSA chain to an anchor | our M5 `ChainCheck` (`openssl_x509_verify` link by link, allowed list first) applies unchanged once the certificates are cut out; the token's third certificate is a **cross-certificate** (DigiCert Trusted Root G4 issued by DigiCert Assured ID Root CA), not a self-signed root — an anchor by subject *and* key, as SPEC-014 already matches | reasoned; M5 code |
| signature algorithms seen | `rsaEncryption` with sha256 (DigiCert 2022/2023/2025); `sha384WithRSAEncryption` as the *signature* algorithm with sha384 (Truepic) — the digest can come from either field | two spellings to accept |

What `ext-openssl` cannot do at all is read `TSTInfo` and `SignerInfo`. Every
route to M6 therefore contains a DER reader for a small, fixed
grammar: `SEQUENCE`, `SET`, `[n]` context tags, `INTEGER`, `OCTET
STRING`, `OID`, `NULL`, `UTCTime`, `GeneralizedTime`, `BOOLEAN` —
definite lengths only (0 indefinite lengths in the five tokens,
measured with `openssl asn1parse`; DER forbids them). With that reader in hand, the CMS
signature is one `openssl_verify` and needs no temp file; the chain
is M5's code. ADR-0003 left exactly this open ("RFC 3161 open until
M6 / ADR-0004").

## 7. Scratch material kept out of the repository

`scratchpad/tst/`: the four header values as `.der`, the extracted
tokens, `c-content.der` (the 112-byte `TSTInfo` of `C.jpg`), the
certificate bundles; `dump-sigtst.php` and `tbs.php` (the
reconstruction of §2 over the project's classes); the 0.90.22 sources
`verify`, `sigtst`, `cose_validator`, `store`, `claim`, both
`check_certificate_trust`. Nothing of it is a fixture yet; the
fixtures M6 measures against already sit in the three corpora (33
timestamped files across five TSAs, one corrupt time, one mismatch).

## What this settles for ADR-0004 and the specs

- A DER reader is unavoidable; its grammar is small and closed (§6).
  Own or `phpseclib/phpseclib` (ASN.1 + X.509, one more dependency,
  no `ext-openssl` reliance) is the ADR's question.
- `openssl_cms_verify` is not needed: the CMS signature verifies with
  `openssl_verify` over re-tagged bytes (§6), and its chain check is
  unusable for a TSA anyway.
- The checks and codes are c2pa-rs's list (§4) with one deliberate
  difference: trust only through configured anchors (§5).
- `timeStamp.*` is informational; the timestamp's one *effect* on the
  verdict is the time SPEC-015 judges validity at (`CertificateProfileCheck`
  already takes an epoch, `null` = now). That removes the two
  `_NO_TIMESTAMP` exceptions (Truepic; `ocsp*.jpg`), brings
  `signature_info.time` under AC7, and must keep Nikon and
  `exp-test1.jpg` `Invalid`.
- Two more fail-closed rules from the corpus: a `GeneralizedTime`
  that is not a date is `malformed` (`CA_ct.jpg`); a `signingTime`
  attribute, when present, is the time (c2pa-rs), and the two must
  agree with each other within the token or the token is `malformed`
  (our addition — a proposal for the spec, not measured anywhere).
