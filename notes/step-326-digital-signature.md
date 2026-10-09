# Step 326 — A manifest signer asserts Digital Signature (C6, SPEC-015 amendment 10)

*2026-10-09.*

C2PA 2.4 §14.5.1.1: *"Certificates used to sign C2PA manifests shall
assert the digitalSignature bit."* SPEC-015 AC4 had followed `c2pa-rs`,
which also takes a KeyUsage of Non Repudiation alone. Step 324 switched the
rule on in a scratch copy and ran the corpus under every settings file. No
real file moved, so Maurice chose to follow the specification.

## What changed

- **`CertificateProfileCheck::checkLeaf()`** requires `Digital Signature`
  of a manifest signer (`signingCredential.invalid`). A leaf with
  Certificate Sign and no Digital Signature, which passed before, now
  fails too.
- **A TSA is not a manifest signer.** `TimestampCheck` calls the same
  `checkLeaf()` for the timestamp authority's certificate. It now passes
  `manifestSigner: false`, which keeps the old rule there (Digital
  Signature or Non Repudiation). This was found while building: the
  scratch experiment of step 324 had no such exception.
- SPEC-015 amendment 10 (AC4 changed, AC10 leaves the variant out), the
  CHANGELOG, `docs/reading-c2pa-2.4.md` (C6 adopted; the KeyUsage row
  covered).

## Measured

- **Tests first.** AC4: red (`Trusted` where `Invalid` was expected), then
  green. The TSA case in AC4 is red with `manifestSigner: true` and green
  with `false`.
- **The corpus.** 875 files under no settings and 160 settings files,
  before and after: 483 runs moved. Only `profile/no-digital-signature.png`
  changed its verdict. Two fixtures that were already `Invalid`
  (`leaf-ku-key-encipherment`, `keyusage-not-utf8`) changed their reason.
  No real file moved.
- **The fuzzer.** Seed 20261005 × 60 without settings: 0 faults. With
  `--trust`, 20261005 × 60 and 20261009 × 200: 534 and 1,765 suspects, each
  judged by `c2patool` 0.28.1, none more lenient here.
- The measurement now runs in parallel. The corpus, the three fuzz runs
  and the `c2patool` checks (eight at a time) run at once: 266 seconds
  where it took about twenty minutes, with the same counts.
- `composer check`: 972 passed.
