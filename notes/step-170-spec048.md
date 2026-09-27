# Step 170 — SPEC-048: no SHA-1 or MD5 signature in the certificate path

*2026-09-27. SPEC-048 approved by Maurice van Loon this day.*

## 170a — the probes and the tests seen red

`bin/make-chain-constraint-variants.php` gained three files. Each sits
under the RSA-1024 intermediate of step 168, because MD5 and PSS need an
RSA issuer:

- `md5-intermediate`: a second intermediate signed with
  `md5WithRSAEncryption`;
- `pss-sha1-intermediate`: a second intermediate signed with RSASSA-PSS
  over SHA-1. OpenSSL writes no hash parameter, the RFC 4055 default,
  shown as *"sha1 (default)"*;
- `pss-sha1-leaf`: a leaf that the RSA-1024 intermediate signs that way.

The script now handles chains of two intermediates, for signing and for
`openssl verify`.

| file | `openssl verify` | c2patool 0.27.22 and 0.28.0 | this verifier before | after |
|---|---|---|---|---|
| `sha1-intermediate` | OK | `Trusted` | `Trusted` | `Valid`, `untrusted` |
| `md5-intermediate` | OK | `Trusted` | `Trusted` | `Valid`, `untrusted` |
| `pss-sha1-intermediate` | OK | `Trusted` | `Trusted` | `Valid`, `untrusted` |
| `pss-sha1-leaf` | OK | `Trusted` | `Trusted` | `Invalid`, `invalid` |
| `rsa1024-intermediate` | OK | `Trusted` | `Trusted` | `Trusted` |

That `c2patool` also accepts the PSS-SHA-1 leaf is amendment 1.

`vendor/bin/pest --group=SPEC-048` before the change: 3 failed, 2 passed.
AC1–AC3 each failed on `'Trusted'` where the refusal was expected. The
guards AC4 and AC5 passed. AC5 is `matrix/ps256.jpg`, a real PSS leaf
over SHA-256.

## 170b — the change

- **`CertificateExtensions`** reads the certificate's outer
  signatureAlgorithm, and for RSASSA-PSS the hash inside its parameters
  (SHA-1 when absent). `weakHash()` names an algorithm that rests on MD2,
  MD4, MD5 or SHA-1.
- **`ChainCheck::pathFault()`** refuses such a signature on any
  certificate strictly between the anchor and the leaf, with
  `signingCredential.untrusted`.
- **`CertificateProfileCheck`** rule 4 refuses an `rsassaPss` leaf whose
  hash is weak, with `signingCredential.invalid`.

## Measured after

- `vendor/bin/pest --group=SPEC-048`: 5 passed. `composer check`: exit 0,
  552 passed.
- **22,880 runs over every media fixture and settings file, before
  (step 169) and after.** Only the four new refusals moved. No chain in
  the corpus, TSA chains included, has a certificate signed over MD5 or
  SHA-1 between anchor and leaf.

**Weight B:** stricter than every oracle by the maintainer's decision.
Nothing was a wrong verdict by SECURITY.md's definition.

## Disclosure

Local, with steps 164–169, until 0.2.5.
