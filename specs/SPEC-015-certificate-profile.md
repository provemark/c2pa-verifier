# SPEC-015: The certificate profile — is the leaf a C2PA signing certificate? `signingCredential.invalid` / `.expired`, `signature_info`

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-21                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-014 makes a leaf `Trusted` when its chain reaches an anchor. It
asks nothing of the leaf itself. C2PA 2.4 §14.5 does: a signing
certificate must be X.509 v3, an end-entity, within its validity at the
time of signing, signed with an allowed algorithm, carrying a key of an
allowed type and size, with a KeyUsage that permits signing and an
ExtendedKeyUsage from the accepted list — and without `anyExtendedKeyUsage`
or a meaningless combination. Step 33 measured what c2patool does with
twelve certificates that break exactly one of those rules: nine are
`signingCredential.invalid`, one `signingCredential.expired`, and three
`Trusted` — among them a leaf whose KeyUsage is `nonRepudiation` alone,
which c2pa-rs accepts in place of `digitalSignature` (its
`certificate_profile.rs`, read to the end). Maurice decided, as for the
EKU list in ADR-0003, to mirror c2pa-rs there ("optie a").

This spec is the profile as criteria, on what `ext-openssl` reports —
`openssl_x509_parse()` and `openssl_pkey_get_details()`, measured on
every variant in step 33 — plus the `signature_info` block c2patool
prints per manifest, which the sister library's `signer()` reads. With
it, M5's "done when" is measurable: verdicts equal to c2patool's with
and without the trust file; the test certificate without a trust file →
`signingCredential.untrusted`.

## Scope

**In scope**

