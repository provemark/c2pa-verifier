# ADR-0004: RFC 3161 — a small DER reader written here; the CMS signature on `openssl_verify`; TSA trust only through configured anchors

| Field    | Value                          |
|----------|--------------------------------|
| Status   | accepted                       |
| Date     | 2026-09-22                     |
| Decided  | Maurice van Loon               |

## Context

ADR-0003 closed M5 and left one thing open, on purpose: "RFC 3161
stays open until M6 … that milestone measures whether a small own DER
reader or `phpseclib` is the smaller risk." Step 40 is that
measurement (`notes/step-40-timestamp-measured.md`); this ADR rests on
it and quotes nothing that was not measured or read there.

What M6 has to do (C2PA 2.4 §14.6, §15; c2pa-rs `time_stamp/verify.rs`):

1. take the `sigTst` (claim v1: a whole `TimeStampResp`) or `sigTst2`
   (claim v2: the `TimeStampToken`) value out of the COSE unprotected
   header — already decoded since M3;
2. read the CMS `SignedData` inside it: its certificates, its one
   `SignerInfo` (`sid`, digest algorithm, signed attributes, signature
   algorithm, signature), and the `eContent` that is the `TSTInfo`;
3. read the `TSTInfo`: `messageImprint` (algorithm + digest),
   `genTime`, and the `signingTime` attribute c2pa-rs prefers when
   present;
4. verify the CMS signature over the signed attributes with the
   signer's key; check `messageDigest` against `eContent`;
5. compare the imprint with the digest of the countersigned bytes —
   the `CounterSignature` Sig_structure over the claim (v1) or the
   signature bstr (v2), proven on four tokens in step 40;
6. judge the TSA certificate: profile with the `timeStamping` EKU,
   validity at the token's time, chain to an anchor (M5's `ChainCheck`);
7. hand the time to SPEC-015, which judges the signer's validity at it
   instead of at now; report `signature_info.time` and the
   `timeStamp.*` codes — all *informational*, never `Invalid`.

What `ext-openssl` reaches, measured (step 40 §6):

- **Of items 2–3 only the certificates.** No `openssl_*` function
  returns the fields of a `TSTInfo` or the parts of a `SignerInfo`.
  `openssl_pkcs7_read` fails on the DER token but, given the same bytes
  base64-wrapped as `-----BEGIN PKCS7-----`, returns its three
  certificates as PEM (measured) — a shortcut for item 6, not for
  items 2–3. `openssl_cms_verify` can *verify*
  the CMS signature, but only from a file, and its chain check is
  unusable for a TSA (OpenSSL demands the S/MIME purpose; the TSA leaf
  has `timeStamping` only; PHP exposes no `-purpose`).
- **All of item 4 once the bytes are cut out**: the signed attributes
  with their `[0]` tag replaced by `SET` (RFC 5652 §5.4), `openssl_verify`
  with the leaf's public key → `1`; one bit flipped → `0`; on the
  DigiCert 2023 and 2025 tokens (RSA-4096) and Truepic's
  `sha384WithRSAEncryption` alike. No temp file.
- **All of item 6**: the certificates are DER `X509` once cut out, and
  `Certificate::fromDer`, `CertificateProfileCheck` and `ChainCheck`
  from M5 take them as they are; the cross-certificate at the end of
  DigiCert's chain matches an anchor by subject and key as SPEC-014
  already does.

So the one gap is a reader for a **small, closed DER grammar**:
`SEQUENCE`, `SET`, context tags `[n]` (constructed and, for the
`sid` alternative, primitive), `INTEGER`, `OCTET STRING`, `OBJECT
IDENTIFIER`, `NULL`, `BOOLEAN`, `UTCTime`, `GeneralizedTime` —
definite lengths only (DER forbids the other kind; 0 found in five
tokens). The four structures over it — `TimeStampResp`, `ContentInfo`
/ `SignedData`, `SignerInfo`, `TSTInfo` — are each a dozen fields.

The same reader would close the one gap SPEC-015 named: unknown
critical extensions, which `openssl_x509_parse` does not flag and
c2pa-rs's profile check rejects.

## Decision

