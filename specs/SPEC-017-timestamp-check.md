# SPEC-017: The timestamp check — the CMS signature, the imprint, the TSA's trust, the six `timeStamp.*` codes, and the time SPEC-015 judges at

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

SPEC-016 turned the `sigTst` / `sigTst2` header into data: a
`TimeStampToken` with its `SignedData`, one `SignerInfo` and the `TSTInfo`.
Nothing has been *verified* about it, so the timestamp still has no
effect: every signer's certificate is judged at now (SPEC-015), and the
three Truepic files of the official corpus stay `signingCredential.expired`
where c2patool says `Valid` (step 37). This spec closes M6: it decides
whether the token is a valid RFC 3161 timestamp over *this* signature,
whether its TSA is trusted, and — only then — hands the token's time to
SPEC-015 as the moment the signer's certificate validity is judged at
(C2PA 2.4 §14.6.1).

What the check must establish, in c2pa-rs's order (`time_stamp/verify.rs`
0.90.22, read in step 40 §4) and with §15's codes:

1. the token parses (SPEC-016) → else `timeStamp.malformed`;
2. the signer certificate named by `sid` is in the token → else
   `timeStamp.malformed`;
3. the signed `messageDigest` equals the digest of `eContent` → else
   `timeStamp.mismatch`;
4. the CMS signature over the signed attributes verifies with the signer's
   key → else `timeStamp.untrusted` (c2pa-rs's word for it; §15 has no
   `timeStamp.invalid`);
5. the signer certificate is valid at the token's time →
   else `timeStamp.outsideValidity`;
6. the imprint equals the digest of the countersigned bytes → the
   `CounterSignature` Sig_structure over the claim bytes (`sigTst`) or the
   signature as a CBOR byte string (`sigTst2`), proven on four tokens in
   step 40 §2 → `timeStamp.validated` / `timeStamp.mismatch`;
7. the TSA certificate passes the profile with the EKU list replaced by
   `timeStamping` alone, and its chain reaches an anchor the operator
   configured → `timeStamp.trusted` / `timeStamp.untrusted`.

Two decisions of ADR-0004 shape the rest. **Every `timeStamp.*` code is
informational**: c2pa-rs logs all of them so, and a broken timestamp never
makes a file `Invalid` — the file loses the *time*, nothing else. **The
TSA is trusted only through a configured anchor**: c2patool 0.27.22
reports `timeStamp.trusted` for three TSAs with no anchor configured,
which step 40 §5 could not derive from the source and this project does
not copy; the resulting divergence is informational on 34 corpus files
and is named in the drift alarms.

## Scope

**In scope**

