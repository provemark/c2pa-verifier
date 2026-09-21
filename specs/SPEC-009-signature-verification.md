# SPEC-009: Verifying the claim signature — per algorithm, with the key checked against it

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

SPEC-008 gives the algorithm, the leaf certificate, the signature bytes
and the exact bytes that were signed. This spec is the first
cryptography in `src/`: it answers whether the signature over the
`Sig_structure` verifies under the leaf's public key — `true`, `false`,
or *cannot say* — with nothing but `ext-openssl` (and `ext-sodium` where
present), as ADR-0001 amendment 1 decided.

Three things make this the layer where a mistake is invisible, and each
is a criterion here: the ECDSA signature is `R‖S` and OpenSSL wants DER —
convert it wrong and every signature "fails", or, worse, a lax converter
accepts a malformed one; RSASSA-PSS is not what `openssl_verify` does for
an ordinary RSA key — use it anyway and a PKCS#1 v1.5 signature that
c2patool rejects passes here; and C2PA 2.4 §13.2.1 requires the key to
*fit* the algorithm — skip that and an ES256 claim under a P-521 key, or
a 1024-bit RSA key, is accepted where the text says "shall refuse".
`notes/step-16-cose-signature.md` measured all three on the four
fixtures, a throw-away RSA key and three broken variants.

What this spec does *not* do: decide whether the certificate is
trusted (M5), whether the signing time is inside its validity (M6), or
what status code to emit (SPEC-010). It says whether the mathematics
holds.

## Scope

**In scope**

- `SignatureVerifier::verify(CoseSign1 $cose, string $claimBytes): bool`
  — `true` when the signature verifies, `false` when it does not
  (including a signature of the wrong length or shape); `CoseException`
  when it *cannot* be verified: an unsupported `alg`, a key that does not
  fit the algorithm, a leaf whose key cannot be read, an extension that is
  missing. Never `true` by default; every path that is not a positive
  verification ends in `false` or an exception.
