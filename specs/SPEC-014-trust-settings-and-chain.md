# SPEC-014: Trust — the settings read, the allowed list, the chain walk to an anchor; `Trusted`

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-21                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

After SPEC-013 the verifier says `Valid` when the mathematics hold: the
claim is signed by the key in the leaf certificate, and the assertions
and the asset are what that signer saw. It says nothing about *who* that
signer is. A self-signed certificate made a minute ago passes every
check so far. C2PA 2.4 §14.4.1 gives the validator the two lists that
turn "a signer" into "a signer I trust": trust anchors (roots and, by
c2pa-rs's `PARTIAL_CHAIN`, intermediates) that the leaf's chain must
reach, and an allowed list of end-entity certificates accepted outright.
§15.7 names the outcome: `signingCredential.trusted` or `.untrusted`, and
c2patool's `validation_state` becomes `Trusted` — the third state, and
the one a consumer actually wants.

Step 30 measured all of it: the two test hierarchies with identical
names, the fixtures' chains without their root, c2patool under nine
settings, c2pa-rs's trust code line by line, and `ext-openssl`'s reach.
ADR-0003 decided the shape: an own chain walk on `openssl_x509_verify`,
the allowed list first, no files, no `phpseclib`. This spec is that
decision as criteria. The certificate *profile* (§14.5 — is the leaf a
C2PA signing certificate at all?) is SPEC-015; this spec asks only
"does the chain reach an anchor?".

## Scope

**In scope**

- `Trust\TrustSettings` — the shared settings format, read from JSON or
  built in code, every value *contents*, never a path:
  `trust.trust_anchors` (PEM, zero or more certificates),
  `trust.allowed_list` (PEM), `trust.trust_config` (the EKU list; read
  here, used by SPEC-015), `verify.verify_trust` (bool, default true).
  `fromJson(string)`, `fromArray(array)`, and named constructors for
  tests. Every PEM block is decoded to DER once; a block that does not
  decode, a non-boolean `verify_trust`, an unknown top-level key, a
  value of the wrong type → `TrustException` (a `TrustSettings` is
  either whole or absent). Bounded: at most `maxCertificates` (default
  256) per list.
- `Trust\Certificate` — one DER certificate with what the walk needs:
  `der`, `sha256`, `subject` and `issuer` as `openssl_x509_parse()` gives
  them (the whole array, compared as a whole), `publicKey` (an
  `OpenSSLAsymmetricKey`), `isCa` (basicConstraints), plus
  `signedBy(Certificate $issuer): bool` = `openssl_x509_verify() === 1`.
  A DER `openssl_x509_read()` refuses → `TrustException`.
- `Trust\ChainCheck::check(Manifest $manifest, TrustSettings $settings): list<ValidationStatus>`,
  the same shape as the other checks, url the signature box's:
  1. `verify_trust` false → `[]` (no code at all; measured:
     `png-verify-off.json`).
  2. The chain from `CoseSign1::fromBytes(signatureBytes())->chain`, leaf
     first (SPEC-008 bounded it at 16). A `CoseException` → its status,
     as `ClaimSignatureCheck` does.
  3. **Allowed list**: the leaf's SHA-256 among the allowed list's →
     `signingCredential.trusted`, explanation naming the list ("found in
     the allowed list") — no chain walked (c2pa-rs: `EndEntity`).
  4. **Anchors**: the walk, from the leaf, at most `chain length`
     steps: the current certificate is *trusted* when its DER equals an
     anchor's, or when it is `signedBy()` an anchor (an intermediate on
     the anchor list, or the root supplied by the validator — the EC
     fixtures' case). Otherwise the next certificate in `x5chain` must
     have `subject === current.issuer` and `current.signedBy(next)`;
     then it becomes current. The chain exhausted, a name that does not
     match, or a link that does not verify → `signingCredential.untrusted`
     with the reason (which link, which names) — never a guess at a
     certificate the chain did not carry. No anchors and no allowed hit
     → `signingCredential.untrusted` ("no trust anchors configured").
  5. The explanation of `.trusted` names the anchor's subject CN and
     the depth; of `.untrusted`, the step that failed.
- `Report\ValidationState` gains `Trusted`, and
  `ValidationResult::fromStatuses()` gains the rule, measured on
  c2patool in steps 14 and 30: **`Trusted`** = at least one
  `signingCredential.trusted` success and no failure; **`Valid`** = at
  least one success and every failure, if any, is
  `signingCredential.untrusted` (c2patool: `untrusted` is a failure and
  the state stays `Valid`, `png.json` of step 14); **`Invalid`**
  otherwise, the empty report included. `validation_status` is omitted
  from `toArray()` when empty (SPEC-013 amendment 3; measured: every
  `Trusted` JSON of step 30 lacks the key).
- `StatusCode` gains `signingCredential.trusted` (success) and
  `signingCredential.untrusted` (failure), verbatim.
- **SPEC-013 amendment 3**: `Verifier::verify($stream, ?TrustSettings
  $trust = null)`: with settings, `ChainCheck` runs after the signature
  check and `checksPerformed` gains `trust`; without, nothing changes
  (the report is what it was, and `checksPerformed` says so).
  `VerificationReport::toArray()` omits `validation_status` when empty.
- Deptrac: `Trust` may see `Cose` (the chain) and `Support`.

**Out of scope** (each needs its own spec before it may be built)

- The certificate profile (§14.5): version, validity, algorithms, key
  sizes, EKU (with the list `trust_config` adds to, ADR-0003 item 4),
  KU — `signingCredential.invalid` / `.expired` — SPEC-015. Until then a
  chain of otherwise unsuitable certificates that reaches an anchor is
  `Trusted` here; SPEC-015 is the spec that makes `Trusted` mean what
  §14 means, and M5's "done when" is measured when both are in.
- The signing *time*: the walk and (in SPEC-015) the validity are
  judged at *now* until M6 supplies the timestamp; the report says so
  in the explanation.
- `signature_info` (alg, issuer, CN, serial) in the report — SPEC-015,
  which reads the leaf's fields anyway.
- Revocation (OCSP, CRL), the C2PA Trust List itself (fetched from the
  network — never in the verification path; an operator supplies it as
  `trust_anchors` contents), ingredient manifests' signers (M7).
- Trust settings from a *path*: the caller reads the file. As SPEC-013:
  no I/O this verifier was not handed.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-014')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The oracle is `tests/Fixtures/c2patool/trusted/*.json` (step 30, c2patool
0.27.22 under `tests/Fixtures/trust/*.settings.json`), and — ADR-0003's
second oracle — `openssl_x509_checkpurpose()` with the same anchors
written to a temporary file in the test. Three variants are made and
measured in the tests-first step (Open questions).

- **AC1 — the four fixtures with the full settings: `Trusted`, and the words are c2patool's** *(the first `Trusted`)*
  - Given `fixture-signed.{jpg,png,webp}` and
    `public-testfiles/adobe-20220124-C.jpg`, and
    `TrustSettings::fromJson(tests/Fixtures/trust/full.settings.json)`
  - When `Verifier::verify($stream, $settings)` runs
  - Then `checksPerformed` is `['signature', 'trust', 'hashedUris',
    'dataHash']`, the statuses hold exactly one `signingCredential.trusted`
    with url `self#jumbf=/c2pa/<label>/c2pa.signature` — equal to the
    entry under `success` in `trusted/{jpg,png,webp,adobe-20220124-C}.json`
    — and no failure; `state` is `Trusted`, equal to the oracle's
    `validation_state`; `toArray()` has no `validation_status` key, as
    the oracle; and the sister parser's `isTrusted()` is true

- **AC2 — the wrong root: `untrusted`, and the state is `Valid`, not `Invalid`** *(c2patool's nuance, measured)*
  - Given the PNG with `rsa-root-only.settings.json` and the Adobe JPEG
    with `ec-root-only.settings.json`
  - When verified
  - Then each has exactly one failure, `signingCredential.untrusted`
    with the signature url, whose explanation names the link that found
    no anchor (the intermediate's subject CN for the PNG, the root's for
    the Adobe file); `state` is **`Valid`**, equal to
    `trusted/png-untrusted-rsa-root-only.json` and
    `trusted/adobe-untrusted-ec-root-only.json`; `validation_status`
    holds that one entry; the sister parser's `isTrusted()` is false and
    `validationState()` `Valid`

- **AC3 — the allowed list: trusted without a chain**
  - Given the PNG with `allowed-only.settings.json` (no anchors) and,
    in code, `TrustSettings` with the allowed list *and* an anchor set
    that does not reach the PNG (the RSA root)
  - When checked
  - Then both give `signingCredential.trusted` whose explanation says
    the leaf was found in the allowed list (the oracle:
    `trusted/png-allowed-only.json`, "EndEntity"); the second proves the
    allowed list wins before the anchors are tried

- **AC4 — the walk needs the intermediate the chain carries, and an intermediate may be the anchor**
  - Given the new `binding/x5chain-leaf-only.png` (the PNG store with
    the intermediate removed from `x5chain`, which sits in the
    *protected* header — measured: labels `[1, 33]` — so the signature
    breaks with it) with the full settings, and the untouched PNG with
    the new `intermediate-anchor.settings.json` (anchors = the EC
    intermediate alone; `PARTIAL_CHAIN`: an intermediate on the anchor
    list ends the walk)
  - When checked
  - Then the first gives `signingCredential.untrusted` whose explanation
    says the chain ends at `C2PA Signer`, issued by `Intermediate CA`,
    which the chain does not carry and no anchor signs — next to the
    `claimSignature.mismatch` the edit causes, both measured on c2patool
    in the tests-first step; the second gives `signingCredential.trusted`
    at depth 1 with `Intermediate CA` named as the anchor

- **AC5 — no trust by name** *(the brief's rule, measured on the two hierarchies)*
  - Given the PNG (EC chain) with `rsa-root-only.settings.json` — an
    anchor whose subject is *byte for byte* the EC root's subject
    (`C=US, ST=CA, L=Somewhere, O=C2PA Test Root CA, OU=FOR TESTING_ONLY,
    CN=Root CA`) but whose key is RSA-PSS
  - When checked
  - Then `signingCredential.untrusted`: the intermediate's issuer name
    matches the anchor's subject and `signedBy()` is false — the
    explanation says the name matched and the signature did not

- **AC6 — `verify_trust` off: no credential code at all**
  - Given the PNG with `verify-off.settings.json`
  - When verified
  - Then no status has a code starting `signingCredential`,
    `checksPerformed` is `['signature', 'hashedUris', 'dataHash']`
    (`trust` absent — nothing was checked), `state` `Valid`, equal to
    `trusted/png-verify-off.json`; and the same call with no settings at
    all gives the same report

- **AC7 — the settings are whole or absent** *(required: error / malformed input)*
  - Given `full.settings.json` with (a) a PEM block whose base64 is
    truncated, (b) `verify_trust: "yes"`, (c) an extra top-level key
    `trusts`, (d) `trust_anchors` as a list instead of a string, (e) 257
    certificates in `allowed_list` with `maxCertificates` 256, and (f)
    a `trust_anchors` PEM whose block holds a private key
  - When `TrustSettings::fromJson()` runs
  - Then each throws `TrustException` naming the field and the fault;
    no partial settings object exists; (f)'s message does not echo the
    key material

- **AC8 — the second oracle: OpenSSL agrees with the walk**
  - Given the four fixtures, the anchors of `full.settings.json` written
    to a temporary file and each chain's intermediates to another
  - When `openssl_x509_checkpurpose($leaf, X509_PURPOSE_ANY, [$anchors],
    $untrusted)` runs next to `ChainCheck`
  - Then both say trusted for all four; with the RSA root alone both say
    untrusted for the three EC fixtures and trusted for the Adobe file

- **AC9 — the three states are told apart by the rule, on paper and on files**
  - Given `ValidationResult::fromStatuses()` with: one
    `signingCredential.trusted` + `claimSignature.validated`; one
    `claimSignature.validated` + one `signingCredential.untrusted`; the
    same plus `assertion.dataHash.mismatch`; only informational
    statuses; nothing
  - When the state is read
  - Then `Trusted`, `Valid`, `Invalid`, `Invalid`, `Invalid`; and on
    files: `binding/pixel-changed.png` with the full settings is
    `Invalid` with both `signingCredential.trusted` (success) and
    `assertion.dataHash.mismatch` (failure) present — trust does not
    rescue a tampered file

- **AC10 — the codes are verbatim, and the drift alarm grows**
  - Given `StatusCode::cases()` and SPEC-013 AC10's corpus
  - When read and re-run with the full settings
  - Then the enum has exactly twenty-three cases, the two new ones
    character for character, `isSuccess()` true for
    `signingCredential.trusted`; and for the 22 files of the corpus
    verified *with* the full settings, `validation_state` equals the
    oracle's *unsettled* JSON only after the rule of AC9 is applied to
    it — the test spells out the four files where `Valid` becomes
    `Trusted` (the fixtures) and asserts the rest unchanged; the
    `SPEC013_NOT_YET` list loses `signingCredential.untrusted`

## References

- Specification: C2PA 2.4 §14.4.1 (the validator's lists: trust anchors,
  additional anchors, allowed EKUs; "some of these lists can be empty"),
  §14.4.2 / §15.7 (validate the signer: `signingCredential.trusted`,
  `.untrusted`), §15.2.2 (the codes), §15.2.1 (`validation_state`:
  Trusted / Valid / Invalid). Read 2026-09-21 (step 30).
- Oracle: c2patool 0.27.22, `tests/Fixtures/c2patool/trusted/` (nine
  files, step 30) and `tests/Fixtures/c2patool/png.json` (step 14:
  `untrusted` as a failure with `validation_state` `Valid`); c2pa-rs
  `main` `58eac79`: `certificate_trust_policy.rs:186–232` (allowed list
  first, SHA-256 of the DER), `certificate_trust/openssl.rs:22–80`
  (`X509_STRICT | PARTIAL_CHAIN`, anchors as trusted, `x5chain[1..]` as
  untrusted, time = timestamp else now); PHP 8.4 / OpenSSL 3.6.3:
  `openssl_x509_verify` 1 / 1 / −1 on the three links (step 30);
  `openssl_x509_checkpurpose` as the second oracle (ADR-0003).
- Reasoned: the walk's termination rule (DER-equal to an anchor, or
  signed by one) as the reading of `PARTIAL_CHAIN`; the subject/issuer
  comparison as whole arrays (OpenSSL's own order and normalisation);
  "no anchors configured" as `untrusted` rather than an error (c2pa-rs:
  `CertificateNotTrusted`); the `Valid`-with-`untrusted` rule from the
  measured JSON, not from the spec text.
- Divergence, none intended: on every settings variant of step 30 the
  state and the credential code must equal c2patool's. Where SPEC-015
  is not yet in, a chain of unsuitable certificates reaching an anchor
  is `Trusted` here and would be `invalid` there — stated in Scope, and
  closed by SPEC-015 before M5 is called done.

## API sketch

```php
// namespace Provemark\C2paVerifier\Trust;

final readonly class TrustSettings
{
    public const DEFAULT_MAX_CERTIFICATES = 256;

    /** @param list<Certificate> $trustAnchors  @param list<Certificate> $allowedList  @param list<string> $trustConfig  EKU OIDs */
    public function __construct(
        public array $trustAnchors,
        public array $allowedList,
        public array $trustConfig,
        public bool $verifyTrust = true,
    ) {}

    public static function fromJson(string $json, int $maxCertificates = self::DEFAULT_MAX_CERTIFICATES): self;
    /** @param array<string, mixed> $settings */
    public static function fromArray(array $settings, int $maxCertificates = self::DEFAULT_MAX_CERTIFICATES): self;
    /** @return list<Certificate> */
    public static function certificatesFromPem(string $pem, string $what, int $max): array;
    /** @return list<string> the OIDs, comments (//) and blank lines dropped */
    public static function ekusFromConfig(string $config): array;
}

final readonly class Certificate
{
    public function __construct(public string $der) {}   // parses; TrustException if OpenSSL refuses
    public string $sha256;              // raw 32 bytes
    /** @var array<string, mixed> */ public array $subject;
    /** @var array<string, mixed> */ public array $issuer;
    public bool $isCa;
    public function signedBy(self $issuer): bool;         // openssl_x509_verify(...) === 1
    public function sameAs(self $other): bool;            // DER equality
    public function subjectCn(): string;
}

final class TrustException extends \RuntimeException {}

final readonly class ChainCheck
{
    /** @return list<ValidationStatus> */
    public function check(Manifest $manifest, TrustSettings $settings): array;
}

// namespace Provemark\C2paVerifier\Report;
enum ValidationState: string { case Trusted = 'Trusted'; case Valid = 'Valid'; case Invalid = 'Invalid'; }
enum StatusCode: string { /* … */ case SigningCredentialTrusted = 'signingCredential.trusted'; case SigningCredentialUntrusted = 'signingCredential.untrusted'; }

// namespace Provemark\C2paVerifier\Verifier;   (SPEC-013 amendment 3)
final readonly class Verifier
{
    public function verify($stream, ?TrustSettings $trust = null): VerificationReport;
}
```

Deptrac: `Trust` → `Manifest`, `Report` (already), plus `Cose`, `Support`.

## Open questions

- Non-blocker: the variants to make in the tests-first step —
  `binding/x5chain-leaf-only.png` (AC4; the protected header rewritten
  with a one-element chain, every enclosing length adjusted, the
  signature therefore broken as AC4 states) and two settings variants
  (`intermediate-anchor.settings.json` for AC4;
  `allowed-plus-wrong-root.settings.json` for AC3), written under
  `tests/Fixtures/trust/` and run through c2patool; a verdict that
  contradicts a criterion amends it before the tests.
- Non-blocker: whether `Certificate` should live in `Trust` or in a
  new `X509` layer that SPEC-015 and M6 (the TSA certificate) share.
  `Trust` now; a move is a Deptrac line when M6 needs it.
- Non-blocker: `subject`/`issuer` compared as whole arrays — if a real
  chain shows OpenSSL rendering the same DN differently on two
  certificates, the comparison moves to the DER of the Name; measured
  then.

## Amendments

1. **2026-09-21, step 35, found by SPEC-015 AC9** *(confirmed by Maurice van Loon, 2026-09-22)* — without settings the trust check runs with no anchors and no allowed list and says `signingCredential.untrusted` ("no trust anchors configured"), as c2patool does on every file without a trust file (`png.json` of step 14, `good-no-settings.json` of step 34a); only `verify_trust: false` keeps it silent. AC6's "the same call with no settings at all gives the same report" was wrong against the oracle and now reads: no settings → `untrusted` and `trust` in `checksPerformed`; `verify-off` → nothing. `checksPerformed` also carries SPEC-015's `certificate` before `trust` (AC1). The exact enum count in AC10's test is left to SPEC-015 AC10.
2. **2026-09-22, step 42b, with SPEC-017** — `ChainCheck::checkCertificates(array $chain, TrustSettings $settings, string $url)`: the allowed list and the walk on a list of certificates, leaf first, without a `Manifest` — the seam the timestamp check uses for a TSA's certificates ordered from the token. `check()` calls it after reading `x5chain`; no outcome changed.
3. **2026-09-24, step 111b, with SPEC-031** — two criteria change with it.
   - **AC3** (the allowed list trusts without a chain) keeps its point, and
     its files change shape. `allowed-only` and `allowed-plus-wrong-root`
     are refused now (SPEC-031 AC4, the maintainer's decision). The test
     moves their certificates into one `"manifest"` entry
     (`spec014AllowedInEntry()`) and asserts the same outcome:
     `signingCredential.trusted` on "allowed list", tried before the walk.
     The recorded `c2patool` 0.27.22 JSON for those two files stays the
     oracle for the certificates.
   - **AC7** (the settings are whole or absent) grows. `trust.anchors` is
     a known key, and a top-level `trust.allowed_list` is refused with a
     message naming `trust.anchors[].allowed_list`.

   **Weight A: a settings file that gave `Trusted` yesterday now exits 2.**
   That is deliberate. `c2patool` 0.28.0 ignores such a file silently,
   and the two tools would otherwise disagree about it without a word.

   Confirmed by Maurice van Loon, 2026-09-24 (step 120).

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Trust/ChainCheckTest.php :: AC1: the four fixtures with the full settings: Trusted, and the words are c2patool's / SPEC-014 | src/Trust/ChainCheck.php :: check() (the walk); src/Verifier/Verifier.php :: verify(), check() (`trust`); src/Report/ValidationResult.php :: fromStatuses(), toArray() |
| AC2 | tests/Unit/Trust/ChainCheckTest.php :: AC2: the wrong root: untrusted, and the state is Valid, not Invalid / SPEC-014 | src/Trust/ChainCheck.php :: check() (chain exhausted); src/Report/ValidationResult.php :: fromStatuses() (the Valid-with-untrusted rule) |
| AC3 | tests/Unit/Trust/ChainCheckTest.php :: AC3: the allowed list: trusted without a chain / SPEC-014 | src/Trust/ChainCheck.php :: check() (the allowed list, hash_equals on sha256) |
| AC4 | tests/Unit/Trust/ChainCheckTest.php :: AC4: the walk needs the intermediate the chain carries, and an intermediate may be the anchor / SPEC-014 | src/Trust/ChainCheck.php :: check() (next === null; signedBy an anchor) |
| AC5 | tests/Unit/Trust/ChainCheckTest.php :: AC5: no trust by name / SPEC-014 | src/Trust/Certificate.php :: signedBy(), sameAs(), $subject/$issuer; src/Trust/ChainCheck.php :: anchorWithSubject() |
| AC6 | tests/Unit/Trust/ChainCheckTest.php :: AC6: verify_trust off: no credential code at all / SPEC-014 | src/Trust/ChainCheck.php :: check() (verifyTrust); src/Verifier/Verifier.php :: check() |
| AC7 | tests/Unit/Trust/ChainCheckTest.php :: AC7: the settings are whole or absent / SPEC-014 | src/Trust/TrustSettings.php :: fromJson(), fromArray(), certificatesFromPem(), ekusFromConfig(), section(), text(); src/Trust/TrustException.php; src/Trust/Certificate.php :: __construct() |
| AC8 | tests/Unit/Trust/ChainCheckTest.php :: AC8: the second oracle: OpenSSL agrees with the walk / SPEC-014 | src/Trust/ChainCheck.php :: check() |
| AC9 | tests/Unit/Trust/ChainCheckTest.php :: AC9: the three states are told apart by the rule, on paper and on files / SPEC-014 | src/Report/ValidationResult.php :: fromStatuses(); src/Report/ValidationState.php :: Trusted |
| AC10 | tests/Unit/Trust/ChainCheckTest.php :: AC10: the codes are verbatim, and the drift alarm grows / SPEC-014 | src/Report/StatusCode.php :: SigningCredentialTrusted, SigningCredentialUntrusted, isSuccess(); tests/Pest.php :: SPEC013_CORPUS |