- `Timestamp\TimestampCheck::check(Manifest $manifest, ?TrustSettings $settings): TimestampResult`
  — the whole of the list above on the active manifest's COSE_Sign1.
  No header → an empty result (`present` false, no statuses, no time).
  Only the first token of the header is judged, as c2pa-rs does ("we only
  pay attention to the first time stamp header"); further tokens are
  counted in the `validated` explanation, never judged. Every
  `TimestampException` and `Asn1Exception` becomes one
  `timeStamp.malformed` status carrying the message; the check never
  throws. Every status carries the signature box's url
  (`self#jumbf=/c2pa/<label>/c2pa.signature`), as c2patool's do.
- `Timestamp\TimestampCheck::judge(TimeStampToken $token, string $tbs, ?TrustSettings $settings, string $url): TimestampResult`
  — steps 2–7 on an already-parsed token: the seam the tests use to reach
  `outsideValidity` (a real token cannot reach it without breaking step 3
  first — the test rebuilds the `TimeStampToken` value with a `TstInfo`
  whose `genTime` lies outside the TSA certificate's validity).
- `Timestamp\TimestampCheck::countersignedBytes(CoseSign1 $cose, string $header, string $claimBytes): string`
  — the `Sig_structure` `["CounterSignature", protected, h'', payload]`
  (RFC 9052 §4.4; c2pa-rs `cose_countersign_data`), payload = the claim
  bytes for `sigTst`, the signature wrapped as a CBOR bstr for `sigTst2`.
  Which header a claim version carries is *not* checked (c2pa-rs picks by
  name, whichever is present; the corpus pairs v1 with `sigTst` and v2
  with `sigTst2` without exception — measured in step 41b).
- The CMS signature (step 4) with `openssl_verify` over
  `signedAttributesForVerification()` and the signer's public key
  (`Cose\PublicKey::fromCertificateDer`), the algorithm from the
  `SignerInfo`: `rsaEncryption` (PKCS#1 v1.5, the digest from
  `digestAlgorithm`), `sha{256,384,512}WithRSAEncryption`,
  `ecdsa-with-SHA{256,384,512}` (a DER-encoded ECDSA signature, which
  `openssl_verify` takes as it is), and `RSASSA-PSS` through `Cose\RsaPss`
  when its parameters say MGF1 with the same hash and a salt of the hash's
  length. The digest algorithm must be one `TstInfo::DIGEST_LENGTHS` names;
  the key must fit the algorithm (RSA for the RSA OIDs, EC for ECDSA).
  Anything else: `timeStamp.untrusted` naming the OID (fail closed; the
  corpus carries the four spellings above — RSA sha256 on DigiCert,
  `sha384WithRSAEncryption` on Truepic, ECDSA sha256 on the Adobe TSA of
  `ocsp*.jpg`; PSS is reasoned, no corpus token uses it).
- The TSA's trust (step 7) with M5's code: `CertificateProfileCheck::checkLeaf`
  with the accepted EKU list replaced by `1.3.6.1.5.5.7.3.8` (SPEC-015
  amendment: an `$ekus` override), validity judged at the token's time;
  `ChainCheck` on the token's certificates ordered leaf to root, the
  operator's `trust_anchors` and `allowed_list` (`TimestampCheck::tsaSettings()`
  keeps the operator's lists and replaces `trust_config`); a
  `signingCredential.*` outcome is renamed `timeStamp.trusted` /
  `timeStamp.untrusted`. Without settings: `untrusted`, "no trust anchors
  configured". `verify_trust: false` skips step 7 and reports neither.
- `Timestamp\TimestampResult` — `present`, `statuses`, `time` (the
  `genTime` when `validated`, for `signature_info.time`), `trusted`, and
  `trustedTime()`: the epoch SPEC-015 judges at, non-null only when
  `validated` *and* `trusted` (§14.6.1: "a trusted timestamp").
- `Report\StatusCode` gains the six cases (SPEC-010 amendment):
  `timeStamp.validated` and `timeStamp.trusted` as successes,
  `timeStamp.malformed`, `.mismatch`, `.outsideValidity`, `.untrusted` as
  informational — `ValidationResult::fromStatuses()` needs no change: the
  state ignores informational statuses already (SPEC-010/012 AC10).
- `Verifier` (SPEC-013 amendment): the timestamp check runs first on the
  active manifest — c2patool lists its `timeStamp.*` entries before
  everything else — and `'timestamp'` heads `checksPerformed` when a
  header is present; `CertificateProfileCheck::check()` receives
  `trustedTime()`; the `signingCredential.expired` explanation names the
  time used and why ("at the timestamp's time 2023-02-12T18:44:26+00:00" /
  "at now; the timestamp's TSA is not trusted" / "at now; no timestamp");
  `signature_info` gains `time` — the `genTime` as `gmdate('c')`,
  `2024-08-06T21:53:37+00:00` — when the token validated, as c2patool
  prints it (and omits it when not: `E-sig-CA`, `CA_ct`).
- Fixtures: two public certificates cut out of corpus tokens, with
  settings files that hold each as the only anchor —
  `tests/Fixtures/trust/truepic-root.pem` (`CN=RootCA, OU=Lens, O=Truepic`,
  self-signed) and `tests/Fixtures/trust/digicert-trusted-root-g4.pem`
  (the cross-certificate in DigiCert's tokens: subject `DigiCert Trusted
  Root G4`, issued by `DigiCert Assured ID Root CA`) — and c2patool's JSON
  for the three Truepic files, `C.jpg` and `CACA.jpg` under each, in
  `tests/Fixtures/c2patool/timestamp/`. Public certificates only; no key.
- The drift alarms (SPEC-013 amendment): `SPEC013_PUBLIC_NO_TIMESTAMP`
  and `SPEC013_RS_NO_TIMESTAMP` are removed. In their place one named
  list, `SPEC013_PUBLIC_TSA_NOT_CONFIGURED` = the three Truepic files:
  without settings they are `expired` here and `Valid` at c2patool,
  because c2patool trusts the Truepic TSA without an anchor and this
  verifier does not (ADR-0004 decision 3). AC6 measures the same files
  under the Truepic-root settings against c2patool under the same
  settings: there the exception must vanish.

**Out of scope**

- Timestamp *assertions* (`c2pa.time-stamp`, §18.17) and any timestamp on
  an ingredient manifest — M7, with the manifest chain.
- Fetching anything: a TSA chain that needs a certificate the token does
  not carry is `untrusted`, never fetched.
- Copying c2patool's `timeStamp.trusted` without anchors (ADR-0004,
  alternatives rejected).
- `claimSignature.insideValidity` / `.outsideValidity`: c2patool emits
  `insideValidity` on 39 of 41 corpus JSONs, the expired Nikon file
  included (measured 2026-09-22) — its explanation is "claim signature
  valid", not a validity statement. Success lists are never compared
  (SPEC-013); the codes stay out of the enum until a spec wants them.
- Using the timestamp's time for anything but SPEC-015's validity
  window: OCSP/CRL freshness, "signed before" claims — later, if ever.
- A second token, a second header: named above; not judged.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-017')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The oracles: the 41 c2patool JSONs of the two external corpora (their
`timeStamp.*` entries and `signature_info.time`, tabulated in step 40 §3),
new JSONs under the two anchor settings (made in the tests-first step,
`tests/Fixtures/c2patool/timestamp/`), the step-40 digests for the
countersigned bytes, and `openssl_verify` on the cut of SPEC-016 AC5.
Tokens are patched with the SPEC-016 test helpers (`spec016Splice`), moved
to `tests/Support/` so both files share them.

- **AC1 — every corpus token c2patool validates, this verifier validates, and the time is c2patool's** *(the positive path)*
  - Given every file of the two external corpora whose c2patool JSON
    carries `timeStamp.validated` on the active manifest (35 expected;
    the test derives the list from the JSONs)
  - When `Verifier::verify($stream)` runs (no settings)
  - Then the report carries `timeStamp.validated` with the same url in
    `validation_results.activeManifest.success`, listed before every
    other success as c2patool orders it; `signature_info.time` equals
    c2patool's `time` string byte for byte; `checks_performed` starts with
    `timestamp`; and the `validation_state` is what it was before this
    spec on every one of them (the timestamp changes no verdict without a
    trusted TSA).

- **AC2 — the corpus's two failures, and no verdict moves** *(the error path, measured)*
  - Given `public-testfiles/adobe-20220124-E-sig-CA.jpg`,
    `c2pa-rs/E-sig-CA.jpg` and `c2pa-rs/CA_ct.jpg`
  - When `Verifier::verify($stream)` runs
  - Then `E-sig-CA` (both) carries `timeStamp.mismatch` in `informational`
    with an explanation naming both digests' first bytes, no
    `timeStamp.validated`, no `signature_info.time`, and stays `Invalid`
    for `claimSignature.mismatch` alone; `CA_ct.jpg` carries
    `timeStamp.malformed` whose explanation contains `genTime`, `63` and
    `offset 86` (SPEC-016 AC9), no `time`, and the state it had before
    this spec — the same codes c2patool reports for the three
    (`timeStamp.mismatch` ×2, `timeStamp.malformed`).

- **AC3 — the CMS signature, three algorithms, one flipped bit each** *(step 4)*
  - Given the tokens of `c2pa-rs/C.jpg` (RSA PKCS#1 v1.5, sha256),
    `public-testfiles/truepic-20230212-camera.jpg`
    (`sha384WithRSAEncryption`) and `c2pa-rs/ocsp.jpg` (ECDSA P-256,
    sha256 — the Adobe TSA), each once as it is and once with one bit of
    `signature` flipped (`spec016Splice` on the last OCTET STRING)
  - When `TimestampCheck::judge($token, $tbs, null, $url)` runs (the
    manifest's own countersigned bytes as `$tbs`; `ocsp.jpg` through
    `judge()` because its store has two manifests and the Verifier refuses
    it until M7)
  - Then the unflipped tokens report `timeStamp.validated` (and
    `timeStamp.untrusted`, no anchors); the flipped ones report
    `timeStamp.untrusted` with an explanation naming the algorithm and
    "signature", no `validated`, `time` null — and a token whose
    `signatureAlgorithm` is patched to an OID outside the list
    (`1.2.840.113549.1.1.1` → `…1.1.2`, md2WithRSA, equal length) is
    `untrusted` naming that OID.

- **AC4 — messageDigest, sid, validity: one status each** *(steps 2, 3, 5)*
  - Given `C.jpg`'s token (a) with one byte of the `messageDigest`
    attribute's value changed, (b) with the `sid` serial's last byte
    changed (no certificate matches), and (c) rebuilt as
    `new TimeStampToken($token->signedData, <TstInfo with genTime
    2010-01-01T00:00:00Z>, 0)` — the DigiCert 2023 TSA certificate is
    valid 2023-07-14 to 2034-10-13
  - When `judge()` runs
  - Then (a) is `timeStamp.mismatch` naming `messageDigest`, (b) is
    `timeStamp.malformed` naming the serial and "no certificate", (c) is
    `timeStamp.outsideValidity` naming the time and both validity dates —
    each exactly one status, no `validated`, `time` null. And (d): the
    unpatched token through the same seam is `validated` — the control.

- **AC5 — the countersigned bytes: v1 over the claim, v2 over the signature, proven against four imprints** *(step 6)*
  - Given the active manifests of `c2pa-rs/C.jpg`,
    `public-testfiles/adobe-20220124-C.jpg`,
    `public-testfiles/truepic-20230212-camera.jpg` (`sigTst`, claim v1)
    and `c2pa-rs/C_with_CAWG_data.jpg` (`sigTst2`, claim v2)
  - When `TimestampCheck::countersignedBytes($cose, $header, $claimBytes)`
    runs and the result is hashed with the token's `hashAlgorithm`
  - Then the digests equal the four imprints (`64d055da…`, `0432594d…`,
    `d8160464…` sha384, `37ab305c…`); the bytes start with
    `84 70 "CounterSignature"`; and `judge()` on `C.jpg`'s token with the
    `sigTst2`-style bytes (the signature bstr) reports `timeStamp.mismatch`
    — the wrong payload is a mismatch, not an error.

- **AC6 — trust only through an anchor: Truepic's root and DigiCert's cross-certificate** *(step 7, and the verdict that changes)*
  - Given `tests/Fixtures/trust/truepic-root.settings.json` (the Truepic
    root as the only anchor, `verify_trust` true) and
    `tests/Fixtures/trust/digicert-trusted-root-g4.settings.json` (the
    cross-certificate as the only anchor)
  - When `Verifier::verify()` runs on the three Truepic files with the
    first, and on `c2pa-rs/C.jpg` with the second
  - Then the Truepic files report `timeStamp.validated` and
    `timeStamp.trusted`, no `signingCredential.expired`, and a
    `validation_state` and failure list equal to c2patool's under the same
    settings file (`tests/Fixtures/c2patool/timestamp/truepic-*.json`,
    made in the tests-first step — `Valid` or `Trusted` depending on
    whether the signer's chain reaches the same root; the test compares,
    it does not assume); `C.jpg` reports `timeStamp.trusted` with the
    cross-certificate matched as the anchor (its chain: leaf → `DigiCert
    Trusted G4 RSA4096 SHA256 TimeStamping CA` → the cross-certificate,
    DER-equal to the anchor); and the same files without settings report
    `timeStamp.untrusted` "no trust anchors configured" — Truepic stays
    `signingCredential.expired` with an explanation saying the time used
    was now because the TSA is not trusted.

- **AC7 — the TSA profile: `timeStamping` alone, at the token's time** *(step 7, the EKU rule)*
  - Given the DigiCert 2023 TSA leaf (`C.jpg`'s token) and, as a
    counter-example, `fixture-signed.jpg`'s own leaf (EKU
    `emailProtection`, no `timeStamping`)
  - When `CertificateProfileCheck::checkLeaf($leaf, TimestampCheck::tsaSettings(null), $genTime, $url, ekus: ['1.3.6.1.5.5.7.3.8'])` runs
  - Then the TSA leaf yields no `signingCredential.invalid`; the
    fixture leaf yields one naming EKU — and `judge()` on a token whose
    signer is that fixture leaf reports `timeStamp.untrusted` (the test
    reaches it through the seam: `tsaSettings()` must contain no EKU but
    `timeStamping`, asserted directly, since a token signed by the
    fixture key does not exist and will not be made).

- **AC8 — the header: absent, one token, more than one** *(bounds)*
  - Given `nikon-20221019-building.jpg` and our three
    `fixture-signed.*` (no header); `C.jpg`; and `C.jpg`'s header
    rewritten with the same token twice (`tstTokens` of two, through the
    seam `check()` gets: a `CoseSign1` built with the patched unprotected
    header — or, simpler, `TimestampHeader` with two tokens fed to a
    package-level `checkHeader()`; decided in the tests-first step)
  - When `check()` runs
  - Then the four report `present` false, no statuses, no `timestamp` in
    `checks_performed`, no `time`, and Nikon keeps
    `signingCredential.expired` with "at now; no timestamp"; `C.jpg`
    judges one token; the doubled header judges the first only and its
    `validated` explanation says "1 of 2 tokens judged".

- **AC9 — the report: order, keys, and the time SPEC-015 used** *(the shape)*
  - Given `c2pa-rs/C.jpg` with the DigiCert settings, and
    `fixture-signed.jpg` without
  - When `Verifier::verify()` runs and `toArray()` is taken
  - Then for `C.jpg`: `validation_results.activeManifest.success` starts
    `timeStamp.validated`, `timeStamp.trusted`, then the rest in the
    order SPEC-013 fixed; `signature_info` has exactly the keys `alg`,
    `issuer`, `common_name`, `cert_serial_number`, `time` in that order
    and equals c2patool's block byte for byte (SPEC-015 AC7's exclusion of
    `time` lifted); `checks_performed` is `['timestamp', 'signature',
    'certificate', 'trust', 'hashedUris', 'dataHash']`. For
    `fixture-signed.jpg`: no `time` key, `checks_performed` unchanged from
    SPEC-015.

- **AC10 — the drift alarms without their timestamp exceptions** *(the corpus, whole)*
  - Given the three corpora as SPEC-013 AC10–AC12 run them, with
    `SPEC013_PUBLIC_NO_TIMESTAMP` and `SPEC013_RS_NO_TIMESTAMP` deleted
    and `SPEC013_PUBLIC_TSA_NOT_CONFIGURED` = the three Truepic files
  - When the alarms run
  - Then every file compares as before except the Truepic three, which
    are named for the reason above; `ocsp.jpg` and
    `ocsp_with_assertion.jpg` — excused until now for "no timestamp" —
    need no excuse (they are `_MULTI`, refused before the timestamp, as
    before); and there is still no file where this verifier is more
    lenient than c2patool.

## References

- Specification: C2PA 2.4 §14.6 (time-stamps: `sigTst`/`sigTst2`, the
  countersigned data), §14.6.1 (validity judged at a *trusted*
  timestamp's time, else now), §15.2.2 / §15.7 (`timeStamp.validated`,
  `.mismatch`, `.malformed`, `.outsideValidity`, `.trusted`,
  `.untrusted`; informational); RFC 3161 §2.4.2 (`TSTInfo`, "the
  time-stamp token MUST be verified"); RFC 5652 §5.4 (the signed
  attributes' `SET OF` for the signature), §5.6 (signature verification:
  `messageDigest` first); RFC 9052 §4.4 (`Sig_structure`,
  `CounterSignature`); RFC 8017 §8.1 (RSASSA-PSS parameters). Read
  2026-09-22.
- Oracle: c2patool 0.27.22 on the 41 corpus JSONs (step 40 §3: 31
  `validated`+`trusted`, 3 with an ingredient `mismatch` beside, 2
  `untrusted`, 2 `mismatch`, 1 `malformed`, 2 none); the same tool under
  the two anchor settings (tests-first step); c2pa-rs 0.90.22
  `time_stamp/verify.rs` (the order, the `.informational` logging, the
  `signingTime`-over-`genTime` rule ADR-0004 decision 5 declines) and
  `crypto/cose/sigtst.rs` (`cose_countersign_data`; the header picked by
  name; "we only pay attention to the first"); `openssl_verify` = 1 on
  five tokens' re-tagged attributes (SPEC-016 AC5).
- Reasoned: `timeStamp.untrusted` for a failed CMS signature (c2pa-rs's
  choice; §15 offers no better code); PSS parameters (no corpus token);
  the `sid`-by-SKI path (no corpus token); "first token only" as c2pa-rs
  states it; the claim-version/header pairing left unchecked as c2pa-rs
  leaves it.
- Divergence, by design and named: `timeStamp.trusted` needs an anchor
  here (ADR-0004 decision 3) — informational on 34 files, and the
  Truepic verdict (`expired` vs `Valid`) until the operator configures
  the Truepic root; `signingTime` ≠ `genTime` is `malformed` here (no
  corpus token has them differ).

## API sketch

```php
// namespace Provemark\C2paVerifier\Timestamp;

final readonly class TimestampResult
{
    /** @param list<ValidationStatus> $statuses */
    public function __construct(
        public bool $present,          // a sigTst/sigTst2 header was there
        public array $statuses,        // timeStamp.* only, in the order judged
        public ?int $time,             // genTime when validated (signature_info.time), else null
        public bool $trusted,          // timeStamp.trusted was reached
    ) {}

    public static function none(): self;                 // no header
    /** The epoch SPEC-015 judges validity at: genTime when validated and trusted, else null (= now). */
    public function trustedTime(): ?int;
}

final readonly class TimestampCheck
{
    public const OID_EKU_TIME_STAMPING = '1.3.6.1.5.5.7.3.8';

    /** @var array<string, string> signatureAlgorithm OID => 'rsa' | 'rsa-pss' | 'ecdsa'; the digest from digestAlgorithm or the OID's own */
    public const SIGNATURE_ALGORITHMS = [
        '1.2.840.113549.1.1.1' => 'rsa',        // rsaEncryption
        '1.2.840.113549.1.1.11' => 'rsa',       // sha256WithRSAEncryption
        '1.2.840.113549.1.1.12' => 'rsa',       // sha384WithRSAEncryption
        '1.2.840.113549.1.1.13' => 'rsa',       // sha512WithRSAEncryption
        '1.2.840.113549.1.1.10' => 'rsa-pss',   // RSASSA-PSS
        '1.2.840.10045.4.3.2' => 'ecdsa',       // ecdsa-with-SHA256
        '1.2.840.10045.4.3.3' => 'ecdsa',       // ecdsa-with-SHA384
        '1.2.840.10045.4.3.4' => 'ecdsa',       // ecdsa-with-SHA512
    ];

    public function __construct(
        private DerReader $reader = new DerReader,
        private CertificateProfileCheck $profile = new CertificateProfileCheck,
        private ChainCheck $chain = new ChainCheck,
    ) {}

    /** The active manifest's timestamp, judged; never throws. */
    public function check(Manifest $manifest, ?TrustSettings $settings): TimestampResult;

    /** Steps 2–7 on a parsed token; the seam for outsideValidity and the algorithms. */
    public function judge(TimeStampToken $token, string $tbs, ?TrustSettings $settings, string $url): TimestampResult;

    /** ["CounterSignature", protected, h'', payload] — payload per header name. */
    public static function countersignedBytes(CoseSign1 $cose, string $header, string $claimBytes): string;

    /** The operator's anchors and allowed list, trust_config replaced by timeStamping alone, verify_trust kept. */
    public static function tsaSettings(?TrustSettings $operator): TrustSettings;
}

// namespace Provemark\C2paVerifier\Trust;  (SPEC-015 amendment)
// CertificateProfileCheck::checkLeaf(Certificate $leaf, ?TrustSettings $settings, ?int $at, string $url, ?array $ekus = null): array
//   $ekus non-null replaces BUILT_IN_EKUS + trust_config as the accepted list
// ChainCheck::checkCertificates(array $chainDer, TrustSettings $settings, string $url): array   (SPEC-014 amendment: the walk without a Manifest)

// namespace Provemark\C2paVerifier\Verifier;  (SPEC-013 amendment)
// check(): $ts = $this->timestamp->check($manifest, $settings);
//          statuses = $ts->statuses, checks = ['timestamp'] when present; then signature, certificate($ts->trustedTime()), trust, hashedUris, dataHash
// signatureInfo(): + 'time' => gmdate('c', $ts->time) when $ts->time !== null
```

## Open questions

- Non-blocker (tests-first step): the c2patool verdicts under the two
  anchor settings files (AC6) — whether the Truepic signer chain reaches
  the Truepic root (`Trusted`) or not (`Valid`); the exact count of AC1's
  files (35 expected); the wording of each explanation, fixed in the
  tests from the first green run.
- Non-blocker: where the SPEC-016 test helpers move to
  (`tests/Support/Der.php`, autoloaded under
  `Provemark\C2paVerifier\Tests\Support`) so that AC3/AC4 can patch tokens;
  the SPEC-016 Traceability rows are updated in the same step.
- Non-blocker: AC8's second shape (a doubled `tstTokens`) — through a
  rebuilt `CoseSign1` or a package-level `checkHeader()`; whichever keeps
  `TimestampCheck` without a public method the Verifier does not need.
- Non-blocker: the `signingCredential.expired` explanation now names the
  time used; SPEC-015's tests that match the old text ("no timestamp
  consulted yet; M6 will supply one") are updated in the same step and
  listed in the SPEC-015 amendment.
- Non-blocker: `timeStamp.*` on ingredient manifests (c2patool reports
  `mismatch` on the tampered ingredient of `CIE-sig-CA` beside the active
  manifest's `validated`) — M7's, when ingredient manifests are walked.

## Amendments

1. **2026-09-22, step 42b, at implementation** — test literals corrected against the code and the fixtures, no criterion changed in substance: the Nikon file is `nikon-20221019-building.jpeg` and the three signed fixtures sit at the fixtures root; AC1's front-door run uses the `full` settings for both corpora, as SPEC-013 AC11/AC12 do (the public oracle JSONs were made that way — `adobe-20220124-C` is `Trusted` there); the "no trust anchors" wording is `ChainCheck`'s ("… is not on the allowed list and no trust anchors are configured"); the EKU fault names `ExtendedKeyUsage`, not "EKU". The five older test files that count or order codes were adjusted under SPEC-010 amendment 5, SPEC-013 amendment 8 and SPEC-015 amendment 4. One rule made explicit in code rather than assumed: the TSA chain is ordered from the token by issuer → subject links from the signer (c2pa-rs `order_certificates_leaf_to_root`), so Truepic's root-first token walks as well as DigiCert's signer-first one.
2. **2026-09-22, step 44, found by the writers corpus (step 43)** *(confirmed by Maurice van Loon, 2026-09-22)* — `signature_info.time` renders the `genTime`'s fractional seconds as c2patool does (`2026-08-26T10:48:55.837381+00:00`; `TimestampResult::$timeFraction`); the epoch handed to SPEC-015 stays whole seconds. With SPEC-016 amendment 3 the Amazon Bedrock and `c2pa-ts` tokens validate (they were `malformed` on their negative nonces, which cost Amazon's file its time and, through `expired`, its verdict). AC11 added: on the five writers files `signature_info.time` equals c2patool's byte for byte where c2patool has one, Amazon's ES384 file validates and, with the DigiCert cross-certificate as anchor, is no longer `expired`; `c2pa-ts`'s v1 claim with `sigTst2` validates (the pairing unchecked, as decided).
3. **2026-09-22, step 44, found by the `c2pa-ts` token** *(confirmed by Maurice van Loon, 2026-09-22)* — two things a non-c2pa-rs writer taught. (a) RFC 5652 §5.4 signs "the complete DER encoding of the SET OF signedAttrs", and DER orders a SET OF by its elements' encodings (X.690 §11.6); every TSA measured until step 43 wrote the attributes already sorted, so re-tagging `A0` → `31` was the DER encoding. `c2pa-ts` writes them unsorted and signs the sorted form — the re-tag alone verified `0`, the sorted SET `1` (measured by hand on all five plausible inputs). `SignerInfo::signedAttributesForVerification()` now returns the DER-canonical SET: the Attribute encodings sorted as X.690 §11.6 says, the length re-encoded; on the five older tokens it is byte-equal to the re-tag (SPEC-016 AC5 stays as it is and measures that). This is a correctness fix, not a leniency: c2pa-rs re-encodes with `rasn` and so sorts too. (b) `c2pa-ts` writes the ECDSA CMS signature as raw R‖S (64 bytes), where RFC 3279 §2.2.3 has DER `ECDSA-Sig-Value`; c2patool accepts it. So does this verifier, by the rule: bytes that are a well-formed DER SEQUENCE of two INTEGERs pass through; otherwise, when they are exactly two coordinates long, they are converted as SPEC-009 converts COSE's R‖S; anything else fails as before. Both in AC11's test (the `c2pa-ts` file validates).

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Timestamp/TimestampCheckTest.php :: SPEC-017 AC1: every corpus token c2patool validates, this verifier validates, and the time is c2patool's / SPEC-017 | src/Timestamp/TimestampCheck.php :: check(), checkHeader(), judge() (steps 2–6); src/Verifier/Verifier.php :: verify(), check() (`timestamp` first), signatureInfo() (`time`); src/Timestamp/TimestampResult.php |
| AC2 | tests/Unit/Timestamp/TimestampCheckTest.php :: SPEC-017 AC2: E-sig-CA is a mismatch, CA_ct is malformed, and no verdict moves / SPEC-017 | src/Timestamp/TimestampCheck.php :: judge() (step 6), checkHeader() (the TimestampException → malformed); src/Report/StatusCode.php :: isInformational() |
| AC3 | tests/Unit/Timestamp/TimestampCheckTest.php :: SPEC-017 AC3: the CMS signature — * (3) and SPEC-017 AC3: a signature algorithm outside the list is untrusted, naming the OID / SPEC-017 | src/Timestamp/TimestampCheck.php :: verifySignature(), SIGNATURE_ALGORITHMS, opensslVerify(); src/Cose/RsaPss.php (the PSS path, reasoned) |
| AC4 | tests/Unit/Timestamp/TimestampCheckTest.php :: SPEC-017 AC4: messageDigest, sid and validity each give exactly one status; the control validates / SPEC-017 | src/Timestamp/TimestampCheck.php :: judge() (steps 2, 3, 5); src/Timestamp/SignedData.php :: signerCertificate() |
| AC5 | tests/Unit/Timestamp/TimestampCheckTest.php :: SPEC-017 AC5: the countersigned bytes equal the four imprints, and the wrong payload is a mismatch / SPEC-017 | src/Timestamp/TimestampCheck.php :: countersignedBytes(), bstr() |
| AC6 | tests/Unit/Timestamp/TimestampCheckTest.php :: SPEC-017 AC6: the Truepic root as anchor … and SPEC-017 AC6: the DigiCert cross-certificate as anchor … / SPEC-017 | src/Timestamp/TimestampCheck.php :: judge() (step 7), orderedChain(), tsaSettings(); src/Trust/ChainCheck.php :: checkCertificates(); src/Trust/CertificateProfileCheck.php :: check() ($at, $reason); src/Timestamp/TimestampResult.php :: trustedTime() |
| AC7 | tests/Unit/Timestamp/TimestampCheckTest.php :: SPEC-017 AC7: the TSA profile accepts timeStamping alone, and tsaSettings() carries nothing else / SPEC-017 | src/Trust/CertificateProfileCheck.php :: checkLeaf() ($ekus), ekuFaults(); src/Timestamp/TimestampCheck.php :: tsaSettings(), OID_EKU_TIME_STAMPING |
| AC8 | tests/Unit/Timestamp/TimestampCheckTest.php :: SPEC-017 AC8: no header … and SPEC-017 AC8: one token is judged; a doubled header … / SPEC-017 | src/Timestamp/TimestampCheck.php :: check(), checkHeader(); src/Timestamp/TimestampResult.php :: none(); src/Verifier/Verifier.php :: check() (the reason "no timestamp") |
| AC9 | tests/Unit/Timestamp/TimestampCheckTest.php :: SPEC-017 AC9: the report — timeStamp entries first, signature_info with time equal to c2patool's, checks_performed / SPEC-017 | src/Verifier/Verifier.php :: check(), signatureInfo(); src/Report/ValidationResult.php :: toArray() (unchanged: informational is its own list) |
| AC10 | tests/Unit/Timestamp/TimestampCheckTest.php :: SPEC-017 AC10: the NO_TIMESTAMP exceptions are gone; TSA_NOT_CONFIGURED names the files that stay expired, and an anchor un-expires them / SPEC-017 | tests/Pest.php :: SPEC013_PUBLIC_TSA_NOT_CONFIGURED, SPEC013_RS_TSA_NOT_CONFIGURED; tests/Unit/Verifier/VerifierTest.php :: AC11, AC12; src/Verifier/Verifier.php :: check() (the time handed to the profile) |
| AC11 (amendments 2–3) | tests/Unit/Timestamp/TimestampCheckTest.php :: SPEC-017 AC11: on the writers corpus signature_info.time equals c2patool's, and the negative-nonce tokens validate / SPEC-017 | src/Timestamp/TimestampResult.php :: $timeFraction, timeIso(); src/Timestamp/SignerInfo.php :: signedAttributesForVerification() (the DER-canonical SET), $attributeEncodings; src/Timestamp/TimestampCheck.php :: ecdsaDer(), isDerEcdsaSignature(); src/Verifier/Verifier.php :: signatureInfo() |
