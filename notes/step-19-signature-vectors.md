# Step 19 — Fourteen signature vectors, and two things they found before any code was written

*2026-09-21.* SPEC-009 (verifying the claim signature) was drafted with
the four real fixtures as its only positive vectors — all ES256 or PS256
under one kind of key. Its criteria for ES384/512, PS384/512, EdDSA, the
key-fits-algorithm refusals and the PKCS#1 v1.5 trap needed vectors that
no fixture provides. This step makes them, checks them twice, and — in
checking — finds one bug in the step-16 conversion and answers one open
question. No verifier code; the draft was updated.

## How the vectors are made

`bin/make-signature-vectors.php`: for each vector, a throw-away key in a
temporary directory (`openssl ecparam -genkey`, `genpkey -algorithm RSA`,
`genpkey -algorithm RSA-PSS` with SHA-256 parameters, `genpkey -algorithm
ed25519`), a self-signed certificate (`openssl req -x509`), the PNG
fixture's real 591-byte claim, and a `Sig_structure` built by our own
`CoseSign1::sigStructure()` — so the vectors exercise exactly the bytes
SPEC-008 produces. Signed with `openssl dgst -sign` (PSS: `-sigopt
rsa_padding_mode:pss -sigopt rsa_pss_saltlen:digest`) or `openssl pkeyutl
-sign -rawin` (Ed25519); every signature verified with OpenSSL's own
`-verify` before it is recorded; DER ECDSA signatures converted to `R‖S`;
the keys deleted at the end and the script prints that they are. What
reaches the repository: the public certificate, the protected header,
the claim bytes, the signature, and `expect` — `true`, `false` or
`exception`. `chain-reversed` is the PNG's real signature with its chain
reversed, no key involved.

The script is the one place in the project that runs `openssl` as a
process. It is tooling; the verifier never does.

## The second check, and what it found

Every vector was then verified in PHP through the step-16 paths —
`openssl_verify` after `R‖S → DER` for ECDSA, EMSA-PSS after a raw RSA
operation for plain keys, `openssl_verify` for the `rsassaPss` key,
`sodium` and `openssl_verify(…, 0)` for Ed25519:

| vector | expect | second check |
|---|---|---|
| `es384-p384`, `es256-p384` | true | 1 |
| **`es512-p521`** | true | **−1** — see below |
| `es256-p256k1` | exception | 1: the mathematics holds; only the curve check refuses it |
| `ps256-rsa2048`, `ps384-rsa3072`, `ps512-rsa4096` | true | EMSA-PSS true; `openssl_verify` (v1.5) 0 |
| `ps256-rsa2048-v15` | false | EMSA-PSS false; **`openssl_verify` (v1.5) 1** — the trap is real |
| `ps256-rsa1024` | exception | EMSA-PSS true; only the size check refuses it |
| `ps384-under-rsapss-sha256-key` | false | `openssl_verify(SHA-384)` **−1**; with SHA-256, 1 |
| `eddsa-ed25519` | true | sodium true; `openssl_verify(…, 0)` 1 |
| `eddsa-rsa`, `alg-unsupported` | exception | refused before verifying |
| `chain-reversed` | false | 0 |

**The bug.** The P-521 vector returned −1 — an OpenSSL error, not a
mismatch. Its `R‖S` is 132 bytes; as DER the two `INTEGER`s make a
138-byte `SEQUENCE`, and DER writes a length above 127 in long form
(`81 8a`), which the step-16 converter did not: it wrote `chr(138)` as a
short length, an invalid encoding. On 64- and 96-byte signatures the
sequence stays under 128 bytes and the bug is invisible. With the
long form the vector verifies (1) and a flipped claim byte fails (0).
SPEC-009 AC10 now names the case. This is exactly the class of mistake
the spec's Problem section describes, found by the first vector that
could find it.

**The open question.** An `id-RSASSA-PSS` key carries its own PSS
parameters. Asked to verify as PS384 a signature made under a key whose
parameters say SHA-256, OpenSSL returns −1 — a refusal, as an error,
not 0. So `openssl_verify`'s three outcomes must be kept apart: 1 is
`true`, 0 is `false`, −1 is "cannot" and never `true`. Written into the
spec's Scope.

**Two confirmations.** A PKCS#1 v1.5 signature under `alg −37` passes
`openssl_verify` on a plain key (1) and fails EMSA-PSS: the two-path
design of step 16 is not optional. And `secp256k1` and RSA-1024
signatures verify mathematically; only the key-fits-algorithm check of
§13.2.1 keeps them out — which is why that check must come *before* the
arithmetic, not after.

## What this settles

SPEC-009's eleven criteria have their vectors; AC7 gained the
`rsassaPss`-parameter case, AC10 the long-form length. The blocking open
question is resolved.

## Measured, in numbers

14 JSON files under `tests/Fixtures/signatures/` with a README (their
SHA-256s recorded; a re-run makes different files, keys being random);
14 OpenSSL self-verifications in the script; 14 PHP re-verifications;
the script under PHPStan level max (eleven findings from a closure's
docblock PHPStan does not read — a named function instead) and Pint;
`composer check` exit 0 (132 passed, no test uses the vectors yet).

## Reasoned, not measured

- That `-sigopt rsa_pss_saltlen:digest` is what RFC 8230 §2 requires
  (salt length = hash length): from the RFC text; the EMSA-PSS check with
  `sLen = hLen` verifying the OpenSSL output is the measurement that
  agrees with it.
- That `openssl_verify(…, 0)` for Ed25519 works on PHP 8.3 and 8.4 as it
  does on 8.5: CI will say when the tests run.
