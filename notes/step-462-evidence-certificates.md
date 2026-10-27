# Step 462 — The certificates of `x5chain`

*2026-10-27.* SPEC-083 built. No verdict changes; the public contract grows
by one property, so this waits for the next minor release, together with
SPEC-081 and SPEC-082.

## Why

SPEC-082 names the anchor a signer's chain reached, not the certificates
between the signer and that anchor. They are in the file, in the claim
signature's `x5chain` header (C2PA 2.4 §14.5), but getting them out takes a
JUMBF, CBOR and COSE reader: the work an archive should not have to redo to
show later that the chain held. crJSON does not carry them either; it
summarises the leaf only.

## What changed

- `ManifestEvidence::$certificates`: the DER bytes of every certificate of
  that manifest's `x5chain`, exactly as the file carries them, the leaf
  first. An optional last constructor parameter.
- They are the bytes the COSE reader took (`CoseSign1::ofManifest()`), so
  the bounds that reader keeps (at most 16 certificates, each within a byte
  limit) hold here too. A certificate X.509 refuses is still handed out: the
  evidence is what the file carried, and the verdict already says it is
  faulty. When the COSE reader refuses the signature, the list is empty and
  the signature check has said why. Nothing new is refused.
- `IngredientManifestCheck::evidenceOf()` now takes the manifest rather
  than its label, so the active manifest and each ingredient manifest get
  their own chain from one place.

## Measured

- **Red first.** All 9 new tests failed: on the missing property, and the
  surface test on the missing line. Then green: `composer check`, 1,455
  passed, 1 skipped (the same skip as before).
- **AC3.** `c2pa-rs/CACA.jpg`, which the spec names, turned out to carry
  the same chain in both manifests, so the test also reads
  `c2pa-rs/ocsp.jpg`, whose two manifests have different signers. Over the
  corpus, 35 files have manifests with different chains.
- **AC5.** The test walks 1,025 corpus files under no settings: 755
  manifests in `evidence()`, each list equal to the COSE reader's chain, or
  empty where that reader refuses.
- **AC6 over the corpus.** 3,060 runs (1,020 files × no settings,
  `trust/full.settings.json`, the programme's LoTE lists) before (a
  worktree at `9eecfc2` with its own copy of `vendor/`, checked to load its
  own `src/`) and after: the SHA-256 of `toJson()` and `toCrJson()` is the
  same in every run.
- **Mutations.** Six changes to the new code, each caught: the list left
  empty, reversed, cut to the leaf, the first manifest's chain given to
  all, the certificates X.509 refuses left out, and the COSE fault no
  longer caught.

## For the next release

A contract change: 172 symbols, fourteen classes. The CHANGELOG entry of the
next minor names `ManifestEvidence::$certificates`, beside SPEC-081's and
SPEC-082's symbols. Next in C1: the time-stamps found (including the ones
ignored, and the TSA's certificates inside each token), then the OCSP
responses.
