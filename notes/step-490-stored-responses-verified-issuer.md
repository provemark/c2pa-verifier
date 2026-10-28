# Step 490 — Stored responses read against the issuer the walk verified

*2026-10-28.* Finding R1 of the review of steps 483–489 (an independent
review of the fixes themselves, before tagging 0.9.0): SPEC-030 amendment
5, approved by Maurice. No contract symbol changes.

## Why

Step 483 made the revocation source hear about the issuer the chain walk
verified. The stored OCSP responses — stapled in the unsigned `rVals`,
or from certificate-status assertions — were still read against
`x5chain[1]`, which nothing checks when an anchor issued the signer
itself. The author of a file could put there N, a certificate with the
anchor's name and a key of their own, and staple a `good` about the
signer signed by N. It verified, the status was
`signingCredential.ocsp.notRevoked`, and because a stored response then
proved something, the source was never asked: a revoked signer stayed
`Trusted` under a source that knew better.

## What changed

- `OcspCheck::checkWithEvidence()` takes a last parameter `$trusted`.
  For a trusted signer, `stored()` reads every response against the
  issuer the walk verified; when the walk verified none (a signer on the
  allowed list or itself an anchor, without a proven `x5chain[1]`), each
  response is `notTried` and the status `skipped`, "the chain walk
  verified no issuer for the signer". For a signer that is not trusted,
  `x5chain[1]` as before.
- `Verifier` and `IngredientManifestCheck` pass the flag.
- Side effect, accepted in the amendment: a signer issued directly by an
  anchor, `x5chain` [L], has its staple read; a verified `revoked` makes
  the file `Invalid` where `c2patool` 0.28.3 says `Trusted` (ADR-0005).
  SPEC-085 amendment 2's probe is therefore `notRevoked` under its root;
  `RevocationNotTriedTest` now shows the not-tried case with L untrusted,
  and `EvidenceOcspTest`'s AC8 rule applies to signers that are not
  trusted (a note under SPEC-085 amendment 2).

## Measured

- The reviewer's script, run again here (`ChainCheck::walk()` and
  `OcspCheck::checkWithEvidence()` as `Verifier` calls them): without a
  staple `ocsp.revoked`, the source asked once; with N's forged `good`
  stapled `ocsp.notRevoked`, the source asked 0 times.
- `bin/make-revocation-stored-issuer-variants.php` (new): four leaf
  probes and one ingredient probe, keys deleted after the run; the table
  is in `tests/Fixtures/revocation-stored-issuer/README.md`. Before the
  build, `leaf-twin-forged.png` under R with a source answering L revoked
  was `Trusted`, the source not asked. `[L, B]` was already refused
  (B's name is not L's issuer). `c2patool` 0.28.3 calls all five
  `Trusted`.
- `tests/Unit/Verifier/RevocationStoredIssuerTest.php`: 8 tests failed
  before the build, 9 passed (controls, guards, the `c2patool` answers,
  and [L, B]); the ingredient test was added when mutation M2 survived
  and fails under it.
- Mutations: six, all caught — the active or the ingredient caller not
  passing the flag, `x5chain[1]` always, no `notTried` without an
  issuer, a trusted [L] not read, the flag applied only with a source.
- Corpus, 3,126 runs (no settings, `trust/full.settings.json`, the
  programme's lists) at `4a090f5` and now, reports and evidence outcomes:
  identical.
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.
