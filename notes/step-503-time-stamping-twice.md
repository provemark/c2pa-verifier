# Step 503 — Time Stamping written twice is still the only EKU

*2026-10-29.* Finding T2 of the security review before 0.10.0, a wrong
`Trusted` (with a low likelihood). SPEC-031 amendment 5, approved by
Maurice before the test was written.

## The rule, and the comparison that missed it

The legacy `trust.trust_anchors` field anchors signers and time-stamping
authorities alike. SPEC-031 amendment 3 (step 159) keeps a certificate
whose only EKU is Time Stamping from signing manifests under it: such a
signer is trusted only by a `"manifest"` entry or an allowed list.
`ChainCheck::timeStampingSigner()` tested "only EKU" as the list being
exactly `[timeStamping]`. `Certificate` keeps the list as the certificate
writes it, duplicates included, so a leaf naming Time Stamping twice was
not equal to it and the second walk was skipped. The profile check
(`CertificateProfileCheck::ekuFaults()`) asks whether anything other than
Time Stamping is present, and nothing is.

## What was measured

- The reviewer's probe, re-run: with legacy anchors only, a leaf with EKU
  `[TS]` is `signingCredential.untrusted`, one with `[TS, TS]`
  `signingCredential.trusted`, with no profile fault.
- `bin/make-tsa-signer-twice-variants.php`, made from step 159's generator
  (whose `c2patool` 0.28.0 and 0.27.22 are no longer kept, so step 159's
  files were left as they are): a second throw-away root and two leaves,
  the PNG fixture re-signed with each. `openssl x509 -ext
  extendedKeyUsage` on the second leaf prints `Time Stamping, Time
  Stamping`; OpenSSL issued it without complaint.
- `c2patool` 0.28.3 under `legacy.settings.json`: `Trusted` on both, the
  difference ADR-0005 already names.
- The new test before the change: the `twice` case failed (`Trusted`),
  the `once` case passed. After: both pass. The old `ChainCheck` against
  the final files once more: the same.
- Every comparison of the EKU list as a whole in `src/`: only this one.
  The profile check and the OCSP responder check test for presence, which
  is already a set reading.
- The corpus before (`7c2723f`, worktree, `vendor/` copied) and after:
  2,154 runs, none differs.
- `composer check` exit 0 (1,667 passed, 1 skipped), `vendor/bin/pest
  --parallel` exit 0. On the way, `BinFunctionNamesTest` caught the
  generator's helpers declared under the same names as step 159's; they
  are `tw*` now.

## What was done

`timeStampingSigner()` reads the purposes as a set, each counted once. A
repeated purpose is not made a fault of its own: RFC 5280 §4.2.1.12 does
not forbid it. New: the generator, `tests/Fixtures/tsa-signer-twice/`
(two PNGs, their leaves, the root, the settings, a README),
`tests/Fixtures/c2patool/tsa-signer-twice/`; SPEC-031 AC9 gains the case.
