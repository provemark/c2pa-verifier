    # SPEC-016: The DER reader, and the timestamp token as data — `TimeStampResp`, `SignedData`, `SignerInfo`, `TSTInfo`

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-22                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The COSE signature's unprotected header carries, in `sigTst` (claim v1)
or `sigTst2` (claim v2), an RFC 3161 timestamp: proof from a
time-stamping authority that the signature existed at a moment in time
(C2PA 2.4 §14.6). Without it a signer's certificate can only be judged
at *now*, and every certificate that has expired since signing is
`signingCredential.expired` — the three Truepic files of the official
corpus, signed with one-day certificates, are `expired` here and `Valid`
at c2patool for exactly this reason (step 37). M6 reads the timestamp;
this spec is its first half: **the bytes become data**. The second half,
SPEC-017, verifies that data (the CMS signature, the imprint, the TSA's
certificate) and turns it into a time and the `timeStamp.*` codes.

The bytes are DER (X.690 §8–10): a `TimeStampResp` (RFC 3161 §2.4.2)
in `sigTst`, a bare `TimeStampToken` in `sigTst2` — a CMS `ContentInfo`
holding a `SignedData` (RFC 5652 §5.1) whose `eContent` is the `TSTInfo`
(RFC 3161 §2.4.2) and whose one `SignerInfo` (RFC 5652 §5.3) carries the
signed attributes and the signature. Step 40 measured that
`ext-openssl` exposes none of these fields — `openssl ts` is a CLI, and
`openssl_cms_verify` verifies without telling — and ADR-0004 decided
that the reader is written here: ten tags, definite lengths only, four
structures, bounded like every parser in this project.

## Scope

**In scope**

- `Asn1\DerReader` — reads one DER element from a byte string at an
  offset: identifier (class, constructed bit, tag number ≤ 30), definite
  length (short form, or long form of 1–4 bytes, minimal as DER
  requires), and the content — either the raw bytes (primitive) or the
  children read recursively (constructed). Bounds: `maxDepth` (default
  32), `maxElements` (default 65 536) per call, `maxBytes` (default
  1 MiB) per input. Every fault an `Asn1Exception` naming the offset.
  `read(string $bytes): Der` requires that the element fills the input
  exactly; `readAt(string $bytes, int $offset): Der` is the seam for the
  structures.
- `Asn1\Der` — the element: `class` (`Asn1\TagClass`: universal,
  application, context, private), `constructed`, `tag`, `offset`,
  `length` (of the whole encoding), `contents` (primitive) or
  `children` (constructed), and `encoded()` — the element's own bytes,
  so that a structure can hand `signedAttrs` and `eContent` on
  unchanged. Typed accessors that refuse the wrong tag with an
  `Asn1Exception`: `sequence()`, `set()`, `integer()` (as a
  non-negative decimal string, as SPEC-015's serial; a negative value
  refused — none of the four structures has one), `integerBytes()`,
  `oid()` (dotted decimal), `octets()`, `boolean()`, `null()`,
  `time()` (UTCTime and GeneralizedTime, as a Unix epoch in UTC,
  validated as a real date), `tagged(int $n)` (a context-specific child),
  `child(int $i)`, `optional(int $i, ...)`.
- `Timestamp\TimeStampToken::fromHeaderValue(string $bytes): self` — the
  four structures over the reader, from either wrapper: a
  `TimeStampResp` (status `granted` (0) or `grantedWithMods` (1)
  required — any other `PKIStatus` is a refusal naming the status and
  the `statusString` when present; a response without a token likewise)
  or a bare `ContentInfo`. Both forms are accepted from both headers,
  as c2pa-rs does (the first child tells them apart without ambiguity:
  a `SEQUENCE` for `PKIStatusInfo`, an `OBJECT IDENTIFIER` for
  `ContentInfo`).