- The algorithms of C2PA 2.4 §13.2.1, by their COSE identifiers:
  - **ES256 (−7), ES384 (−35), ES512 (−36)**: the key must be EC on
    P-256, P-384 or P-521 (OpenSSL: `prime256v1`, `secp384r1`, `secp521r1`)
    — any of the three for any of the three, as §13.2.1 says; the
    signature is `R‖S` of 64, 96 or 132 bytes for those curves (each half
    the curve's byte size), converted to DER `SEQUENCE { INTEGER r,
    INTEGER s }` with leading zeros stripped and one added back when the
    high bit is set, **and the long-form length byte when the sequence
    exceeds 127 bytes** (P-521: measured in step 19, the short form makes
    OpenSSL return −1); then `openssl_verify` with SHA-256/384/512.
  - **PS256 (−37), PS384 (−38), PS512 (−39)**: the key must be RSA of at
    least 2048 bits (§13.2.1; keys above 16,384 bits may be refused and
    are). Two paths by the leaf's key algorithm: a key whose
    SubjectPublicKeyInfo is `id-RSASSA-PSS` (`openssl_pkey_get_details`
    type −1) → `openssl_verify` with the hash, which OpenSSL performs as
    PSS for that key type and refuses (−1) when the key's own PSS
    parameters name another hash (measured in step 19 — that −1 is
    "cannot verify", never `true`); an ordinary `rsaEncryption` key →
    `openssl_public_decrypt` with `OPENSSL_NO_PADDING` to recover the
    encoded message, then EMSA-PSS-VERIFY (RFC 8017 §9.1.2) with MGF1 over
    the same hash and a salt length equal to the hash length (RFC 8230
    §2). `openssl_verify` is **never** called for a PS* algorithm on an
    ordinary RSA key: it would do PKCS#1 v1.5.
  - **EdDSA (−8)**: Ed25519 only; the key must be Ed25519 (a 44-byte
    SPKI, OID 1.3.101.112). Verified with
    `sodium_crypto_sign_verify_detached` over the raw 32-byte key when
    `ext-sodium` is present, else with `openssl_verify(…, 0)` when the
    installed OpenSSL and PHP support it (measured on PHP 8.5: yes),
    else `CoseException` naming the missing extension — never a silent
    `false`.
  - Any other `alg` → `CoseException` naming it.
  - `openssl_verify` returns 1, 0 or −1: only 1 is `true`; 0 is `false`;
    −1 (an OpenSSL error: a key the operation cannot use, a mismatching
    parameter) is `false` too, with the OpenSSL error string in the
    exception when the key was already accepted as fitting — never
    silently `true`.
- Every path is exercised by a recorded vector: the protected bytes, the
  claim bytes, the signature and the leaf certificate (public), committed
  as data; the private keys that produced the synthetic ones never enter
  the repository.

**Out of scope** (each needs its own spec before it may be built)

- Chain building, trust anchors, EKU (M5); certificate validity against
  the signing time (M6); status codes (SPEC-010).
- Certificates that carry a key but are otherwise malformed or expired:
  only the public key is read here; the rest is M5's.
- Deterministic ECDSA, signature malleability (a high-`s` value): OpenSSL
  accepts either form; not distinguished here.
- Counter-signatures, `COSE_Sign` with several signers.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-009')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The four fixtures reach this layer through SPEC-001/2/3 → 005 → 007 →
008; the synthetic vectors live under `tests/Fixtures/signatures/` (Open
questions).

- **AC1 — the four fixtures verify** *(oracle: `c2patool` →
  `claimSignature.validated` on each; step 16 verified the same bytes with
  `ext-openssl` by hand)*
  - Given each fixture's `CoseSign1` and `claimBytes()`
  - When `verify()` runs
  - Then it returns `true` for the JPEG, PNG and WebP (ES256, P-256) and
    for the Adobe file (PS256 under an `id-RSASSA-PSS` key of 4096 bits)

- **AC2 — one changed byte of the claim is a mismatch** *(oracle:
  `c2patool` → `claimSignature.mismatch`)*
  - Given `tests/Fixtures/cose/claim-title-changed.bin` (one byte of the
    title)
  - When verified
  - Then it returns `false`

- **AC3 — one changed bit of the signature is a mismatch** *(oracle:
  `claimSignature.mismatch`)*
  - Given `tests/Fixtures/cose/signature-changed.bin`
  - When verified
  - Then it returns `false`

- **AC4 — a claim from another manifest is a mismatch**
  - Given the PNG's `CoseSign1` and the JPEG's `claimBytes()`
  - When verified
  - Then it returns `false`

- **AC5 — a key that does not fit the algorithm cannot be verified**
  *(required: error / malformed input; oracle: `c2patool` reports
  `claimSignature.mismatch` for the first, no separate code)*
  - Given `tests/Fixtures/cose/alg-eddsa-with-ec-key.bin` (alg −8, the key
    P-256); and the synthetic vectors `es256-p256k1` (alg −7, a key on
    `secp256k1`), `ps256-rsa1024` (alg −37, a 1024-bit key), `eddsa-rsa`
    (alg −8, an RSA key)
  - When verified
  - Then each throws `CoseException` naming the algorithm and the key
    (kind, curve or size), citing §13.2.1

- **AC6 — ES384 and ES512 verify, and their curves cross**
  - Given the synthetic vectors `es384-p384`, `es512-p521`, and
    `es256-p384` (ES256 under a P-384 key, allowed by §13.2.1), each with
    a positive signature and one with a flipped claim byte
  - When verified
  - Then the positives return `true` and the flipped `false`; and
    `es384-p384` with its 96-byte signature cut to 95 bytes returns
    `false`

- **AC7 — PS256 under an ordinary RSA key verifies through EMSA-PSS, and PKCS#1 v1.5 does not pass**
  - Given the synthetic vectors `ps256-rsa2048` (a PSS signature, salt 32,
    made by OpenSSL), `ps256-rsa2048-v15` (a second key, the same bytes
    signed PKCS#1 v1.5 under `alg` −37 — what a verifier that calls
    `openssl_verify` on a plain key wrongly accepts: measured, it returns
    1), `ps384-rsa3072`, `ps512-rsa4096`, and
    `ps384-under-rsapss-sha256-key` (an `id-RSASSA-PSS` key whose
    parameters say SHA-256, signed SHA-256, presented as PS384)
  - When verified
  - Then `ps256-rsa2048`, `ps384-rsa3072` and `ps512-rsa4096` return
    `true` and their flipped-claim variants `false`; `ps256-rsa2048-v15`
    returns `false`; and `ps384-under-rsapss-sha256-key` returns `false`
    (OpenSSL refuses the parameter mismatch with −1)

- **AC8 — EdDSA verifies, through sodium or OpenSSL**
  - Given the synthetic vector `eddsa-ed25519` (a positive signature and
    a flipped-claim variant)
  - When verified on a PHP with `ext-sodium`, and on one without it where
    `openssl_verify(…, 0)` supports Ed25519
  - Then `true` and `false` respectively; and when neither is available
    the verifier throws `CoseException` naming both extensions (tested by
    constructing the verifier with both paths disabled)

- **AC9 — an unsupported algorithm cannot be verified**
  - Given the PNG's `CoseSign1` with `alg` −65535 (a synthetic vector with
    the protected header re-encoded)
  - When verified
  - Then it throws `CoseException` naming −65535 as unsupported

- **AC10 — the R‖S to DER conversion is exact** *(oracle: RFC 3279 §2.2.3;
  the fixtures' signatures, which verify only if it is)*
  - Given the PNG's 64-byte signature, and the synthetic pairs `r` and `s`
    with a leading zero byte, with the high bit set, and equal to zero
  - When converted
  - Then the PNG's DER begins `30 4? 02 20` or `30 4? 02 21 00` according
    to its high bit, `strlen` matches, and the three synthetic pairs give
    `02 01 00`, `02 21 00 …` and `02 01 00` for the integer in question;
    the P-521 vector's 132-byte signature converts to a sequence with the
    long-form length (`30 81 8?`) — the short form was measured to make
    OpenSSL return −1; and a 63-byte input is not converted but reported
    (`false` from `verify()`)

- **AC11 — the leaf's key is read from the certificate, not from the chain's order**
  - Given the PNG's `CoseSign1` with its chain reversed (a synthetic
    vector: the intermediate first)
  - When verified
  - Then it returns `false` — the intermediate's key does not verify the
    signature — and does not throw

## References

- Specification: C2PA 2.4 §13.2.1 (the algorithm list and the
  key-fits-algorithm rule, citing RFC 8152 §8.1/8.2 and RFC 8230 §2/§4),
  §13.2.3 and §13.2.6 (what is verified: the `Sig_structure`); RFC 8152
  §8.1 (ECDSA in COSE: `R‖S`, the curve sizes), §8.2 (EdDSA); RFC 8230
  §2 (RSASSA-PSS in COSE: salt length = hash length, MGF1 with the same
  hash); RFC 8017 §9.1.2 (EMSA-PSS-VERIFY); RFC 3279 §2.2.3 (the DER
  form of an ECDSA signature). Read 2026-09-21.
- Oracle: `c2patool 0.27.22` — `claimSignature.validated` on the four
  fixtures, `claimSignature.mismatch` on the three `tests/Fixtures/cose/`
  variants of step 16; the hand verification of step 16 with
  `ext-openssl`, including the plain-RSA PSS/v1.5 pair; `web-auth/cose-lib`
  4.8.2's `ECDSA.php` and `PSSRSA.php` as reference reading (MIT) for the
  DER conversion and EMSA-PSS; the fourteen vectors of
  `tests/Fixtures/signatures/` (step 19, 2026-09-21: OpenSSL 3.6.3 CLI,
  each self-verified by OpenSSL and re-verified in PHP through the
  step-16 paths).
- Reasoned: refusing RSA keys above 16,384 bits (§13.2.1 "may"); reading
  the raw Ed25519 key as the last 32 bytes of a 44-byte SPKI (RFC 8410).
  Measured in step 19: OpenSSL does enforce an `id-RSASSA-PSS` key's
  parameters (−1 on a mismatching hash).

## API sketch

```php
// namespace Provemark\C2paVerifier\Cose;

