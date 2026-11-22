# Timestamp authorities off the certificate profile (SPEC-017 amendment 8)

Built by `bin/make-tsa-matrix.php <scratch> <c2patool-0.28.1>
<c2patool-0.27.22> tsa-profile control tsa-leaf-no-key-usage
tsa-leaf-ca-true tsa-leaf-sha1` on 2026-10-09 (step 290). Each PNG is
`../../fixture-signed.png` with its version 2 claim re-signed by a
throw-away signer and an RFC 3161 token from a throw-away timestamp
authority (`openssl ts -reply`) in `sigTst2`, over the CounterSignature
structure of C2PA 2.4 §10.3.2.5. The keys lived in a scratch directory while
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

## `c2patool` 0.29.0 and SPEC-017 amendment 12 (step 550)

`c2pa` 0.92.0 (upstream #3031) reports a TSA leaf's profile faults as
`timeStamp.untrusted` and ignores the time-stamp, as C2PA 2.4 §15.8.2 has
it. 0.29.0's answers for the four probes above were recorded in step 550
(`<probe>--0.29.0[--no-settings].json`):

| file | 0.29.0 with settings | 0.29.0 without | here, from amendment 12 |
|---|---|---|---|
| `control.png` | `Trusted` | `Valid` | `Trusted` / `Valid` |
| `tsa-leaf-no-key-usage.png` | `Trusted` | `Valid` | `Trusted` / `Valid` |
| `tsa-leaf-ca-true.png` | `Trusted` | `Valid` | `Trusted` / `Valid` |
| `tsa-leaf-sha1.png` | `Trusted` | `Valid` | `Trusted` / `Valid` |

Two probes added in step 550 (SPEC-017 AC15). They were built by
`bin/make-tsa-matrix.php <scratch> <c2patool-0.29.0> <c2patool-0.28.6>
tsa-profile expired-signer-tsa-leaf-ca-true
expired-signer-tsa-leaf-eku-email-only`. In each, the signer expired
during the run, after the token was made. The TSA leaf is off the profile.
The keys were deleted.

| file | the TSA's leaf | 0.28.6 with settings | 0.29.0 with settings | here |
|---|---|---|---|---|
| `expired-signer-tsa-leaf-ca-true.png` | `CA:TRUE` (`openssl ts`) | `Invalid` (`signingCredential.invalid`, `expired`) | `Invalid` (`signingCredential.expired`) | `Invalid` (`signingCredential.expired`) |
| `expired-signer-tsa-leaf-eku-email-only.png` | E-mail Protection only (CMS) | `Invalid` (`signingCredential.invalid` only: it reports `timeStamp.trusted`) | `Invalid` (`signingCredential.expired`) | `Invalid` (`signingCredential.expired`) |

Without settings, all three implementations say `Invalid` on both. The
answers are under
`../../c2patool/tsa-profile/<probe>--{0.28.6,0.29.0}[--no-settings].json`.