- `Timestamp\SignedData` — `version`, the `digestAlgorithms` OIDs,
  `eContentType` (must be `id-ct-TSTInfo` 1.2.840.113549.1.9.16.1.4),
  `eContent` (the `TSTInfo` DER, bytes), `certificates` (a list of DER
  X.509, in the token's order — the choice `certificate` only;
  `extraCert`, `v1AttrCert`, `v2AttrCert`, `other` refused), `crls`
  ignored, exactly one `SignerInfo` (RFC 3161 §2.4.2: "shall contain
  exactly one signer").
- `Timestamp\SignerInfo` — `version`, `sid` (`issuerAndSerialNumber`
  as issuer DER + serial decimal, or `subjectKeyIdentifier` as bytes),
  `digestAlgorithm` OID, `signedAttributes` (the raw `[0]` element and
  `signedAttributesForVerification()` — the same bytes with the first
  byte `0xA0` replaced by `0x31`, RFC 5652 §5.4), `messageDigest`
  (bytes, required — RFC 5652 §11.2), `signingTime` (epoch, optional,
  RFC 5652 §11.3), `contentType` attribute (must equal `eContentType`,
  RFC 5652 §11.1), `signatureAlgorithm` OID (and, for RSA-PSS, its
  parameters as bytes), `signature` (bytes). Unknown attributes are
  kept as OID → raw bytes and never refused.
- `Timestamp\TstInfo` — `version` (must be 1), `policy` OID,
  `messageImprint` (`hashAlgorithm` OID and `hashedMessage` bytes;
  the digest length must fit the algorithm), `serialNumber` (decimal
  string), `genTime` (epoch, from a `GeneralizedTime` with optional
  fractional seconds and a mandatory `Z`, RFC 3161 §2.4.2), `accuracy`
  (optional; seconds, millis, micros), `ordering`, `nonce` (optional,
  decimal), `tsa` (optional `GeneralName`, kept as raw bytes),
  `extensions` (optional, raw bytes; a critical extension is a
  refusal — fail closed, as X.509 would).
- `Asn1Exception`, `Timestamp\TimestampException` — the second wraps
  the first with what was being read ("TSTInfo genTime at offset
  87: minute 63 is not a time"); SPEC-017 maps both to
  `timeStamp.malformed`.
- Deptrac: `Asn1` (→ `Support`); `Timestamp` (→ `Asn1`, `Report`,
  `Support`; `Cose` and `Trust` join in SPEC-017).

**Out of scope**

- Verifying anything: the CMS signature, `messageDigest` against
  `eContent`, the imprint against the countersigned bytes, the TSA
  certificate — SPEC-017. This spec's tests may *use* `openssl_verify`
  as an oracle that the cut is right (AC5), nothing more.
- Encoding DER, high tag numbers (> 30), indefinite lengths, BER
  leniencies (non-minimal lengths, constructed strings), tags this
  project never meets (`BIT STRING` beyond raw bytes, `UTF8String`,
  `PrintableString` beyond raw bytes — a `Name` is compared as DER,
  never rendered here; SPEC-015's `Certificate` renders names through
  `openssl_x509_parse`).
- Reading X.509 with this reader (the unknown-critical-extension gap of
  SPEC-015) — an amendment after M6, ADR-0004.
- Timestamp *assertions* (`c2pa.time-stamp`, C2PA 2.4 §18.17) — later,
  with M7's ingredients; they carry the same token and will reuse this
  class.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-016')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The oracles: `openssl asn1parse -inform DER` (every offset, tag and
length), `openssl ts -reply -token_in -text` (every `TSTInfo` field) and
`openssl cms -cmsout -print` (the `SignerInfo`), all run in step 40 on
the tokens of five files; the values below are copied from that
output. The tokens are not new fixtures: the tests extract them from
the corpus files already in the repository through `CoseSign1`
(`unprotected['sigTst'|'sigTst2']['tstTokens'][0]['val']`), as step 40's
scratch script did. Byte vectors for AC1–AC2 are literals in the test.

- **AC1 — the ten tags decode, and the values are X.690's** *(the reader)*
  - Given hand-made vectors: `02 01 00` (0), `02 01 7f` (127),
    `02 02 00 80` (128), `02 10 06 37 d4 46 …` (the sixteen-byte serial
    of `C.jpg`'s token → `8265249780176541439333781366280615849`, the
    decimal of what `openssl` prints as hex `0637D446C68635796BE29941AE68F7A9`,
    converted by the SPEC-015 routine), `06 0a 60 86 48 01 86 fd 6c 07 01`
    (`2.16.840.1.114412.7.1`), `06 0b 2a 86 48 86 f7 0d 01 09 10 01 04`
    (`1.2.840.113549.1.9.16.1.4`), `05 00` (null), `01 01 ff` (true),
    `04 03 61 62 63` (`abc`), `17 0d 32 34 30 38 30 36 32 31 35 33 33 37 5a`
    (`240806215337Z` → 2024-08-06T21:53:37Z, epoch 1722981217),
    `18 0f 32 30 32 34 30 38 30 36 32 31 35 33 33 37 5a` (the same as
    GeneralizedTime), `18 13 …2e 35 30 30 5a` (with `.500` — the same
    epoch, fractions dropped), `30 06 02 01 01 02 01 02` (a SEQUENCE of
    two), `31 00` (an empty SET), `a0 03 02 01 05` (`[0]` constructed
    holding 5), `80 01 ff` (`[0]` primitive), a long-form length
    `04 82 01 00` + 256 bytes.
  - When `DerReader::read()` runs on each
  - Then each decodes to the value named, `Der::$offset`/`$length` equal
    `openssl asn1parse`'s `offset`/`hl+l` for the same bytes, `encoded()`
    returns the input, and the wrong accessor (`integer()` on the OID,
    `sequence()` on the SET) throws `Asn1Exception` naming the offset and
    both tags. UTCTime years: `50`–`99` → 1950–1999, `00`–`49` →
    2000–2049 (RFC 5280 §4.1.2.5.1).

- **AC2 — malformed DER is refused with the offset, never read past** *(the error path)*
  - Given: a length past the end (`04 05 61 62`); an indefinite length
    (`30 80 …`); a non-minimal length (`04 81 03 61 62 63` — BER, not DER);
    a long form of five bytes (`04 85 …`); the reserved `04 ff`; a high
    tag number (`1f 81 00 …`); a truncated identifier (a lone `30`);
    nesting 33 deep (`30 xx` × 33, `maxDepth` 32); 65 537 `05 00`
    elements in one SEQUENCE (`maxElements`); an input of `maxBytes` + 1;
    `read()` on an element followed by one trailing byte; a `UTCTime`
    without `Z` (`17 0c 32 34 30 38 30 36 32 31 35 33 33 37`); a
    `GeneralizedTime` with minute 63 (`20240806216337Z` — the bytes of
    `CA_ct.jpg`'s token); 31 February; a `BOOLEAN` of two bytes; an OID
    with an unterminated subidentifier (`06 02 2a 86`); an OID of zero
    length; an `INTEGER` with a non-minimal leading `00 00`; a negative
    `INTEGER` through `integer()` (`02 01 ff`).
  - When the reader (or the accessor) runs
  - Then every case throws `Asn1Exception` with the offset in the message
    and a description a reader can act on ("length 5 at offset 1 runs past
    the end (4 bytes)"), no case returns a `Der`, and reading the
    truncated cases performs no `substr` past the input (the test
    asserts through a counting stream-free wrapper: the exception's
    offset is ≤ `strlen`).

- **AC3 — the five tokens' `TSTInfo`, field by field as `openssl ts` prints it** *(the structure)*
  - Given the `sigTst`/`sigTst2` value of `c2pa-rs/C.jpg`,
    `public-testfiles/adobe-20220124-C.jpg`,
    `public-testfiles/truepic-20230212-camera.jpg`,
    `c2pa-rs/C_with_CAWG_data.jpg`, `c2pa-rs/CACA.jpg` (the active
    manifest's)
  - When `TimeStampToken::fromHeaderValue()` runs
  - Then `tstInfo` holds, per file: `version` 1; `policy`
    `2.16.840.1.114412.7.1` (DigiCert ×4) / `1.3.6.1.4.1.22408.1.2.3.45`
    (Truepic); `messageImprint.hashAlgorithm` `2.16.840.1.101.3.4.2.1`
    (sha256) ×4 / `2.16.840.1.101.3.4.2.2` (sha384), `hashedMessage`
    32 / 48 bytes starting `64d055da`, `0432594d`, `d8160464`, `37ab305c`,
    `4b2fa959`; `serialNumber` the decimal of `0637D446C68635796BE29941AE68F7A9`,
    `86B091F8B163977B2B98CDC2A02D7B0F`, `3972A07AC1F439E50BB0DB3EB17B20D9`,
    `8EFC58636572B0EB9637E2DC718C93E6`, `B7EAF41E6D4CF3ACDF7116816B9C3272`;
    `genTime` 2024-08-06T21:53:37Z, 2023-01-24T14:48:56Z,
    2023-02-12T18:44:26Z, 2025-07-29T23:13:49Z, 2025-10-16T17:31:55Z;
    `nonce` the decimal of `59A4EE7623CEFC36`, `90C0FF57DE1168F8`, null,
    `16DA33EF5E896BE0`, `3A1E7AA9E21E8526`; `accuracy` null ×4 /
    millis 500 (Truepic, `0x01F4`); `ordering` false; `tsa` null ×4 /
    non-null bytes (Truepic's DirName); `extensions` null.

- **AC4 — the `SignedData` and the one `SignerInfo`** *(the structure)*
  - Given the same five tokens
  - When `fromHeaderValue()` runs
  - Then `signedData.version` is 3; `eContentType` is
    `1.2.840.113549.1.9.16.1.4`; `eContent` is 112 / 114 / 227 / 113 /
    113 bytes and starts `30 6e` / `30 70` / `30 81 e0` / `30 6f` / `30 6f`;
    `certificates` holds 3 DER certificates each, and
    `Trust\Certificate::fromDer()` accepts every one (the first's subject
    CN: `DigiCert Timestamp 2023`, `DigiCert Timestamp 2022`,
    `Truepic Lens Time-Stamping Authority`, `DigiCert SHA256 RSA4096
    Timestamp Responder 2025 1` ×2); `signerInfo.version` is 1; `sid` is
    `issuerAndSerialNumber` on all five, with the issuer DER equal to the
    first certificate's issuer DER and the serial equal to its serial;
    `digestAlgorithm` sha256 ×4 / sha384; `signatureAlgorithm`
    `1.2.840.113549.1.1.1` (rsaEncryption) ×4 / `1.2.840.113549.1.1.12`
    (sha384WithRSAEncryption); `signature` 512 bytes on all five (RSA-4096 keys, DigiCert and
    Truepic alike);
    `messageDigest` equals `hash(<digestAlgorithm>, eContent, true)`;
    `signingTime` equals `genTime`; the `contentType` attribute equals
    `eContentType`; the unknown attributes (`signingCertificate`,
    `signingCertificateV2` on DigiCert; `CMSAlgorithmProtection` on
    Truepic) are present by OID and untouched.

- **AC5 — the cut is right: the re-tagged signed attributes verify** *(the oracle for the bytes)*
  - Given the five tokens and, per token, the public key of the first
    certificate (`Certificate::fromDer()->publicKey`)
  - When `openssl_verify($signerInfo->signedAttributesForVerification(),
    $signerInfo->signature, $key, <digest>)` runs (sha256 / sha384 by
    `digestAlgorithm`)
  - Then it returns `1` for every token; with one bit of
    `signedAttributesForVerification()` flipped it returns `0`; and
    `signedAttributesForVerification()` differs from `signedAttributes`
    in exactly its first byte (`0xA0` → `0x31`) and nowhere else.

- **AC6 — both wrappers, either header** *(the two shapes)*
  - Given the `sigTst` value of `C.jpg` (a `TimeStampResp`, 5951 bytes,
    starting `30 82 17 3b 30 03 02 01 00`) and the `sigTst2` value of
    `C_with_CAWG_data.jpg` (a `ContentInfo`, 5998 bytes, starting
    `30 82 17 6a 06 09 2a 86 48 86 f7 0d 01 07 02`)
  - When `fromHeaderValue()` runs on each, and on the *token* cut out of
    the first (bytes 9…end, the 5942-byte `ContentInfo` `openssl ts
    -token_out` writes) and on the second wrapped in a hand-made
    `TimeStampResp` with status 0
  - Then all four parse to a `TimeStampToken` with the same `TSTInfo`
    as their source; a `TimeStampResp` with status 2 (`rejection`) and
    a `statusString` `"bad request"` is refused with a message naming
    both; status 1 (`grantedWithMods`) is accepted; a response with
    status 0 and no token is refused; and a `ContentInfo` whose OID is
    not `signedData` (`1.2.840.113549.1.7.1`, `data`) is refused naming
    the OID.

- **AC7 — the token's own rules are enforced, one refusal each** *(RFC 3161 / RFC 5652 as fail-closed rules)*
  - Given `C.jpg`'s token patched, one change per case, with the
    enclosing lengths kept consistent (the test's helper rewrites a
    fixed-size field or replaces bytes of equal length): `eContentType`
    changed to `id-data`; a second `SignerInfo` appended (the SET
    lengths grown); zero `SignerInfo`s; `TSTInfo.version` 2;
    `messageImprint.hashedMessage` shortened to 31 bytes; a
    `SignerInfo` without `signedAttrs`; a `signedAttrs` without
    `messageDigest`; a `contentType` attribute that is not the
    `eContentType`; a `certificates` field holding an `[1]` `extraCert`
    (v1AttrCert) choice; no `certificates` at all; a `TSTInfo` with a
    critical extension appended
  - When `fromHeaderValue()` runs
  - Then each case throws `TimestampException` whose message names the
    rule ("SignedData must hold exactly one SignerInfo, found 2",
    "messageImprint: 31 bytes is not a sha256 digest"), and — the
    falsification — the unpatched token still parses after every helper
    is proven to change exactly the bytes it claims (`openssl asn1parse`
    on the patched bytes in the tests-first step, recorded in the note).

- **AC8 — bounded: a token is at most `maxBytes`, and the header at most `maxTokens`** *(limits)*
  - Given a `sigTst` value of 1 MiB + 1 byte; a `tstTokens` list of nine
    entries (`maxTokens` 8); a `tstTokens` entry without `val`; a `val`
    that is CBOR text rather than bytes
  - When `Timestamp\TimestampHeader::fromUnprotected(array $header)` (the
    small reader of the CBOR shape `{tstTokens: [{val: bstr}, …]}`,
    C2PA 2.4 §14.6) runs
  - Then each is refused with `TimestampException` naming the limit or
    the field; a header with neither `sigTst` nor `sigTst2` yields
    `null` (no timestamp — our three fixtures and Nikon); a header with
    both is refused (fail closed; c2pa-rs prefers `sigTst2`; none of
    the corpus files carries both — measured in the tests-first step);
    and `sigTst2` on a v1 claim or `sigTst` on a v2 claim is *not*
    refused here (which header a claim version may carry is SPEC-017's
    rule, with the countersigned bytes).

- **AC9 — `CA_ct.jpg` is the corpus's malformed token, and the message says why** *(the corpus's error path)*
  - Given the `sigTst` value of `c2pa-rs/CA_ct.jpg`
  - When `fromHeaderValue()` runs
  - Then it throws `TimestampException` whose message contains
    `genTime`, the offset of the time inside the `TSTInfo` (87, the
    `18 0f` at that offset), and `63`; c2patool's JSON for the file
    (`tests/Fixtures/c2patool/c2pa-rs/CA_ct.json`) carries
    `timeStamp.malformed` with "timestamp response had no TstInfo" —
    the same verdict, our message the more precise one.

- **AC10 — every corpus token parses, and none takes the reader past its bounds** *(the drift alarm's ground)*
  - Given every file of the three corpora whose active manifest carries
    `sigTst` or `sigTst2` (35 + 2 JPEGs measured in step 40's ADR entry;
    the exact list is produced by the test)
  - When `TimestampHeader::fromUnprotected()` and `fromHeaderValue()` run
  - Then every value parses except `CA_ct.jpg` (AC9), each with exactly
    one token, one `SignerInfo`, `TSTInfo.version` 1 and three
    certificates, and the deepest nesting met is below 16 and the
    element count below 2 048 (measured in the tests-first step and
    written into the test as the ceiling the defaults leave room for).

- **AC11 — an element that is not there is a refusal, never a PHP error** *(amendment 4; required: malformed input)*
  - Given the timestamp tokens of `c2pa-rs/C.jpg` (RSA),
    `public-testfiles/truepic-20230212-camera.jpg` (RSA with SHA-384) and
    `c2pa-rs/ocsp.jpg` (ECDSA), each mutated once for every constructed
    element: that element emptied, and separately without its last
    child, with every enclosing length re-encoded by the test's own
    `DerPatch`, which does not use the reader under test
  - When each is parsed and judged, with every PHP warning turned into an
    exception
  - Then each either passes or is a `TimestampException`, which the check
    reports as `timeStamp.malformed`. No warning, no `Error`, no other
    exception escapes.

- **AC12 — an INTEGER read as a number has at most 256 octets** *(amendment 5; limits)*
  - Given INTEGERs of 256 and 257 octets, and the security review's
    token whose SignedData version is an INTEGER of 8,000 octets
  - When each is read as a decimal number
  - Then the first is converted exactly (617 digits), and the other two
    are refused with an exception naming the bound, the token as a
    `TimestampException` in well under a second. Known values
    (2^64, 2^256 − 1) are converted exactly.

## References

- Specification: X.690 (2021) §8.1 (identifier and length octets),
  §8.3 (INTEGER), §8.19 (OID), §10 (DER: definite lengths, minimal
  encoding); RFC 3161 §2.4.2 (`TimeStampResp`, `PKIStatus`,
  `TimeStampToken`, `TSTInfo`: "shall contain exactly one signer",
  `genTime` as `GeneralizedTime` with optional fractions and `Z`);
  RFC 5652 §5.1 (`SignedData`), §5.3 (`SignerInfo`), §5.4 (the `SET OF`
  re-tagging of `signedAttrs` for the signature), §11.1–11.3
  (`contentType`, `messageDigest`, `signingTime`); RFC 5280 §4.1.2.5
  (UTCTime and GeneralizedTime rules); C2PA 2.4 §14.6 (`sigTst` /
  `sigTst2`, `tstTokens`). Read 2026-09-22 (step 40).
- Oracle: `openssl asn1parse`, `openssl ts -reply -text`, `openssl cms
  -cmsout -print`, OpenSSL 3.6.3, on the five tokens (step 40 and its
  AI-log entry); c2patool 0.27.22's `CA_ct.json`; `openssl_verify` for
  AC5 (measured `1` / `0` in step 40); `openssl_pkcs7_read` on the
  PEM-wrapped token as a second oracle for AC4's certificate list
  (three, measured).
- Reasoned: both wrappers from both headers (c2pa-rs
  `time_stamp/verify.rs` tries the response, then the token); a
  critical `TSTInfo` extension as a refusal (RFC 3161 §2.4.2 says a
  validator "shall" reject an unrecognised critical extension — read
  in the tests-first step and cited by line then); `maxBytes` 1 MiB
  (the largest corpus token is 5998 bytes; 170× headroom, and the
  whole header already sits under SPEC-008's box limit).
- Divergence: none by design. Where c2pa-rs's `rasn` refuses a token,
  so do we (AC9); where it reads one, so do we (AC10). The one place
  we may be stricter is AC7's rules (rasn enforces the schema, not the
  RFC's "exactly one signer" — to be checked in the tests-first step
  with a two-signer token through c2patool, and stated here if the
  oracle accepts it).

## API sketch

```php
// namespace Provemark\C2paVerifier\Asn1;

enum TagClass: int { case Universal = 0; case Application = 1; case ContextSpecific = 2; case Private = 3; }

final readonly class Der
{
    /** @param list<Der>|null $children */
    public function __construct(
        public TagClass $class,
        public bool $constructed,
        public int $tag,
        public int $offset,         // of the identifier octet in the input
        public int $headerLength,   // identifier + length octets
        public string $contents,    // primitive: the content octets; constructed: the same bytes, unparsed
        public ?array $children,    // constructed only
    ) {}

    public function encoded(): string;                  // header + contents, as in the input
    public function is(TagClass $class, int $tag): bool;
    /** @return list<Der> */ public function sequence(): array;
    /** @return list<Der> */ public function set(): array;
    public function integer(): string;                  // non-negative decimal
    public function integerBytes(): string;             // the content octets, minimal
    public function oid(): string;                      // dotted decimal
    public function octets(): string;
    public function boolean(): bool;
    public function null(): void;
    public function time(): int;                        // UTCTime (23) or GeneralizedTime (24), UTC epoch
    public function tagged(int $n): Der;                // this element, asserted [n] context-specific
    public function child(int $i): Der;                 // of a constructed element, else Asn1Exception
}

final readonly class DerReader
{
    public const DEFAULT_MAX_DEPTH = 32;
    public const DEFAULT_MAX_ELEMENTS = 65536;
    public const DEFAULT_MAX_BYTES = 1048576;

    public function __construct(
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public int $maxElements = self::DEFAULT_MAX_ELEMENTS,
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {}

    /** The one element that fills $bytes exactly. */
    public function read(string $bytes): Der;
    /** One element at $offset; its $length says where the next begins. */
    public function readAt(string $bytes, int $offset, int $depth = 0): Der;
}

final class Asn1Exception extends \RuntimeException {}

// namespace Provemark\C2paVerifier\Timestamp;

final readonly class TimestampHeader
{
    public const DEFAULT_MAX_TOKENS = 8;

    /** @param list<string> $tokens  the raw values, in header order  */
    public function __construct(public string $header /* 'sigTst' | 'sigTst2' */, public array $tokens) {}

    /** @param array<int|string, mixed> $unprotected  null when neither header is present */
    public static function fromUnprotected(array $unprotected, int $maxTokens = self::DEFAULT_MAX_TOKENS): ?self;
}

final readonly class TimeStampToken
{
    public function __construct(
        public SignedData $signedData,
        public TstInfo $tstInfo,
        public ?int $responseStatus,     // the PKIStatus when the value was a TimeStampResp, else null
    ) {}

    public static function fromHeaderValue(string $bytes, ?DerReader $reader = null): self;
}

final readonly class SignedData
{
    /** @param list<string> $digestAlgorithms OIDs  @param list<string> $certificates DER */
    public function __construct(
        public int $version,
        public array $digestAlgorithms,
        public string $eContentType,
        public string $eContent,
        public array $certificates,
        public SignerInfo $signerInfo,
    ) {}
}

final readonly class SignerInfo
{
    /** @param array<string, string> $otherAttributes OID => the Attribute's DER */
    public function __construct(
        public int $version,
        public ?string $sidIssuer,            // issuerAndSerialNumber: the Name's DER
        public ?string $sidSerial,            // decimal
        public ?string $sidSubjectKeyId,      // the other choice
        public string $digestAlgorithm,
        public string $signedAttributes,      // the [0] element, as encoded
        public string $messageDigest,
        public ?int $signingTime,
        public string $contentTypeAttribute,
        public array $otherAttributes,
        public string $signatureAlgorithm,
        public ?string $signatureParameters,  // RSA-PSS: the parameters' DER
        public string $signature,
    ) {}

    public function signedAttributesForVerification(): string;   // 0xA0 → 0x31
}

final readonly class TstInfo
{
    public function __construct(
        public int $version,
        public string $policy,
        public string $hashAlgorithm,         // OID
        public string $hashedMessage,
        public string $serialNumber,          // decimal
        public int $genTime,                  // UTC epoch, fractions dropped
        public ?TstAccuracy $accuracy,
        public bool $ordering,
        public ?string $nonce,                // decimal
        public ?string $tsa,                  // GeneralName DER
        public ?string $extensions,           // DER; a critical one is a refusal before construction
    ) {}
}

final readonly class TstAccuracy { public function __construct(public ?int $seconds, public ?int $millis, public ?int $micros) {} }

final class TimestampException extends \RuntimeException {}
```

## Open questions

- Non-blocker (tests-first step): the nesting depth and element count ceiling of AC10, and
  whether c2patool accepts a two-signer token (AC7's divergence note).
- Non-blocker: `Der::time()` drops fractional seconds. RFC 3161 allows
  them in `genTime`; the report renders whole seconds as c2patool
  does (`2024-08-06T21:53:37+00:00`). If a corpus token carries
  fractions and c2patool renders them, an amendment keeps them.
- Non-blocker: whether `integer()` should return `int` when it fits
  and `string` otherwise. `string` always, as SPEC-015's serial: one
  type, no overflow surprise on 32-bit hosts.
- Non-blocker: `TimestampHeader` refuses a header with both `sigTst`
  and `sigTst2`. c2pa-rs reads `sigTst2` first and falls back. If a
  real writer emits both, the amendment follows c2pa-rs; until then
  the stricter rule holds and is measured against the corpora (AC8).

## Amendments

1. **2026-09-22, step 41a, measured before the tests** — three literals corrected by the measurement: AC10 counts **38** timestamped files (35 `sigTst` and 2 `sigTst2` JPEGs plus `c2pa-rs/exp-test1.png`, the one PNG with a header), 37 parse; the bounds pinned in AC10 are `maxDepth` 20 and `maxElements` 512 (`openssl asn1parse` shows d=18 — nineteen levels — and 311 elements at most, on the Truepic tokens), not "below 16 / 2 048"; AC4's Truepic token carries `signingCertificateV2` next to `CMSAlgorithmProtection` (the test lists `[1.2.840.113549.1.9.52, …16.2.47]`). No file carries both headers (AC8's rule is measured, not assumed).
2. **2026-09-22, step 41b, found by the first green run** — the signer is not "the first certificate": DigiCert's tokens put the signer first, Truepic's put its root first and the signer last (AC4 and AC5 failed on Truepic with `RootCA` and `openssl_verify` 0). `SignedData::signerCertificate()` now returns the certificate the `sid` names — by issuer Name DER and serial, or by subjectKeyIdentifier (read from the certificate's own extensions; no corpus token uses that choice, so it is reasoned, not measured) — and AC4, AC5 and AC10 use it. AC10's "three certificates" is "two or three, the signer among them": the two `ocsp*.jpg` tokens carry two (an ECDSA TSA, "Adobe SHA256 ECC256 Timestamp Responder 2025 1", under the 2025 DigiCert CA — the first ECDSA timestamp signature for SPEC-017). Five literals in the tests were mine, not the oracle's, and were corrected against the measurement: the OID vector's length byte (`06 09`, not `06 0a`); the `TimeStampResp` head (`…3003020100`, 9 bytes = 18 hex digits); the leaf CN `DigiCert Timestamp 2022 - 2`; `genTime` at offset **86** of `CA_ct.jpg`'s TSTInfo; and the AC7 extension patch, which first used `[3]` with an EXPLICIT wrapper where RFC 3161 says `extensions [1] IMPLICIT Extensions` (the reader refuses both — the `[3]` as an unexpected field, the `[1]` for its critical extension — but the test must break what it claims to break).
3. **2026-09-22, step 44, found by the writers corpus (step 43)** *(confirmed by Maurice van Loon, 2026-09-22)* — two of the reader's rules were true of the five tokens measured and wrong in general. (a) A negative INTEGER is legal DER and RFC 3161's `nonce` is a random value that TSA clients encode as they draw it: Amazon Bedrock's and `c2pa-ts`'s tokens carry `0x-335F9549` and `0x-612D17525B24B1B64D0E` (as `openssl ts -reply -text` prints them) and were `malformed` here. `Der::integer(bool $signed = false)`: with `$signed` a negative value is read as two's complement and returned as a decimal with a minus sign; `TstInfo` reads the nonce signed; serials, versions, accuracies stay non-negative (RFC 5280 §4.1.2.2 for serials). (b) A `GeneralizedTime` may carry fractional seconds (RFC 3161 §2.4.2) and c2patool keeps them in `signature_info.time` (`…55.837381+00:00`, `…40.669+00:00` — the token's own digits); `time()` still returns whole seconds, and `Der::timeFraction()` returns the digits after the point (or null); `TstInfo::$genTimeFraction` carries them. AC11 added: the three writer tokens parse with the signed nonces and the fractions named.

4. **2026-09-25, step 153, found by the security review; measured** *(confirmed by Maurice van Loon, 2026-09-25)* —
   a child the parser reads by position must exist. `Der::element(int $i)`
   returns the i-th element of a SEQUENCE, or throws `Asn1Exception` when
   the element is not a SEQUENCE or has no such child. Six places read
   `->sequence()[0]` without that check:

   - `SignedData` (a digest algorithm, the encapContentInfo, a
     certificate extension);
   - `SignerInfo` (the digest and signature algorithms);
   - `TstInfo` (the imprint's hash algorithm).

   An empty SEQUENCE there was a PHP warning followed by `Call to a member
   function oid() on null`, a fatal `Error` that no check catches. The
   verifier then ended with exit status 255 and no report, on input
   anyone can write, with no key needed.

   Measured with AC11's mutations before the change: 48 escapes at those
   six places over the three tokens; none on a removed last child, which
   the existing `count()` checks already cover. With the change the
   `Asn1Exception` takes the path every other malformed token takes:
   `TimestampException`, then `timeStamp.malformed`. **Weight B**: a crash
   becomes a status; no verdict that was reached before changes. New
   criterion AC11.

5. **2026-09-25, step 154, found by the security review; measured** *(confirmed by Maurice van Loon, 2026-09-25)* —
   `Bytes::hexToDecimal` converted one hex digit at a time over every
   decimal digit so far: quadratic in the length. An INTEGER of 2,000
   octets took 0.54 s, 4,000 took 2.1 s and 8,000 took 8.6 s. The
   review's 52 KB file kept `bin/c2pa-verify` busy for 35.8 s, where
   `c2patool` answers `timeStamp.malformed` in 0.01 s. A token may be
   1 MiB and a header may hold eight, so the cost had no practical end.

   Two changes:

   - **The conversion** now works in chunks of seven hex digits over
     limbs of 10^9. It gives the same result on 3,008 comparisons with
     the old one, including leading zeros and powers of two, and is about
     60 times faster: 256 octets in about 0.1 ms.
   - **A bound:** an INTEGER read as a number has at most 256 octets of
     magnitude (`Bytes::MAX_DECIMAL_OCTETS`); a longer one is an
     `Asn1Exception` before any conversion.

   Measured: every INTEGER converted over all signed fixtures (150,540
   conversions) and the 78 files of current writers (1,414) is at most
   20 octets, RFC 5280's limit for a serial number. A timestamp token or
   OCSP response with a longer INTEGER becomes `timeStamp.malformed` or
   `signingCredential.ocsp.skipped`, which is what `c2patool` answers on
   the review's token. **Weight B**: a denial of service becomes a
   status. New criterion AC12.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Asn1/DerReaderTest.php :: SPEC-016 AC1: the ten tags decode, and the values are X.690's — * (8 tests) / SPEC-016 | src/Asn1/DerReader.php :: read(), readAt(), element(); src/Asn1/Der.php :: integer(), integerBytes(), oid(), octets(), boolean(), null(), time(), sequence(), set(), tagged(), child(), encoded(), length(); src/Asn1/TagClass.php; src/Support/Bytes.php :: hexToDecimal() (moved from Trust\Certificate) |
| AC2 | tests/Unit/Asn1/DerReaderTest.php :: SPEC-016 AC2: malformed DER is refused with the offset, never read past — * (16 vectors + depth, elements, maxBytes) / SPEC-016 | src/Asn1/DerReader.php :: element() (lengths, tags, limits); src/Asn1/Der.php :: the accessors' refusals; src/Asn1/Asn1Exception.php |
| AC3 | tests/Unit/Timestamp/TimeStampTokenTest.php :: SPEC-016 AC3: the five tokens' TSTInfo, field by field as openssl ts prints it — * (5) / SPEC-016; the corpus readers in tests/Support/Corpus.php (moved there in step 42a) | src/Timestamp/TstInfo.php :: fromDer(), read(), accuracy(); src/Timestamp/TstAccuracy.php; src/Timestamp/TimeStampToken.php :: fromHeaderValue() ($responseStatus) |
| AC4 | tests/Unit/Timestamp/TimeStampTokenTest.php :: SPEC-016 AC4: the SignedData and the one SignerInfo — * (5) / SPEC-016 | src/Timestamp/SignedData.php :: fromDer(), signerCertificate(), identity(); src/Timestamp/SignerInfo.php :: fromDer(), single() |
| AC5 | tests/Unit/Timestamp/TimeStampTokenTest.php :: SPEC-016 AC5: the cut is right — the re-tagged signed attributes verify — * (5) / SPEC-016 | src/Timestamp/SignerInfo.php :: signedAttributesForVerification(), $signature; src/Timestamp/SignedData.php :: signerCertificate() |
| AC6 | tests/Unit/Timestamp/TimeStampTokenTest.php :: SPEC-016 AC6: both wrappers, either header — * (3) / SPEC-016 | src/Timestamp/TimeStampToken.php :: fromHeaderValue(), status(), STATUS_NAMES |
| AC7 | tests/Unit/Timestamp/TimeStampTokenTest.php :: SPEC-016 AC7: the token's own rules are enforced, one refusal each — * (12) / SPEC-016; the patch helpers in tests/Support/DerPatch.php (moved there in step 42a) | src/Timestamp/SignedData.php :: fromDer() (eContentType, certificates choice, exactly one SignerInfo); src/Timestamp/SignerInfo.php :: fromDer() (signedAttrs, messageDigest, contentType); src/Timestamp/TstInfo.php :: read(), extensions() (version, imprint length, critical) |
| AC8 | tests/Unit/Timestamp/TimeStampTokenTest.php :: SPEC-016 AC8: bounded — a token is at most maxBytes, a header at most maxTokens — * (5) / SPEC-016 | src/Timestamp/TimestampHeader.php :: fromUnprotected(); src/Asn1/DerReader.php :: readAt() (maxBytes); src/Timestamp/TimestampException.php |
| AC9 | tests/Unit/Timestamp/TimeStampTokenTest.php :: SPEC-016 AC9: CA_ct.jpg is the corpus's malformed token, and the message says why — genTime 20240806216337Z: minute 63 at offset 86 of the TSTInfo / SPEC-016 | src/Asn1/Der.php :: time(); src/Timestamp/TstInfo.php :: read() (the genTime wrap) |
| AC10 | tests/Unit/Timestamp/TimeStampTokenTest.php :: SPEC-016 AC10: every corpus token parses, and none takes the reader past its bounds — 38 timestamped files, 37 parse; … / SPEC-016 | src/Timestamp/TimestampHeader.php, src/Timestamp/TimeStampToken.php, src/Asn1/DerReader.php (the bounds) |
| AC11 | tests/Unit/Asn1/MissingElementTest.php :: AC11 / SPEC-016 | src/Asn1/Der.php (`element()`); src/Timestamp/SignedData.php, SignerInfo.php, TstInfo.php (the six reads by position); tests/Support/DerPatch.php (`constructed()`) |
| AC11 (amendment 3) | tests/Unit/Timestamp/TimeStampTokenTest.php :: SPEC-016 AC11: a negative INTEGER reads signed on request …; … GeneralizedTime fractions are kept …; … the writer token parses — * (3) / SPEC-016 | src/Asn1/Der.php :: integer(bool $signed), timeFraction(); src/Timestamp/TstInfo.php :: read() (nonce signed, $genTimeFraction) |
| AC12 | tests/Unit/Asn1/IntegerBoundTest.php :: AC12 / SPEC-016 | src/Support/Bytes.php (`hexToDecimal()`, `MAX_DECIMAL_OCTETS`, `decimalOctets()`); src/Asn1/Der.php (`integer()`) |