declare(strict_types=1);

final readonly class SignatureVerifier
{
    public const ES256 = -7;  public const ES384 = -35; public const ES512 = -36;
    public const PS256 = -37; public const PS384 = -38; public const PS512 = -39;
    public const EDDSA = -8;

    public function __construct(
        public bool $useSodium = true,     // both true: sodium first, then OpenSSL; tests flip them
        public bool $useOpensslEd25519 = true,
    ) {}

    /**
     * @return bool true: verifies; false: does not (mismatch, wrong length)
     *
     * @throws CoseException unsupported alg, key does not fit, key unreadable, extension missing
     */
    public function verify(CoseSign1 $cose, string $claimBytes): bool;
}

/** Internal helpers, each with its own vectors: */
// EcdsaSignature::toDer(string $rs, int $curveBytes): ?string   — null when the length is wrong
// RsaPss::verify(string $message, string $signature, \OpenSSLAsymmetricKey $key, string $hash): bool
// PublicKey::fromDer(string $leafDer): PublicKey  { kind: 'ec'|'rsa'|'rsa-pss'|'ed25519', curve, bits, resource, rawEd25519 }
```

`Cose` sees `Cbor` and `Support`; the leaf DER comes from
`CoseSign1::$chain[0]`, the message from `sigStructure()`. Nothing here
holds a private key or signs.

## Open questions

- Resolved before approval (step 19, 2026-09-21): fourteen vectors under
  `tests/Fixtures/signatures/`, made by `bin/make-signature-vectors.php`
  (keys deleted), each self-verified by OpenSSL and re-verified in PHP;
  the P-521 long-form DER length and the PSS-parameter refusal were found
  there and are in the criteria.
- Resolved at implementation: `bool`; SPEC-010 assembles its report from
  `CoseSign1` (alg, chain) and the result.
- Added at implementation: `src/Cose/OpenSsl.php`, a scoped error handler
  plus a drain of OpenSSL's error queue around every `openssl_*` call, so
  a failure is an answer and never a warning or a stale error on a later
  call; and `PublicKey`, which classifies the leaf's key by the SPKI
  algorithm OID rather than by PHP's key-type constants (which do not name
  RSA-PSS, nor Ed25519 before PHP 8.4).

## Amendments

1. **2026-09-21, measured on CI** — AC8's "on one without it where
   `openssl_verify(…, 0)` supports Ed25519" now has its boundary: PHP 8.4
   and 8.5 do; PHP 8.3 does not (`openssl_verify` with digest 0 fails
   there and the verifier throws the named `CoseException`). The test
   encodes exactly that: `true` on 8.4+, the exception on 8.3, anything
   else a failure. `composer.json`'s `suggest` for `ext-sodium` says so.
   No criterion changed; the measurement filled in what the criterion
   left to measurement.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Cose/SignatureVerifierTest.php :: AC1: the four fixtures verify / SPEC-009 | src/Cose/SignatureVerifier.php :: verify(), ecdsa(), rsaPss(); src/Cose/PublicKey.php :: fromCertificateDer() |
| AC2 | tests/Unit/Cose/SignatureVerifierTest.php :: AC2: one changed byte of the claim is a mismatch / SPEC-009 | src/Cose/SignatureVerifier.php :: ecdsa(), opensslVerify() |
| AC3 | tests/Unit/Cose/SignatureVerifierTest.php :: AC3: one changed bit of the signature is a mismatch / SPEC-009 | src/Cose/SignatureVerifier.php :: ecdsa(), opensslVerify() |
| AC4 | tests/Unit/Cose/SignatureVerifierTest.php :: AC4: a claim from another manifest is a mismatch / SPEC-009 | src/Cose/SignatureVerifier.php :: verify() |
| AC5 | tests/Unit/Cose/SignatureVerifierTest.php :: AC5: a key that does not fit the algorithm cannot be verified / SPEC-009 | src/Cose/SignatureVerifier.php :: requireFit(); src/Cose/PublicKey.php :: describe() |
| AC6 | tests/Unit/Cose/SignatureVerifierTest.php :: AC6: ES384 and ES512 verify, and their curves cross / SPEC-009 | src/Cose/SignatureVerifier.php :: ecdsa() (`CURVES`); src/Cose/EcdsaSignature.php :: toDer() |
| AC7 | tests/Unit/Cose/SignatureVerifierTest.php :: AC7: PS256 under an ordinary RSA key verifies through EMSA-PSS, and PKCS#1 v1.5 does not pass / SPEC-009 | src/Cose/SignatureVerifier.php :: rsaPss(); src/Cose/RsaPss.php :: verify(), emsaPssVerify(), mgf1() |
| AC8 | tests/Unit/Cose/SignatureVerifierTest.php :: AC8: EdDSA verifies, through sodium or OpenSSL / SPEC-009 | src/Cose/SignatureVerifier.php :: ed25519(); src/Cose/PublicKey.php :: rawEd25519() |
| AC9 | tests/Unit/Cose/SignatureVerifierTest.php :: AC9: an unsupported algorithm cannot be verified / SPEC-009 | src/Cose/SignatureVerifier.php :: verify() (`NAMES`) |
| AC10 | tests/Unit/Cose/SignatureVerifierTest.php :: AC10: the R||S to DER conversion is exact / SPEC-009 | src/Cose/EcdsaSignature.php :: toDer(), integer(), length() |
| AC11 | tests/Unit/Cose/SignatureVerifierTest.php :: AC11: the leaf key is read from chain[0], so a reversed chain is a mismatch, not an error / SPEC-009 | src/Cose/SignatureVerifier.php :: verify() (`chain[0]`) |
