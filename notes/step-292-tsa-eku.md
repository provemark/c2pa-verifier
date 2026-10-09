# Step 292 — The timestamp authority's extended key usage, measured

*2026-10-09. Measurement only; no change to `src/`.*

Step 289's timestamp matrix left out every variant of the TSA leaf's
extended key usage (EKU): `openssl ts -reply` refuses to sign with a
certificate whose EKU is anything but the critical `timeStamping` alone
("invalid signer certificate purpose"). RFC 3161 §2.3 asks exactly that of
a TSA certificate; C2PA 2.4 §14.5 is the certificate profile this verifier
applies to the TSA leaf with the EKU list replaced by `timeStamping`
(SPEC-017 AC7), and amendment 8 makes a fault against it `Invalid` for a
version 2 claim.

## Signing a token another way

`bin/make-tsa-matrix.php` has a new option, `sign => cms`. `openssl ts
-reply` makes the token with a helper certificate: the same key, the base
TSA extensions, issued by the same intermediate. Its TSTInfo is taken out
(`openssl cms -verify -noverify`) and signed again by the probe's own TSA
certificate with `openssl cms -sign -cades -econtent_type
id-smime-ct-TSTInfo`, which does not look at the EKU. The token carries
contentType, messageDigest, signingCertificateV2 (for the probe's
certificate) and, unlike `openssl ts`, signingTime.

Falsification of the route: `control-cms`, the valid TSA chain signed
through cms, gets the same answer from every judge as `control`
(`Trusted`, `timeStamp.validated` + `timeStamp.trusted` in 0.28.1 and
here; `openssl ts -verify` OK). And `openssl ts -verify` refuses every EKU
probe with "unsuitable certificate purpose", so the tokens are read as
tokens and judged on the EKU only.

## Result

Run: `php bin/make-tsa-matrix.php <scratch> <c2patool-0.28.1> <c2patool-0.27.22>`
(all 29 probes; the 22 of step 289 gave the same table as after step 290).

| probe | c2patool 0.28.1 | this verifier |
|---|---|---|
| `control-cms` | `Trusted`, `timeStamp.trusted` | the same |
| `timeStamping`, not critical | `Trusted`, `timeStamp.trusted` | the same |
| `timeStamping` + `emailProtection` | `Invalid`, `signingCredential.invalid`, `timeStamp.untrusted` | the same |
| `emailProtection` only | `Invalid`, `signingCredential.invalid`, **`timeStamp.trusted`** | `Invalid`, `signingCredential.invalid`, **`timeStamp.untrusted`** |
| no EKU | `Invalid`, `signingCredential.invalid`, `timeStamp.untrusted` | the same |
| `anyExtendedKeyUsage` | `Invalid`, `signingCredential.invalid`, `timeStamp.untrusted` | the same |
| `timeStamping` + `OCSPSigning` | `Invalid`, `signingCredential.invalid`, `timeStamp.untrusted` | the same |

**Nothing more lenient than 0.28.1.** Seven of seven agree on the state.

- One code difference where the state agrees: a TSA leaf with
  `emailProtection` only. 0.28.1 logs the profile fault and still reports
  the timestamp `trusted`; this verifier reports it `untrusted`. Ours
  is the stricter answer: a certificate without `timeStamping` is no time
  stamping authority (RFC 3161 §2.3).
- A non-critical `timeStamping` is accepted by both, against RFC 3161 §2.3
  ("MUST be marked critical"); OpenSSL refuses it. Equal to the oracle, so
  no change here; refusing it would be a choice to be stricter than
  `c2patool`.
- c2patool 0.27.22 (no `trust.anchors`, so no trust here) says `Valid` for
  the `emailProtection`-only leaf where it says `Invalid` for the others.
  Recorded, not used: 0.28.1 is the oracle for trust-kind settings.

## Checked

`composer check`: 938 passed. PHPStan in Docker `php:8.3-cli`: no errors.
The throw-away keys were deleted by the generator at the end of the run.
