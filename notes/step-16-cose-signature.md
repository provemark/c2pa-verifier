# Step 16 — Verifying the four signatures by hand, before M3 is specified

*2026-09-21.* M2 ends with `Manifest::claimBytes()` and
`Manifest::signatureBytes()`. Before M3's specs say how a claim signature
is verified, this step verifies the four fixtures' signatures with a
throw-away probe and nothing but `ext-openssl`, breaks them in three ways,
and measures what `web-auth/cose-lib` — the library ADR-0001 named as the
first choice — would add and do. No verifier code, no spec, no change to
`composer.json`.

## What a C2PA signature is (C2PA 2.4 §13.2, read 2026-09-21)

A `COSE_Sign1_Tagged` (tag 18) of four items: the protected header as a
byte string (a CBOR map with `alg` under the integer label 1, and — for
2.x writers — `x5chain` under 33), the unprotected header (a map), the
payload (**always `nil`**: detached), and the signature. What is signed
is not the array but the `Sig_structure` built in memory (§13.2.3,
§13.2.6):

```
["Signature1", body_protected (the protected header's bytes as stored), h'' (external_aad, empty by rule), payload]
```

with `payload` = the contents of the claim's JUMBF content box —
`claimBytes()`. The allowed algorithms (§13.2.1): ES256/384/512,
PS256/384/512, EdDSA (Ed25519 only); the key must fit the algorithm, and
implementations "shall refuse to verify" otherwise.

## Measured: the four fixtures

The COSE_Sign1 of each, decoded with SPEC-006/007:

| | alg | x5chain | certs | signature | leaf key |
|---|---|---|---|---|---|
| JPEG, PNG, WebP | −7 (ES256) | protected, label 33 | 2 (651 + 622 B) | 64 B (R‖S) | EC P-256, CN `C2PA Signer`, EKU E-mail Protection |
| Adobe 2022 | −37 (PS256) | **unprotected**, label `"x5chain"` (string, deprecated) | 3 | 512 B | RSA 4096, SPKI algorithm **`rsassaPss`** (SHA-256, MGF1-SHA256, salt 32) |

The probe built the `Sig_structure` with a ten-line CBOR encoder (four
definite items) and verified:

- **ES256**, all three: the 64-byte R‖S converted to DER
  (`SEQUENCE { INTEGER r, INTEGER s }`, a leading zero when the high bit
  is set), then `openssl_verify($sigStructure, $der, $leafPublicKey,
  OPENSSL_ALGO_SHA256)` → **1, valid**. The PNG's `Sig_structure` is
  1,895 bytes and begins `84 6a 5369676e617475726531 59 0505 …`.
- **PS256**, Adobe: `openssl_verify($sigStructure, $signature, $key,
  OPENSSL_ALGO_SHA256)` → **1, valid** — a surprise, because PHP's
  `openssl_verify` has no padding option and is documented as PKCS#1 v1.5.
  The explanation, measured: the certificate's key is of type
  `rsassaPss`, and OpenSSL's `EVP_DigestVerify` applies PSS with the
  key's own parameters for such keys (`openssl_pkey_get_details` reports
  type −1 and no `rsa` block; `openssl_public_decrypt` with
  `OPENSSL_NO_PADDING` fails with "operation not supported for this
  keytype").
- **Negative**: one byte of the claim flipped → invalid, for all four;
  the PNG's signature against the JPEG's claim → invalid.

## Measured: PSS with a plain RSA key (throw-away key, deleted)

