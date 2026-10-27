# Step 483 — The issuer the walk verified, and the second walk's CAs

*2026-10-27.* The first two findings of the review of step 482, fixed
before 0.9.0 is tagged: SPEC-086 amendment 2, SPEC-087 amendment 1 and
SPEC-066 amendment 3, approved by Maurice with option b for the first. No
contract symbol changes.

## Why

**Finding 1.** The revocation source (SPEC-086) was asked about the signer
with `x5chain[1]` as its issuer. The chain walk (SPEC-016) does not need
that certificate when an anchor issued the signer itself: it finds the
anchor in the trust list. A signer issued directly by an anchor could
therefore leave `x5chain[1]` out (the source was then not asked at all) or
put an unrelated certificate there (the source got a question about the
wrong issuer, which a responder cannot answer for the right one). Either
way a revoked signer stayed `Trusted` under a source that knew better.

**Finding 2.** A signer whose only EKU is Time Stamping and which reaches
only the legacy `trust_anchors` is walked a second time without them (step
159). That second walk received neither the COSE unprotected header nor
the source, so a revoked CA on its path went unseen, from a staple (since
0.6.0, SPEC-066) or from the source (SPEC-087).

## What changed

- `ChainCheck::walk()` and `checkWithAnchor()` return a third element: the
  certificate the walk verified as the signer's issuer — `x5chain[1]` when
  the path ran through it, the anchor when the anchor issued the signer.
  For a signer the walk vouches for without a certificate above it (on the
  allowed list, or itself an anchor), option b: `x5chain[1]` only when its
  subject is the signer's issuer and its key verifies the signer's
  signature (`provenIssuer()`), otherwise null.
- `OcspCheck::checkWithEvidence()` takes that issuer as a new last
  parameter and asks the source only with it; null means not asked.
  `Verifier` and `IngredientManifestCheck` pass it.
- `timeStampingSigner()` passes the unprotected header and the source to
  its second walk, keeps that walk's issuer, and ends its
  `signingCredential.untrusted` explanation with the second walk's own
  reason ("without the legacy anchors: …"), so a revoked CA is named.
- `RevocationSource`'s docblock says which issuer it is told.

## Measured

- `bin/make-revocation-issuer-variants.php` (new): seven probes with
  throw-away keys, deleted after the run; the table is in
  `tests/Fixtures/revocation-issuer/README.md`. `c2patool` 0.28.3 calls
  all seven `Trusted`: it asks no source and checks no CA's revocation.
- `tests/Unit/Verifier/RevocationIssuerTest.php`, 16 tests. Run against
  the code before the build (a worktree at `6785511`): 11 failed, 5
  passed — the control [L, R], the allowed-list signer with `x5chain` [L]
  (not asked before either), the anchor signer with [L, R], and the guard
  that T stays trusted without a revocation. Two of the 16 (the name twin
  N and key twin K) were added after mutations M2 and M3 survived: B
  differs from R in name and key at once, so neither check alone was
  pinned. Both now fail under their mutation.
- Mutations (`RevocationIssuerTest`, `RevocationSourceTest`,
  `RevocationSourceCasTest`, `tests/Unit/Trust`): ten, all caught — the
  allowed list or the anchor signer taking `x5chain[1]` unproven, the
  proof without its signature or its name check, the anchor issuer at
  depth 0 replaced by `x5chain[1]`, the fetch told `x5chain[1]`, the second
  walk without the header or the source, and either caller passing no
  issuer.
- One existing test followed: SPEC-086's *Scope* test now passes the
  issuer itself, and the one about an issuer missing from `x5chain` reads
  "without an issuer the walk verified" (amendment 2).
- Corpus, 3,057 runs (no settings, `trust/full.settings.json`, the
  programme's lists) at `6785511` (worktree with a copied `vendor/`) and
  now: identical. Under their own settings, `tsa-signer-staple.png` moves
  from `Trusted` to `Valid`, and `tsa-signer/tsa-only.png` under
  `legacy.settings.json` keeps `Valid` with a longer explanation.
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.

## Read, not measured

`c2pa-rs` 0.91.1 builds its OCSP request with `x5chain[1]` when that
certificate's subject matches the signer's issuer, by name only, and
otherwise with the first signing anchor of that subject
(`crypto/cose/ocsp.rs`, `ocsp_signing_chain()`). The name twin N would pass
there. A row in `docs/comparison.md`.
