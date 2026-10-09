# Step 327 — Every certificate above the signer meets the profile (C4, SPEC-014 amendment 8)

*2026-10-09.*

C2PA 2.4 §14.5.1.1 says *"All certificates shall fulfill"* the profile:
the signature algorithm from its list, the RSASSA-PSS parameters, a curve
from P-256/384/521, RSA of at least 2048 bits, X.509 version 3, and an
Authority Key Identifier where the certificate is not self-signed. This
verifier, like `c2pa-rs`, held only the signer's certificate to it. Step
324 switched the rule on in a scratch copy, the anchor included, and no
real file in the corpus moved. Maurice chose to follow the specification.

## What changed

- **`ChainCheck::profileFault()`** (new), called by `pathFault()` for every
  certificate above the leaf, the anchor included. It runs after the
  SPEC-048 (MD5, SHA-1) and SPEC-049 (RSA exponent) checks, so those
  keep their own reasons. A path that fails reaches no anchor:
  `signingCredential.untrusted`.
  `CertificateProfileCheck::SIGNATURE_ALGORITHMS` and `CURVES` are shared
  for it. The class is internal, so this is not a contract change.
- **Four probes in the trust matrix** (`bin/make-trust-matrix.php`, set
  `chain-matrix`; 61 now): `int-no-aki`, `int-secp256k1`, `int-no-ski`
  and `anchor-no-ski`. The first try had no effect: OpenSSL 3 adds a
  Subject and an Authority Key Identifier by default, even with the line
  removed. The probes are built with the value `none`.
- SPEC-014 amendment 8 (AC14). SPEC-048 amendment 2 and SPEC-049 amendment
  4: their controls used the RSA-1024 intermediate as a file that stays
  `Trusted`, and now assert what each spec is about. SPEC-061 amendment 5
  (the matrix). The matrix README, the CHANGELOG, and
  `docs/reading-c2pa-2.4.md` (C4 adopted; five profile rows covered for
  every certificate).

## Measured

| probe | `c2patool` 0.28.1 | 0.27.22 | OpenSSL | here before | here after |
|---|---|---|---|---|---|
| `int-rsa1024` | `Trusted` | `Trusted` | OK | `Trusted` | `Valid` |
| `int-secp256k1` | `Trusted` | `Trusted` | OK | `Trusted` | `Valid` |
| `int-no-aki` | `Valid` | `Valid` | refused | `Trusted` | `Valid` |
| `anchor-rsa1024` | `Trusted` | `Trusted` | OK | `Trusted` | `Valid` |
| `anchor-sha1-self-signed` | `Trusted` | `Trusted` | OK | `Trusted` | `Valid` |
| `int-no-ski` | `Invalid` | `Invalid` | refused | `Invalid` | `Invalid` |
| `anchor-no-ski` | `Valid` | `Valid` | refused | `Trusted` | `Valid` |

`int-no-aki` and `anchor-no-ski` were more lenient than `c2patool` before
this step. Below a CA without a Subject Key Identifier, the next
certificate has no AKI keyid; that is what `c2patool` and OpenSSL refuse.

- **Tests first.** The matrix (`SPEC061_STRICTER`) and the SPEC-048/049
  controls: 3 failed, then green after the change and the probes' rebuild.
- **The corpus.** 875 files under no settings and 161 settings files (with
  the four new probes), before and after: 8 runs moved, all crafted
  fixtures. No real file moved.
- **The fuzzer.** Seed 20261005 × 60 without settings: 0 faults. With
  `--trust` (256 pairs), 20261005 × 60 and 20261009 × 200: 534 and 1,765
  suspects, each judged by `c2patool` 0.28.1, none more lenient here.
- Two measurements were started at once by mistake, and they shared a
  worktree. The corpus comparison was then run again on its own; the
  numbers above are from that clean run. The measurement script now takes
  a lock.
- `composer check`: 972 passed.
