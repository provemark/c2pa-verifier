# Step 20 — SignatureVerifier (SPEC-009 implemented): the first cryptography in `src/`

*2026-09-21. Oracle: c2patool 0.27.22 on the fixtures and the `cose/`
variants; the fourteen vectors of step 19.*

## What was built

Five files in `src/Cose/`, nothing outside `ext-openssl` and, opt-in,
`ext-sodium`:

- `SignatureVerifier::verify(CoseSign1, claimBytes): bool` — the
  algorithm looked up (seven identifiers; anything else is a
  `CoseException`); the leaf's key read and classified; **the key checked
  against the algorithm before any arithmetic** (C2PA 2.4 §13.2.1: EC on
  P-256/384/521 for ES*, RSA of 2048–16384 bits for PS*, Ed25519 for
  EdDSA — a refusal names the key and the requirement); then one of three
  paths over `sigStructure(claimBytes)`.
- `EcdsaSignature::toDer()` — `R‖S` of exactly 2 × the curve's size
  (else `null` → `false`), minimal `INTEGER`s, and the long-form
  `SEQUENCE` length above 127 bytes — the P-521 case step 19 found.
- `PublicKey` — the leaf's SubjectPublicKeyInfo classified by its
  algorithm OID (`rsaEncryption`, `id-RSASSA-PSS`, `id-ecPublicKey` with
  the curve name, `id-Ed25519`), not by PHP's key-type constants, which
  name neither RSA-PSS nor, before 8.4, Ed25519.
- `RsaPss` — for an ordinary RSA key: `openssl_public_decrypt` with no
  padding, then EMSA-PSS-VERIFY with salt length = hash length; the
  `k`-vs-`emLen` step of RFC 8017 §8.1.2 included. For an
  `id-RSASSA-PSS` key the verifier calls `openssl_verify`, which performs
  PSS with the key's own parameters and answers −1 when they do not
  match the hash asked — and −1 is `false`, never `true`.
- `OpenSsl::quiet()` — a scoped error handler around every `openssl_*`
  call and a drain of OpenSSL's error queue after it: a failed operation
  is an answer, not a warning, and never a stale error on a later call.
- Ed25519: `sodium_crypto_sign_verify_detached` over the last 32 bytes of
  the SPKI when the extension is there; else `openssl_verify(…, 0)`;
  else a `CoseException` naming both — never a silent `false`.

## Measured

- Red: 11 tests on the missing classes (`2d32724`).
- First run: **10 passed, 1 failed** — AC10, and the failure was the
  test's: for an `r` with its high bit set and an `s` of zero the DER
  body is 2 + 33 + 2 + 1 = 38 bytes (`30 26`), not 37. The converter was
  right.
- PHPStan: two findings (an unnarrowed `curve_name`, `sodium`'s
  `non-empty-string` parameter — `rawEd25519()` now guarantees 32 bytes
  or throws). Then `composer check` → exit 0: spec-check `OK: 10 spec(s),
  10 test file(s)`, Pint passed, PHPStan `No errors`, Deptrac 0
  violations, Pest **143 passed (802 assertions)**.
- On this machine (PHP 8.5.8, OpenSSL 3.6.3) AC8's OpenSSL-only Ed25519
  path returns `true`; PHP 8.3 and 8.4 are CI's measurement.

## What M3 now proves

For the first time `src/` says something about *truth*: the three
c2patool-signed fixtures and the 2022 Adobe file verify under their own
leaf keys over exactly the bytes SPEC-007 and SPEC-008 hand over; one
claim byte, one signature bit, another manifest's claim, a reversed
chain, a v1.5 signature under a PSS label, a PSS-parameter mismatch —
all `false`; a secp256k1 key, a 1024-bit key, an RSA key under EdDSA, an
unknown algorithm — all refused before the arithmetic. What it does not
say: whether the leaf is anyone's to trust (M5), whether the signing time
is inside its validity (M6), whether the file's bytes are the ones the
claim binds (M4). A valid signature under a self-signed certificate is
mathematically as valid as any other; SPEC-010 will word that carefully.

## Reasoned, not measured

- The RSA upper bound of 16,384 bits (§13.2.1 "may refuse"): no vector;
  a key that size would take OpenSSL seconds to generate and the check is
  one comparison.
- That `openssl_verify` with digest `0` on a non-Ed25519 key cannot occur
  here: `requireFit()` has already refused any other key kind for EdDSA.
