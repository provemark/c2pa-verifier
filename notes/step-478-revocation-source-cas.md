# Step 478 — The revocation source asked about the CAs of the path

*2026-10-27.* SPEC-087 built, under ADR-0007; with it candidate C3, and the
whole revocation-and-evidence proposal, is done. No new contract symbol:
`RevocationSource` keeps its signature and now also hears about the CAs.
It goes into the next minor with SPEC-081 to SPEC-086.

## Why

A certificate is also untrusted when a CA between it and the anchor was
revoked (C2PA 2.4 §15.9). SPEC-066 reads that from a stapled response, but
most files staple none, so a file under a compromised CA stayed `Trusted`
even with a source in hand.

## What changed

- `OcspCheck::fetchedCaRevocation()` asks the source about each CA strictly
  between the anchor and the signer, from the top down, with the
  certificate above it as issuer and its own AIA OCSP urls. A response read
  as SPEC-066 reads a stapled one that says revoked at the judged time (not
  removeFromCRL) ends the walk with a reason; anything else adds nothing.
- `ChainCheck::walk()` and `checkWithAnchor()` take the source and call it
  after `revokedCa()`: a stored revocation decides first and nothing is
  asked. A revoked CA makes the path reach no anchor,
  `signingCredential.untrusted`, so the signer is not asked afterwards.
  `Verifier` and `IngredientManifestCheck` pass the source on.
- The CAs are asked also when the signer's own staple proves something,
  where `c2pa-rs` asks them only when it proved nothing: a row in
  `docs/comparison.md` (ADR-0005: it prevents a `Trusted` under a revoked
  CA).
- `RevocationSource`'s docblock, the README, `docs/conformance.md`
  (`PRED-STRU-010`, `PRED-CRYP-020` *yes*: 77 yes, 18 by design) and
  `docs/reading-c2pa-2.4.md` (the §15.9 AIA row covered: 331 covered).
- **SPEC-086 amendment 1, approved by Maurice.** Five of its criteria counted every call the
  source received; they now count the calls about a signer, and its tests
  filter the recorded calls to those (`spec086SignerCalls()`). Without this
  ten SPEC-086 tests failed after the build, each because a CA call now
  came first. SPEC-087 should have named it; it is caught here, before the
  commit.

## Measured

- **Before the build.** 12 of the 21 tests failed, on the new behaviour; 9
  passed already, each a guard that nothing is asked or changed where it
  may not be — true before, since nothing asked about a CA. (Twenty-one
  tests: AC3 has five cases, AC4 two, AC7 two, AC9 three, AC10 three.)
- **After.** `composer check` and `vendor/bin/pest --parallel`, both exit 0.
- **A flaky test of my own, caught.** AC9's first version compared two
  reports judged at *now*; a `skipped` explanation names the judged time,
  and two runs straddled a second in the parallel run. AC9 now uses a fixed
  validation time.
- **AC9 over the corpus.** 3,066 runs in a worktree at `aa04153` (own
  `vendor/` copy, checked to load its own `src/`) and after, with no source
  and with `NoRevocationSource`: identical.
- **AC10.** With a recording source answering null: none 0 calls; `full`
  222 about CAs and 222 about signers in 187 files (every trusted path in
  the corpus has one intermediate); the lists 2 and 2.
- **Mutations.** Fifteen. Ten caught at once; three (bottom-up order, M4;
  a revocation after the judged time counted, M6; only the first CA asked,
  M11) by tests written after the build. Two not caught: dropping the size
  limit on a fetched CA response (M8) — no test without a valid signed
  response over 64 KiB, and the limit guards work, not the verdict; and
  dropping the source in the branch where the anchor itself is reached in
  `x5chain` (M14) — for a valid chain that branch holds no CA between, since
  a certificate the anchor issued is taken by the other branch first.
- **A mutation runner fault.** The first run of the mutation script crashed
  on undecodable test output after writing mutation M1 and before
  restoring it; `Verifier.php` was put back by hand and checked, and the
  runner now reads bytes.

## For the next release

No CHANGELOG symbol of its own; the entry for `RevocationSource` says it is
asked about the signer and the CAs of its path.
