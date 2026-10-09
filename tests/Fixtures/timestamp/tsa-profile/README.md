# Timestamp authorities off the certificate profile (SPEC-017 amendment 8)

Built by `bin/make-tsa-matrix.php <scratch> <c2patool-0.28.1>
<c2patool-0.27.22> tsa-profile control tsa-leaf-no-key-usage
tsa-leaf-ca-true tsa-leaf-sha1` on 2026-10-09 (step 290). Each PNG is
`../../fixture-signed.png` with its version 2 claim re-signed by a
throw-away signer and an RFC 3161 token from a throw-away timestamp
authority (`openssl ts -reply`) in `sigTst2`, over the CounterSignature
structure of C2PA 2.4 §14.6. The keys lived in a scratch directory while
the script ran and were deleted. No private key is here.
`<probe>.settings.json` holds the signer's root as a `"manifest"` entry and
the TSA's root as a `"tsa"` entry.

| file | the TSA's leaf | 0.28.1 with settings | 0.28.1 without | 0.27.22 without |
|---|---|---|---|---|
| `control.png` | on the profile | `Trusted` | `Valid` | `Valid` |
| `tsa-leaf-no-key-usage.png` | no keyUsage | `Invalid` | `Invalid` | `Invalid` |
| `tsa-leaf-ca-true.png` | `CA:TRUE` | `Invalid` | `Invalid` | `Invalid` |
| `tsa-leaf-sha1.png` | signed over SHA-1 | `Invalid` | `Invalid` | `Invalid` |

Every `Invalid` carries `timeStamp.untrusted` and `signingCredential.invalid`:
`c2pa-rs` logs the TSA's profile faults under that code. 0.27.22 does not
read `trust.anchors`, so its answer with the settings is its answer without
them. The answers are under
`../../c2patool/tsa-profile/<probe>--<version>[--no-settings].json`.
