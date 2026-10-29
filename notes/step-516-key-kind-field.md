# Step 516 — A key's kind is its AlgorithmIdentifier, read as a field

*2026-10-29.* Finding C4 of the security review before 0.10.0, a low
finding. SPEC-009 amendment 7, approved before the test was written.

## The finding

`PublicKey::fromCertificateDer()` told a key's kind apart by searching
the first 32 bytes of its SubjectPublicKeyInfo for the encoded OID of
RSA-PSS, RSA, Ed25519 and EC, in that order. It did not read the field
the algorithm is written in (RFC 5280 §4.1.2.7). For an EC key, the
start of the key's own X coordinate falls inside those 32 bytes: 5 bytes
for P-256, 8 for P-384, 6 for P-521. A key whose X begins with
`06 03 2b 65 70`, Ed25519's OID, was read as Ed25519.

The error fails closed. An EC signature checked as Ed25519 never
verifies, so the outcome is `Invalid` with the wrong reason, never a
wrong `Valid`. By chance it would happen to about one key in 2^38
(P-384) to 2^40 (P-256). A signer could also grind a key of their own
for it, which turns only their own files `Invalid`. Four places read a
key this way: the claim signer, the time-stamp signer, the OCSP
responder and its issuer.

## What was measured

- A valid P-256 point with such an X, put into a certificate by
  `openssl x509 -force_pubkey` and issued by a throw-away CA whose key
  was deleted. It is now `tests/Fixtures/cose/ec-key-ed25519-bytes.der`,
  and its README says how it was made. `openssl x509 -text` names the key
  `id-ecPublicKey`. On `aaf79ca` this verifier read it as `ed25519`.
- AC14 on `aaf79ca` failed on that first assertion (`ed25519`, expected
  `ec`). With that assertion set aside, the eight existing keys it pins
  had the same kinds on `aaf79ca` as after. The pin for Adobe's `C.jpg`
  was first written as `rsa`. The old code said `rsa-pss`, and `openssl
  x509 -text` confirms `rsassaPss`, so the test was corrected to the
  measurement. After the change: green.
- SPEC-009's tests and the rest of `tests/Unit/Cose` (45) pass.
- The corpus before (`aaf79ca`, worktree, `vendor/` copied) and after:
  2,190 runs, none differs.
- `composer check` (1,706 passed, 1 skipped), `vendor/bin/pest
  --parallel` and `bin/package-check.php` pass.

## What was done

The kind is now the OID of the SubjectPublicKeyInfo's AlgorithmIdentifier
(the first element of its first element), read with `DerReader`
(SPEC-016) and compared exactly as a dotted string. An SPKI the reader
refuses is a `CoseException` with `signingCredential.invalid`, as an
unknown kind already was. OpenSSL still reads the key itself.
`asRsaEncryption()`, which writes an rsaEncryption AlgorithmIdentifier,
keeps the encoded OID as its own constant.

The `Cose` layer may now depend on `Asn1` (`deptrac.yaml`). `Asn1`
depends only on `Support`, so the new rule adds no cycle.