The next writer may put a PS256 signature under an ordinary
`rsaEncryption` key. With a 2048-bit key generated in the scratch
directory and deleted afterwards, and OpenSSL's own `dgst -sigopt
rsa_padding_mode:pss -sigopt rsa_pss_saltlen:32`:

| signature | `openssl_verify` (v1.5) | manual EMSA-PSS-VERIFY (RFC 8017 §9.1.2) |
|---|---|---|
| PSS | invalid | **valid**; flipped message → invalid |
| PKCS#1 v1.5 | valid | invalid |

So `openssl_verify` on a plain key does v1.5 only. A PS256 verifier in
pure PHP therefore needs two paths, chosen by the key's algorithm
identifier: `rsassaPss` key → `openssl_verify` (OpenSSL enforces PSS and
refuses v1.5 for that key type); plain RSA key → `openssl_public_decrypt`
with `OPENSSL_NO_PADDING` to recover the encoded message, then
EMSA-PSS-VERIFY (hash, MGF1, salt length = hash length per RFC 8230 §2)
— forty lines, validated here against OpenSSL's own PSS output. And it
must **never** call `openssl_verify` (v1.5) for `alg −37` on a plain key:
that would accept a v1.5 signature c2patool rejects — the wrong
direction.

## Measured: `web-auth/cose-lib` 4.8.2, in the scratch directory only

- Brings `spomky-labs/pki-framework` 1.6.3 and `brick/math` 1.0.0: three
  packages, 2.5 MB of `vendor/`.
- ES256: verifies the PNG's signature (valid; flipped → invalid) — and its
  `ECDSA::verify()` is the same `openssl_verify` call as the probe's,
  after the same R‖S → DER conversion (read in
  `src/Algorithm/Signature/ECDSA/ECDSA.php`).
- PS256: implemented as a raw RSA exponentiation in `brick/math` plus
  EMSA-PSS (the same algorithm as the probe's forty lines) — independent
  of OpenSSL's key type, so it *would* handle both key kinds…
- …but **it cannot load the Adobe certificate**:
  `PublicKeyLoader::fromCertificate()` → "Unable to read the certificate".
  `pki-framework` does not accept an `rsassaPss` SubjectPublicKeyInfo.
  The one PS256 fixture we have is verified by `ext-openssl` and refused
  by the library.
- It also checks that the key fits the algorithm (`KeyRestrictionAware`),
  which §13.2.1 requires; the probe did not.

## Measured: three broken signatures through c2patool 0.27.22

`bin/make-cose-variants.php`, one byte each: a claim byte
(`claim-title-changed`), a signature bit (`signature-changed`), the `alg`
−7 → −8 with the P-256 key left in place (`alg-eddsa-with-ec-key`). All
three: `Invalid`, `claimSignature.mismatch`. c2patool has no separate
code for a key that does not fit the algorithm; it reports a mismatch.
Table in `tests/Fixtures/cose/README.md`.

## What this settles for M3 (proposed)

1. **ADR-0001's "cose-lib first" did not survive its falsification
   attempt.** For ES256 the library is the same OpenSSL call; for the
   only PS256 fixture it fails where `ext-openssl` succeeds; it costs
   three packages on hosts that want none. The reason the ADR gave —
   "COSE detail mistakes … are exactly the mistakes that produce a wrong
   `Valid`" — is real, and this step answers it differently: the
   `Sig_structure` and the R‖S → DER conversion are now measured against
   four real signatures and three broken ones, and cose-lib's own source
   is the second implementation to compare against. Proposal: **amend
   ADR-0001 — COSE verification written here, on `ext-openssl`**, with
   cose-lib's ECDSA and PSS code as reference reading (MIT), never copied
   without saying so. The maintainer's decision.
2. **Three specs**: SPEC-008 the COSE_Sign1 structure and headers (tag
   18, the four items, `alg` under 1 in the protected header, `x5chain`
   under 33 or `"x5chain"` in either bucket with 33 winning, the detached
   payload, the `Sig_structure` — with the one CBOR encoding the verifier
   needs, tested against the 1,895-byte vector above); SPEC-009 the
   verification per algorithm (ES256/384/512 via DER + `openssl_verify`
   with the key-fits-algorithm check; PS256/384/512 via the two paths
   above; EdDSA via `sodium` when present, else an error — never a silent
   skip; everything else `signingCredential`/`algorithm.unsupported`);
   SPEC-010 the first of the `Report` layer: `claimSignature.validated` /
   `claimSignature.mismatch` and the mapping of SPEC-007's JSON error onto
   `assertion.json.invalid` (pending since step 14).
3. **x5chain in the unprotected header**: accepted, as c2patool accepts
   the 2022 corpus — RFC 9360's "MUST be integrity protected" is met
   differently there (the chain is what the signature is checked
   *against*; a swapped chain fails the signature). To be written into
   SPEC-008 with this reasoning, for the maintainer to confirm.

## Reasoned, not measured

- ES384/ES512, PS384/PS512 and Ed25519: no fixture. The `public-testfiles`
  set and c2patool's `--signer` options can produce them when SPEC-009
  needs its vectors.
- That OpenSSL checks the `rsassaPss` key's parameters (hash, MGF, salt)
  against what `openssl_verify` is asked for: from OpenSSL's design, not
  exercised with a mismatching parameter set.
