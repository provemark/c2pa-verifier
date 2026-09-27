# Step 167 — SPEC-047: where the certificate chain may be

*2026-09-27. SPEC-047 approved by Maurice van Loon this day, with option A
for the unprotected `"x5chain"` in a claim v2 or later.*

## Tests seen red

`vendor/bin/pest --group=SPEC-047` before the change: 3 failed, 2 passed.
AC1, AC2 and AC3 failed on `'Trusted'` where `'Invalid'` was expected.
The guards AC4 (the 2022 Adobe claim v1 file) and AC5 (the protected
`plain` probe) passed. AC4 first failed for a wrong reason: it compared
with the public-testfiles oracle, which was recorded with trust settings.
It now compares with `c2patool/adobe-20220124-C.json`, which was recorded
without settings, as the test verifies.

## The change

- **`CoseSign1::findChain()`** looks where `c2pa-rs`'s
  `cert_chain_from_sign1` looks:
  - protected label 33, then protected `"x5chain"`;
  - only when neither is there, the unprotected `"x5chain"`.

  Label 33 is not read from the unprotected header. A protected chain
  together with an unprotected `"x5chain"` is `signingCredential.invalid`
  (*"ambiguous"*). This is SPEC-008 amendment 3. The missing-chain message
  keeps SPEC-008 AC10's opening words.
- **`CoseSign1::ofManifest()`** (new) decodes a manifest's signature and,
  for a claim v2 or later, refuses a chain that is not protected with
  `signingCredential.invalid`. The six places that decode a manifest's
  signature now go through it:
  - in `Verifier`: `signatureInfo()`, `unprotectedHeader()` and
    `chainOf()`;
  - `ChainCheck`, `CertificateProfileCheck` and `TimestampCheck`.

  `ClaimSignatureCheck` is unchanged (amendment 1): the signature does
  verify under the key in the header.

## Measured after

- `vendor/bin/pest --group=SPEC-047`: 5 passed. `composer check`: exit 0,
  547 passed.
- `x5chain-text-swapped.png` now reports these statuses:
  - `claimSignature.insideValidity` and `claimSignature.validated`;
  - `signingCredential.invalid` twice, from the chain and the profile
    checks;
  - `ocsp.skipped`;
  - the hash matches.

  It has no `signature_info`, so "Adobe Inc" appears nowhere in the report.
- **All media fixtures under no settings and every readable settings file,
  22,605 runs, before (step 166) and after.** Only the five probes with an
  unprotected or doubled chain moved, to `Invalid` under every settings
  file. Every claim v2 in the corpus carries its chain in the protected
  header. Every claim v1 file, the 2022 Adobe files included, is
  unchanged.
- `docs/comparison.md` names the difference for claims v2 and later.

**Weight A:** files that were wrongly `Trusted` become `Invalid`. Present
since COSE was read (M3), to 0.2.4.

## Disclosure

Local until the release that closes step 164 (with SPEC-046).
