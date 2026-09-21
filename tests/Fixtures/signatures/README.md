# Signature vectors for SPEC-009

One JSON file per vector: `alg`, the protected header (`protected_hex`, `{1: alg,
33: [leaf]}`), the claim bytes (`claim_hex`: the PNG fixture's real 591-byte
claim, SHA-256 in `claim_sha256`), the signature (`signature_hex`, R‖S for
ECDSA), the leaf certificate (`leaf_pem`, self-signed, public) and `expect`:
what `SignatureVerifier::verify()` must return — `true`, `false`, or
`exception` (cannot verify: the key does not fit the algorithm, or the
algorithm is unsupported).

Made by `bin/make-signature-vectors.php` on 2026-09-21 with OpenSSL 3.6.3:
throw-away keys in a temporary directory, a self-signed certificate each, the
`Sig_structure` built by `CoseSign1::sigStructure()`, signed with `openssl
dgst -sign` (PSS with `-sigopt rsa_padding_mode:pss -sigopt
rsa_pss_saltlen:digest`) or `openssl pkeyutl -sign -rawin` (Ed25519), every
signature checked with OpenSSL's own `-verify` before it was recorded, and
the keys deleted (`keys deleted: yes` in the script's output). Keys are
random, so a re-run produces different files; the committed ones are the
data. `chain-reversed.json` is not synthetic: it is the PNG fixture's real
signature with its chain reversed. Each vector was checked a second time in
PHP with the step-16 verification paths (`notes/step-19-signature-vectors.md`).

| vector | alg | key | expect | why |
|---|---|---|---|---|
| `alg-unsupported` | -65535 | EC P-256 | `exception` | alg -65535 is not in C2PA 2.4 §13.2.1 |
| `chain-reversed` | -7 | EC P-256 (the intermediate CA's) | `false` | the PNG fixture's real signature with its x5chain reversed: the leaf must be read from chain[0], which is now the intermediate, whose key does not verify |
| `eddsa-ed25519` | -8 | Ed25519 | `true` | EdDSA (Ed25519) |
| `eddsa-rsa` | -8 | RSA 2048 | `exception` | alg says EdDSA but the key is RSA: the key does not fit |
| `es256-p256k1` | -7 | EC secp256k1 | `exception` | ES256 under a secp256k1 key: not P-256/384/521, so the key does not fit (§13.2.1) |
| `es256-p384` | -7 | EC P-384 | `true` | ES256 under a P-384 key: allowed by C2PA 2.4 §13.2.1 ("shall accept keys on any of these curves for all ECDSA algorithm choices") |
| `es384-p384` | -35 | EC P-384 | `true` | ES384 under a P-384 key |
| `es512-p521` | -36 | EC P-521 | `true` | ES512 under a P-521 key |
| `ps256-rsa1024` | -37 | RSA 1024 | `exception` | a valid PSS signature under a 1024-bit key: below the 2048-bit minimum of §13.2.1, so the key does not fit |
| `ps256-rsa2048-v15` | -37 | RSA 2048 | `false` | alg says PS256 but the signature is PKCS#1 v1.5: what a verifier that calls openssl_verify would wrongly accept |
| `ps256-rsa2048` | -37 | RSA 2048 | `true` | PS256 under a plain RSA key: the EMSA-PSS path |
| `ps384-rsa3072` | -38 | RSA 3072 | `true` | PS384 under a plain RSA key |
| `ps384-under-rsapss-sha256-key` | -38 | RSA-PSS 2048 (params: SHA-256, salt 32) | `false` | the key's PSS parameters say SHA-256, the claim says PS384: does OpenSSL refuse? (measured in step 19) |
| `ps512-rsa4096` | -39 | RSA 4096 | `true` | PS512 under a plain RSA key |

SHA-256 of the files as committed:

```
8ccebf6dd0d58390738879ccbb95d21ba6f131ca22dd3f89058fb1fe83ce2408  alg-unsupported.json
69f3cdcb971f4dec4ca42ba52c987088565f8194d205f6dff7c1a83740c73bc1  chain-reversed.json
8f61271dc3a61effdbc154f1429bc221f51a035d80d5fceb58fd90bbe75ff170  eddsa-ed25519.json
0a3a07ea3e65b8d0e8e562edc4dbed60d91c044a613fd17f874d1e8e974a5ffc  eddsa-rsa.json
f7b05f20269dce28b945392c28888d7b4f9a3aa35112d2c521da5f7f5d38791e  es256-p256k1.json
499c609fe5e0b2d80ac31f6fb0cd5bfd4a9fcbb1028a37473c4a4cf46847842f  es256-p384.json
fd3aef77d5c489a11bdc6763e88b917d7c43183010ac0ccdcd62808343ec165e  es384-p384.json
425df2a240432ee8f2f58bbb7204ba602bb030094601fe3c8c8fcf0793778225  es512-p521.json
71029e3a2dc45a15481a16aea9f6c9621d4b06d3fa7f8f279237f9d5e2aab4a7  ps256-rsa1024.json
b92bd33c7973c1e1bda615f95420b5d7c4aa6c4aac7116af787934f0a1e0f22f  ps256-rsa2048-v15.json
d9f86b3fc694774bcfba7c429b389c7f0d3d05377460d2444ff03a4aa8d0dba2  ps256-rsa2048.json
5c17bc4c3a1bdb6572809c580eddba090e2776abdde9620ac8c7aeb54af02927  ps384-rsa3072.json
f62448e3115b6aeef1d7b43df0da2fa4e4f1db46bedd131c822dea5b2038917f  ps384-under-rsapss-sha256-key.json
d946e4aee65696a773fae58b607b008a2bbae3292190564809f362af50dd0145  ps512-rsa4096.json
```
