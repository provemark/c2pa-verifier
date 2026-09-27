# Step 166 — SPEC-046: name constraints and critical extensions

*2026-09-27. SPEC-046 approved by Maurice van Loon this day.*

## Tests seen red

The step 164 script gained one variant for AC5: `nc-dns`, a leaf under an
intermediate whose nameConstraints permit only `dNSName:example.com`. Both
`c2patool` versions and `openssl verify` accept it, because the leaf has no
DNS name. The whole set was rebuilt with new throw-away keys, and every
earlier answer came out the same.

`vendor/bin/pest --group=SPEC-046` before the change: 4 failed, 2 passed.
AC1, AC3, AC4 and AC5 failed, each on `'Trusted'` where `c2patool`'s
verdict (or, for AC5, `'Valid'`) was expected. The guards AC2 and AC6
passed.

## The change

- **`Trust\CertificateExtensions`** (new, `@internal`) reads a
  certificate's own DER:
  - every extension of `tbsCertificate` with its critical flag;
  - the subject as RDNs, normalised for comparison (RFC 5280 §7.1:
    surrounding and repeated white space, case);
  - the e-mail addresses from subjectAltName and the subject's
    `emailAddress`;
  - the certificate's own nameConstraints.

  `UNDERSTOOD` lists the 17 extensions of SPEC-046 scope item 1.
  `Certificate` holds it as `$x509`.
- **`Trust\NameConstraints`** (new, `@internal`) evaluates
  `directoryName` and `rfc822Name`, both permitted and excluded. Any
  other form, and any subtree with a minimum or maximum, makes every
  certificate below it fail.
- **`ChainCheck::pathFault()`** runs on a path that reached an anchor,
  before the path is trusted. The path is the anchor, or the first
  certificate that is an anchor, down to the leaf. It checks two things:
  - a critical extension outside `UNDERSTOOD` in any certificate of the
    path;
  - a name below a constraining CA that the constraint does not allow.
    A self-issued intermediate's own subject is exempt.

  Either one is `signingCredential.untrusted`. The TSA's chain uses the
  same walk (scope item 5).
- **`CertificateProfileCheck::checkLeaf()`** rule 9: a critical extension
  outside `UNDERSTOOD` in the leaf is `signingCredential.invalid`, as
  `c2pa-rs`'s profile check gives it.

## Measured after

- `vendor/bin/pest --group=SPEC-046`: 6 passed. `composer check`: exit 0,
  542 passed.
- **All media fixtures under no settings and every readable settings
  file, 22,605 runs, before and after.** Only the new files moved:
  - `nc-outside`, `critical-intermediate` and `nc-dns` go from `Trusted`
    to `Valid` under their root;
  - `critical-leaf` goes to `Invalid` under every settings file (55
    runs), because the profile rule does not depend on trust.

  No real chain in the corpus carries name constraints or an unknown
  critical extension; that is AC6.
- `docs/comparison.md` names AC5's difference.

**Weight A:** files that were wrongly `Trusted` become `Valid` or
`Invalid`. Present since the chain walk was built (M5), to 0.2.4.

## Disclosure

Local until the release that closes step 164 (with SPEC-047).