- `Trust\Certificate` grows (SPEC-014's class, no amendment needed: it
  is this spec's to extend): `version` (X.509, 1-based: OpenSSL's
  `version` + 1), `validFrom` / `validTo` (epoch), `signatureAlgorithm`
  (OpenSSL's long name, e.g. `ecdsa-with-SHA256`, `rsassaPss`),
  `keyType` (`EC` / `RSA` / `Ed25519` / other), `keyBits`, `curve`
  (OpenSSL's name, `prime256v1` / `secp384r1` / `secp521r1` / …),
  `extendedKeyUsage` as a list of **OIDs** (OpenSSL's long names mapped
  through a table for the eight it names — E-mail Protection, Time
  Stamping, OCSP Signing, Code Signing, TLS Web Server/Client
  Authentication, Any Extended Key Usage, Document Signing — and a
  dotted string taken as an OID; absent → `null`), `keyUsage` as a list
  of OpenSSL's names (absent → `null`), `hasAuthorityKeyIdentifier`,
  `hasSubjectKeyIdentifier`, `organization` (subject O), `serialDecimal`
  (from `serialNumberHex`, a base-16 → base-10 conversion on strings —
  no `gmp`, no `bcmath`).
- `Trust\CertificateProfileCheck::check(Manifest $manifest, ?TrustSettings $settings = null, ?int $at = null): list<ValidationStatus>`,
  url the signature box's, on the **leaf** of the x5chain; `$at` the
  epoch to judge validity at — `null` = now (M6 will pass the
  timestamp). One `signingCredential.invalid` status **per fault found**,
  each naming the fault (c2patool stops at the first; the reader here
  gets them all; the codes compare as a set); validity is its own code.
  The rules, in this order, every one measured in step 33 unless noted:
  1. **End-entity**: basicConstraints `CA:TRUE` → `.invalid`
     (`ca-as-leaf`).
  2. **Version 3** (`v1`-style certificates → `.invalid`; reasoned:
     step 33's intended v1 came out v3, see Open questions).
  3. **Validity**: `$at` before `validFrom` or after `validTo` →
     `signingCredential.expired`, explanation naming both dates and the
     time used and that no timestamp was consulted (`expired`).
  4. **Signature algorithm** one of `sha256WithRSAEncryption`,
     `ecdsa-with-SHA256`, `ecdsa-with-SHA384`, `ecdsa-with-SHA512`,
     `ED25519`, `rsassaPss` (c2pa-rs's list; OpenSSL's names, the PSS
     parameters left to OpenSSL when it verified the link); else
     `.invalid` (reasoned; no variant — OpenSSL will not sign with an
     algorithm outside its own).
  5. **Key**: EC on `prime256v1` / `secp384r1` / `secp521r1`; RSA ≥ 2048
     bits; Ed25519; else `.invalid` (`rsa-1024`, `curve-secp256k1` —
     both already refused by SPEC-009 at the signature; this check
     names the certificate's fault as well).
  6. **KeyUsage** present, and `Digital Signature` or `Non Repudiation`
     or `Certificate Sign` among its names; `Digital Signature` together
     with `Certificate Sign` on an end-entity → `.invalid`; absent →
     `.invalid` (c2pa-rs's rule verbatim, Maurice's option a:
     `no-digital-signature` is *not* a fault; `v1`'s missing KU is).
  7. **ExtendedKeyUsage** present (absent on an end-entity → `.invalid`,
     `no-eku`); `anyExtendedKeyUsage` → `.invalid` (`eku-any`); at least
     one OID on the accepted list → else `.invalid` (`eku-outside-list`);
     the accepted list = emailProtection `1.3.6.1.5.5.7.3.4`,
     documentSigning `…3.36`, timeStamping `…3.8`, OCSPSigning `…3.9`,
     MS C2PA Signing `1.3.6.1.4.1.311.76.59.1.9`, C2PA Signing
     `1.3.6.1.4.1.62558.2.1` (c2pa-rs's `valid_eku_oids.cfg`), plus
     `TrustSettings::$trustConfig` — additive, never narrowing (ADR-0003
     item 4); the combinations c2pa-rs refuses → `.invalid`: OCSPSigning
     with timeStamping, or either of them with any of emailProtection,
     codeSigning, serverAuth, clientAuth or an OID outside those
     (`eku-mixed`).
  8. **AuthorityKeyIdentifier** present → else `.invalid` (c2pa-rs's
     `aki_good`; reasoned — every variant carries one, OpenSSL 3 adds
     it; a hand-built parse array proves the rule in the test).
- `Verifier`: `CertificateProfileCheck` runs **always**, right after
  `ClaimSignatureCheck` and before `ChainCheck`, with or without settings
  and whatever `verify_trust` says — measured in step 34a: c2patool
  reports `signingCredential.expired` / `.invalid` on `expired.png` and
  `no-eku.png` with no settings at all and with `verify_trust: false`
  (amendment 1). The profile is a property of the signature's
  certificate, not of the operator's trust. `checksPerformed` gains
  `certificate`. With settings, the EKU list is the built-in six plus
  `trust_config`; without, the built-in six.
- **`signature_info`** in `VerificationReport::toArray()`, per manifest,
  as c2patool prints it: `{alg: "Es256"|"Es384"|"Es512"|"Ps256"|"Ps384"|"Ps512"|"Ed25519", issuer: <leaf O>, common_name: <leaf CN>, cert_serial_number: <decimal>}` —
  read from the active manifest's leaf whether or not a trust check ran
  (c2patool prints it without settings too; `png.json` of step 14).
  When the chain cannot be read, the block is absent. The sister
  library's `signer()` must read it.
- `StatusCode` gains `signingCredential.expired` (failure), verbatim;
  `signingCredential.invalid` already exists (SPEC-010).

**Out of scope** (each needs its own spec before it may be built)

- **Unknown critical extensions** (c2pa-rs's `handled_all_critical`):
  `ext-openssl` does not expose an extension's criticality. A leaf with
  an unknown critical extension is `.invalid` there and passes here —
  the one place M5 accepts more than the oracle, named as a risk;
  closed when M6's ASN.1 reader exists (ADR-0003 item 5).
- The signing *time* from the timestamp — M6 passes `$at`; until then
  *now*, and the explanation says so.
- The profile of the *intermediates* and of the TSA certificate (M6).
- OCSP / CRL, the C2PA Trust List, the CAWG identity assertion's
  certificates.
- `mandatory_ekus` (c2pa-rs's unset-by-default "must have one of
  these") — a later spec if an operator asks.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-015')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The oracle is `tests/Fixtures/c2patool/profile/*.json` (step 33, c2patool
0.27.22 with `tests/Fixtures/profile/throw-away-root.settings.json`) and
the `trusted/` and step-14 JSONs for `signature_info`. Two measurements
are still to be taken before the tests (Open questions).

- **AC1 — the control: `good` and `eku-c2pa` pass the profile, and the four fixtures with the full settings stay `Trusted`**
  - Given `profile/good.png` and `profile/eku-c2pa.png` with the
    throw-away settings, and the four fixtures with `trust/full.settings.json`
  - When verified
  - Then no status has a code starting `signingCredential.invalid` or
    `.expired`, `signingCredential.trusted` is present, `state` is
    `Trusted` — equal to the oracle's for each; and `eku-c2pa`'s leaf
    has `extendedKeyUsage` `['1.3.6.1.4.1.62558.2.1']` as the OID list

- **AC2 — one departure, one `signingCredential.invalid`, the state `Invalid`, as c2patool**
  - Given `profile/{ca-as-leaf, eku-outside-list, eku-any, eku-mixed, no-eku, v1, rsa-1024, curve-secp256k1}.png` with the throw-away settings
  - When verified
  - Then each has at least one `signingCredential.invalid` whose
    explanation names the departure (`CA`, `Code Signing`, `any`,
    `Time Stamping`, `no ExtendedKeyUsage`, `no KeyUsage`, `1024`,
    `secp256k1` respectively), still `signingCredential.trusted` as a
    success (the chain is intact), `state` `Invalid`; and the oracle's
    `validation_status` codes are `['signingCredential.invalid']` for
    each, its state `Invalid`

- **AC3 — expired: its own code, and the time used is named**
  - Given `profile/expired.png` with the throw-away settings, verified
    at now and, through `CertificateProfileCheck::check(…, at: <2024-06-01>)`,
    at a time inside its validity
  - When checked
  - Then at now: `signingCredential.expired` whose explanation carries
    `2024-01-01`, `2025-01-01`, the time used and the words "no
    timestamp"; `state` `Invalid`; the oracle's codes
    `['signingCredential.expired']`. At 2024-06-01: no `.expired`

- **AC4 — KeyUsage as c2pa-rs keeps it** *(decision: option a)*
  - Given `profile/no-digital-signature.png` (KU `Non Repudiation` only)
    with the throw-away settings, and hand-built `Certificate` cases:
    KU `Digital Signature, Certificate Sign` on `CA:FALSE`; KU absent
  - When checked
  - Then the variant passes the profile and is `Trusted`, equal to the
    oracle (`no-digital-signature.json`); the two hand-built cases give
    `signingCredential.invalid` naming `Certificate Sign` and `no
    KeyUsage`

- **AC5 — the EKU list is the built-in six plus `trust_config`, never fewer** *(ADR-0003 item 4)*
  - Given `profile/eku-outside-list.png` (Code Signing) with (a) the
    throw-away settings and (b) the same settings whose `trust_config`
    adds `1.3.6.1.5.5.7.3.3` (codeSigning), and `fixture-signed.png`
    with `trust/anchors-wrong-eku.settings.json` (`trust_config` =
    documentSigning only; the leaf has emailProtection)
  - When verified
  - Then (a) `.invalid`, (b) no `.invalid` and `Trusted`, and the PNG
    under the narrowed config `Trusted` — equal to
    `trusted/png-anchors-wrong-eku.json`

- **AC6 — the rules the variants cannot show, on hand-built parse data** *(required: error / malformed input)*
  - Given `Certificate` cases built from a parse array (a seam the
    implementation provides for tests): version 1; signature algorithm
    `sha1WithRSAEncryption`; an EC key on `brainpoolP256r1`; no
    AuthorityKeyIdentifier; EKU `OCSP Signing` + `Time Stamping`
  - When checked
  - Then each gives `signingCredential.invalid` naming the rule (`v1`,
    `sha1WithRSAEncryption`, `brainpoolP256r1`, `AuthorityKeyIdentifier`,
    `OCSP Signing`); none escapes as an exception

- **AC7 — `signature_info` is c2patool's**
  - Given the four fixtures verified *without* settings, and `profile/good.png`
    with the throw-away settings
  - When `toArray()` runs
  - Then `manifests.<active>.signature_info` equals the oracle's block
    for that file (`c2patool/{jpg,png,webp,adobe-20220124-C}.json`,
    `c2patool/profile/good.json`): `alg` `Es256` (Adobe: `Ps256`),
    `issuer` the leaf's O (`C2PA Test Signing Cert`; for `good`: `C2PA
    Verifier throw-away hierarchy`), `common_name`, and
    `cert_serial_number` as the decimal string — the PNG's
    `640229841392226413189608867977836244731148734950` from hex
    `7024E6247605F1D65F1B477551D4FAFCB5ED91E6`; and the sister parser's
    `signer()` returns a `SignerInfo` with those values

- **AC8 — the profile is checked always; the two checks are independent**
  - Given `profile/expired.png` verified (a) with settings whose anchor
    is the *EC test root* (not the throw-away one), (b) with no settings,
    (c) with `trust/verify-off.settings.json`; and `profile/no-eku.png`
    with no settings
  - When verified
  - Then (a) and (b) give both `signingCredential.untrusted` and
    `signingCredential.expired`, (c) gives `.expired` alone, `no-eku`
    without settings gives `.invalid` and `.untrusted`; every state
    `Invalid`; `checksPerformed` holds `certificate` in all four and
    `trust` only in (a); and c2patool's recorded codes are the same
    (`profile/expired-wrong-anchor.json`, `expired-no-settings.json`,
    `expired-verify-off.json`, `no-eku-no-settings.json` — step 34a)

- **AC9 — M5's "done when": with and without the trust file, the verdicts are c2patool's**
  - Given the four fixtures, verified without settings, with
    `trust/full.settings.json`, and with `trust/verify-off.settings.json`
  - When compared with `c2patool/{name}.json`, `c2patool/trusted/{name}.json`
    and `trusted/png-verify-off.json`
  - Then `validation_state` equals in all cases (`Valid` /
    `Trusted` / `Valid`), the credential codes equal (`untrusted` /
    `trusted` / none), and the test certificate without a trust file
    gives `signingCredential.untrusted` — the brief's M5 line, measured

- **AC10 — the codes are verbatim, and the drift alarm grows**
  - Given `StatusCode::cases()`, and SPEC-013's corpus with the full
    settings and the twelve profile variants with the throw-away
    settings
  - When read and verified
  - Then the enum has twenty-four cases, `signingCredential.expired`
    character for character and a failure; for the 22 + 12 files the
    state equals the oracle's and the credential-code set equals the
    oracle's — no normalisation needed for `signingCredential.*`; the
    subset rule of SPEC-013 AC10 holds for the rest

## References

- Specification: C2PA 2.4 §14.5 (the certificate profile: v3,
  end-entity, validity, algorithms, keys, KU, EKU), §14.4.1 (the EKU
  list), §15.7 / §15.2.2 (`signingCredential.invalid`, `.expired`,
  verbatim). Read 2026-09-21 (step 30).
- Oracle: c2patool 0.27.22 on the twelve step-33 variants
  (`tests/Fixtures/c2patool/profile/`), on the fixtures with and without
  settings (steps 14, 30, 31); c2pa-rs `main` `58eac79`
  `sdk/src/crypto/cose/certificate_profile.rs:37–520` (the rules, in
  order: not CA, v3, validity at timestamp else now, signature
  algorithm list, PSS parameters, EC curves, RSA ≥ 2048, EKU rules, KU
  rules, AKI/SKI, unknown critical extensions) and
  `valid_eku_oids.cfg`; `openssl_x509_parse()` /
  `openssl_pkey_get_details()` on every variant (step 33: the names
  OpenSSL 3.6 prints — `E-mail Protection`, `Any Extended Key Usage`, a
  dotted OID for the C2PA one, `Digital Signature, Non Repudiation`,
  `CA:TRUE`, `version` 2 for v3, `serialNumberHex`).
- Reasoned: rules 2, 4 and 8 rest on c2pa-rs's code and hand-built
  data, not on a re-signed file (Open questions); the OpenSSL-name →
  OID table for EKUs; the hex → decimal serial.
- Divergences, kept: none intended on the twelve variants. Accepted
  more than the oracle: unknown critical extensions (Out of scope).

## API sketch

```php
// namespace Provemark\C2paVerifier\Trust;

final readonly class Certificate
{
    // SPEC-014's fields, plus:
    public int $version;                     // X.509 version, 1-based
    public int $validFrom;                   // epoch
    public int $validTo;
    public string $signatureAlgorithm;       // OpenSSL's long name
    public string $keyType;                  // EC | RSA | Ed25519 | other
    public int $keyBits;
    public ?string $curve;
    /** @var list<string>|null */ public ?array $extendedKeyUsage;   // OIDs
    /** @var list<string>|null */ public ?array $keyUsage;           // OpenSSL's names
    public bool $hasAuthorityKeyIdentifier;
    public bool $hasSubjectKeyIdentifier;
    public ?string $organization;
    public string $serialDecimal;

    /** @param array<string, mixed> $parsed  @param array<string, mixed> $key  a seam for tests: what openssl_x509_parse() and openssl_pkey_get_details() would give */
    public static function fromParsed(string $der, array $parsed, array $key): self;
}

final readonly class CertificateProfileCheck
{
    /** The built-in EKU list (c2pa-rs valid_eku_oids.cfg); trust_config adds. */
    public const BUILT_IN_EKUS = ['1.3.6.1.5.5.7.3.4', '1.3.6.1.5.5.7.3.36', '1.3.6.1.5.5.7.3.8', '1.3.6.1.5.5.7.3.9', '1.3.6.1.4.1.311.76.59.1.9', '1.3.6.1.4.1.62558.2.1'];

    /** @return list<ValidationStatus>  $at: epoch to judge validity at; null = now */
    public function check(Manifest $manifest, ?TrustSettings $settings = null, ?int $at = null): array;
}

// namespace Provemark\C2paVerifier\Report;
enum StatusCode: string { /* … */ case SigningCredentialExpired = 'signingCredential.expired'; }

// namespace Provemark\C2paVerifier\Verifier;
// VerificationReport::toArray(): manifests.<label>.signature_info = {alg, issuer, common_name, cert_serial_number}
```

## Open questions

- Measured in step 34a (amendment 1): c2patool runs the profile check
  without settings and with `verify_trust: false`; `expired.png` with
  the EC test root as anchor gives `.expired` and `.untrusted` together.
- Non-blocker: the `v1` variant of step 33 is *not* v1 — OpenSSL 3's
  `x509 -req -CA` adds SKI/AKI and so v3 — it stands as the "no KU, no
  EKU" variant (which is why c2patool refused it) and is documented as
  such; the version rule is tested on hand-built data (AC6).
- Non-blocker: OpenSSL's EKU long names could differ between OpenSSL
  versions (CI runs 8.3/8.4/8.5 on one distribution); the table is
  checked against the eight names measured, and an unknown name that is
  not a dotted OID is `.invalid` with the name in the message — fail
  closed, and visible.

## Amendments

1. **2026-09-21, step 34a, before the tests (per the Open question)** —
   the profile check runs always, after the signature check, whatever
   the settings: c2patool 0.27.22 reports `signingCredential.expired` on
   `expired.png` and `.invalid` on `no-eku.png` with no settings and
   with `verify_trust: false` (`tests/Fixtures/c2patool/profile/
   expired-no-settings.json`, `expired-verify-off.json`,
   `no-eku-no-settings.json`). The Verifier bullet, the `check()`
   signature (settings optional), `checksPerformed` (`certificate`) and
   AC8 changed accordingly.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
| AC10                 | —                           | —                    |