1. **The DER reader is written here**, as `src/Asn1/` — a new Deptrac
   layer below `Timestamp` and `Trust`, depending on `Support` only.
   Bounded like every parser in this project (SPEC-004's habit): a
   maximum depth, a maximum element count, every length checked
   against the remaining bytes, every fault an exception naming the
   offset. It reads; it does not encode, and it does not model ASN.1 in
   general — a `Der` value with tag, class, constructed flag, and
   either bytes or children, and typed accessors (`integer()`, `oid()`,
   `time()`, `octets()`) that refuse the wrong tag. `GeneralizedTime`
   and `UTCTime` are validated as dates (`CA_ct.jpg`'s minute 63 is
   `timeStamp.malformed`, as c2pa-rs).
2. **The CMS signature is verified with `openssl_verify`** over the
   re-tagged signed attributes and the signer's public key from the
   token's own certificate — the digest algorithm from `SignerInfo`,
   accepting `rsaEncryption` + digest, `sha{256,384,512}WithRSAEncryption`,
   `ecdsa-with-SHA{256,384,512}` and RSA-PSS as SPEC-015 lists them.
   No `openssl_cms_verify`, no temp file — the rule of ADR-0003.
3. **The TSA certificate is judged with M5's code**: profile with the
   EKU list replaced by `timeStamping` (1.3.6.1.5.5.7.3.8), validity at
   the token's time, chain by `ChainCheck` against the operator's
   `trust_anchors` / `allowed_list` — the same file, as c2pa-rs
   ("timestamping trust anchors can be added separately",
   `certificate_trust_policy.rs`). **Trusted only through a configured
   anchor**; `timeStamp.untrusted` otherwise. This departs from
   c2patool 0.27.22, which reports `timeStamp.trusted` for three TSAs
   with no anchor configured and `untrusted` for a fourth — behaviour
   step 40 could not derive from the 0.90.22 source and therefore does
   not copy. The divergence touches 34 corpus files, all of the "same
   verdict, different informational code" kind, and is named in the
   drift alarms like the others.
4. **The timestamp's one effect on the verdict is the time.** All
   `timeStamp.*` codes are informational (c2pa-rs logs every one of
   them so; `E-sig-CA` is `Invalid` for its signature, `CACA.jpg` with
   an `untrusted` stamp is `Trusted`). A *validated* timestamp whose TSA
   chain is trusted supplies the epoch SPEC-015 judges the signer's
   validity at; any other outcome leaves it at now, and the report
   says which time it used. §14.6.1 asks for a *trusted* timestamp
   before its time may be used — the letter is followed, so a
   timestamp from an unconfigured TSA does not rescue an expired
   signer. (c2patool's `Valid` on the Truepic files rests on its
   `trusted` of §5 in step 40; ours will need Truepic's root as an
   anchor to reach it — see Consequences.)
5. **`signingTime` and `genTime`**: c2pa-rs takes the signed
   `signingTime` attribute over `genTime` when present. We read both,
   use `genTime` (RFC 3161 §2.4.2: the time of the stamp), and treat a
   `signingTime` that differs from it as `timeStamp.malformed` — the
   fail-closed reading, measured to change nothing on the five tokens
   (equal on all).

`composer.json` stays `php`, `ext-openssl`, `ext-mbstring`.

## Alternatives rejected

- **`phpseclib/phpseclib` for ASN.1 and CMS.** The mature tool, and the
  one ADR-0001 named as probable. Rejected for three measured reasons:
  the grammar needed is ten tags and four structures, not ASN.1; the
  library's `File\ASN1` maps to PHP arrays by schema and would still
  leave the CMS signature, the countersigned bytes and the TSA trust
  to us; and it is a large runtime dependency on shared hosting for a
  reader of a few hundred lines. It remains the fallback if the own
  reader fails a measurement it cannot pass — that is what ADR-0001's
  "replaced only when demonstrably equal" clause is for, in reverse.
- **`openssl_cms_verify` on a temp file for item 4.** Works
  (`NOVERIFY`, DER, measured), but a file per token in the verification
  path, and it still needs the DER reader for items 2–3, 5 and 6.
  Nothing gained.
- **Copying c2patool's `timeStamp.trusted` behaviour.** Not derivable
  from the source; copying an unexplained leniency is trust by
  observation, which is the kind of rule the brief forbids.
- **Making `timeStamp.*` a failure.** Stricter than the specification
  and than every oracle; it would turn every timestamped file with an
  unconfigured TSA `Invalid`. The specification puts the cost where it
  belongs — the time — and so do we.
- **Reading `sigTst` only, or `sigTst2` only.** The corpora carry both
  (35 JPEGs with `sigTst`, 2 with `sigTst2`, plus `exp-test1.png` with
  `sigTst` — measured over the two external corpora in step 41a); the countersigned bytes
  differ, and either alone leaves a class of files at now.

## Consequences

- Two specs: **SPEC-016**, the DER reader and the four structures
  (`TimeStampResp` → `TimeStampToken` → `SignedData` → `TSTInfo`, plus
  `SignerInfo`), measured field by field against `openssl asn1parse`
  and `openssl ts -reply -text` on the five tokens, with malformed-input
  criteria (truncated length, indefinite length, wrong tag, minute 63,
  a `SET` where a `SEQUENCE` is due); **SPEC-017**, the timestamp check
  — the countersigned bytes, the CMS signature, `messageDigest`, the
  imprint, the TSA profile and chain, the six `timeStamp.*` codes
  (`.validated`, `.mismatch`, `.malformed`, `.outsideValidity`,
  `.trusted`, `.untrusted`), `signature_info.time`, and the epoch
  handed to SPEC-015.
- **SPEC-015 amendment**: `check()` takes the timestamp's epoch when
  SPEC-017 supplies one; the `.expired` message names the time used and
  whether a timestamp was consulted (the clause is already written for
  it). AC7's exclusion of `signature_info.time` is lifted.
- **The drift alarms**: `SPEC013_PUBLIC_NO_TIMESTAMP` and
  `SPEC013_RS_NO_TIMESTAMP` are removed — that was M6's "done when".
  The Truepic files reach `Valid` only with Truepic's root as an
  anchor (it is inside the token; the alarm's settings file may carry
  it, since it is a public certificate); without it they stay
  `expired` at now, correctly by §14.6.1, and the alarm names them as
  the one place our reading is stricter than c2patool's `trusted`. A
  new named list, `_TIMESTAMP_UNTRUSTED` or similar, carries the 34
  files whose informational code differs.
- **`StatusCode`** gains the six `timeStamp.*` cases (SPEC-010
  amendment; the enum-growth tests move once more).
- **The report** gains `signature_info.time` for a timestamped
  manifest — c2patool's key and its ISO rendering — and the
  informational entries under `validation_results`, so the
  `checks_performed` list grows by `timestamp`.
- **SPEC-015's unknown-critical-extension gap** can close with the same
  reader (an amendment after M6, not part of it).
- **Deptrac**: `Asn1` (new, → `Support`), `Timestamp` (→ `Asn1`,
  `Cose`, `Trust`, `Manifest`, `Report`, `Support`); `Trust` may later
  take `Asn1` for the extension scan.
