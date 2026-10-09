# Step 304 — The leaf's RSASSA-PSS parameters, measured

*2026-10-09. Measurement only; no change to `src/` or to the generators.*

Step 303's fuzzing found two files where a flip in the RSASSA-PSS
parameters of the algorithm that signed the leaf left the file `Valid`
here and `Invalid` in `c2patool`. This step measures the rule with
certificates issued that way on purpose.

## Probes

`bin/make-trust-matrix.php` was given nine probes, run in its scratch
mode. In each, the intermediate is RSA-2048 and signs the leaf with
`openssl x509 -sigopt rsa_padding_mode:pss`, with the digest, MGF1 digest
and salt length named. Two probes then change the last byte of the outer
`signatureAlgorithm`'s MGF1 hash OID, which no signature covers. The change
to the generator is not committed in this step (SPEC-061 AC6); it comes
with the fix and its fixtures.

| probe (PSS hash / MGF1 hash / salt) | `c2patool` 0.27.22 | 0.28.1 | `openssl verify` | this verifier |
|---|---|---|---|---|
| control (SHA-256 / SHA-256 / 32) | `Trusted` | `Trusted` | OK | `Trusted` |
| SHA-384 / SHA-384 / 48 | `Trusted` | `Trusted` | OK | `Trusted` |
| **SHA-224** / SHA-224 / 28 | `Invalid` | `Invalid` ("certificate hash algorithm not supported") | OK | **`Trusted`** |
| SHA-1 / SHA-1 / 20 | `Trusted` | `Trusted` | OK | `Invalid` (SHA-1, by design) |
| SHA-256 / SHA-1 / 32 | `Trusted` | `Trusted` | OK | `Trusted` |
| **SHA-256 / SHA-384** / 32 | `Invalid` | `Invalid` ("certificate algorithm error") | OK | **`Trusted`** |
| SHA-256 / SHA-256 / 20 | `Trusted` | `Trusted` | OK | `Trusted` |
| **outer MGF1 hash → SHA-384** | `Invalid` | `Invalid` ("certificate algorithm error") | refused: "cert info signature and signature algorithm mismatch" | **`Valid`** (untrusted) |
| **outer MGF1 hash → unknown OID** | `Invalid` | `Invalid` ("certificate algorithm error") | refused, the same | **`Valid`** (untrusted) |

Four probes are more lenient here. Two are `Trusted` with a certificate
issued as it is, no byte changed after signing: a PSS leaf over SHA-224,
and one whose MGF1 hash differs from its PSS hash.

## Read in `c2pa-rs` 0.91.1

`src/crypto/cose/certificate_profile.rs`, `check_certificate_profile`, for
a leaf signed with RSASSA-PSS:

- It reads the parameters of the leaf's `signatureAlgorithm`, the outer
  field, not tbsCertificate's `signature`.
- It requires `[0]` (hash) and `[1]` (MGF) to be present.
- It requires the MGF1 hash to equal the PSS hash ("certificate algorithm
  error").
- It requires that hash to be SHA-256, SHA-384 or SHA-512 ("certificate
  hash algorithm not supported"). Both are `signingCredential.invalid`.
- Missing parameters are `signingCredential.invalid` as well.

DER leaves out a field that holds its default, and SHA-1 is the default
for both. So a SHA-1 PSS hash and a SHA-1 MGF1 have no `[0]` or `[1]`.
There the parse fails and `c2pa-rs` returns an error without logging a
status, and the measured result is `Trusted`. That explains the two SHA-1
rows. The salt length is not checked.

This verifier's profile (SPEC-015) checks the algorithm's name
(`rsassaPss` is allowed) and refuses a weak PSS hash (SHA-1, MD5; SPEC-048
for the path). It does not refuse an unknown or unlisted PSS hash, and it
does not read the MGF1 hash at all.

## Checked

No code changed. `composer check`: 949 passed.
