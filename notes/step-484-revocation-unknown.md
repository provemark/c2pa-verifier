# Step 484 — A stored `unknown` no longer blocks the revocation source

*2026-10-27.* Finding 4 of the review of step 482, fixed before 0.9.0 is
tagged: SPEC-086 amendment 3, approved by Maurice. No contract symbol
changes.

## Why

A stapled OCSP response that answers `unknown` about the signer gave
`signingCredential.ocsp.unknown`, and the revocation source (SPEC-086) was
asked only after a `skipped`. The `rVals` header is not covered by the
signature, so anyone can staple an `unknown` and keep the source from being
asked; a revoked signer then stayed `Trusted` under a source that knew
better. `unknown` establishes nothing (C2PA 2.4 §15.9.2), and `c2pa-rs`
0.91.1 fetches in that case too (read, `check_ocsp_status()`).

## What changed

`OcspCheck::checkWithEvidence()` asks the source also after a stored
`unknown`. Only a decisive answer replaces it: a `revoked` at the judged
time or a current `good`; the stored response is then `notChosen` in the
evidence, without a code. Anything else leaves
`signingCredential.ocsp.unknown`, with the explanation ending "the caller's
revocation source was asked as well: …"; a usable fetched answer is then
`notChosen`, an unusable one `unusable`. One `signingCredential.ocsp.*`
status per manifest, as before.

## Measured

- `bin/make-revocation-unknown-variants.php` (new): one probe with a
  stapled `unknown`, three responses; keys deleted after the run. This is
  the first file that reaches the `unknown` branch at all: no test or
  fixture did before, it had only been read. `c2patool` 0.28.3: `Trusted`,
  no OCSP code.
- `tests/Unit/Verifier/RevocationUnknownTest.php`, 7 tests: 6 failed before
  the implementation, the guard without a source passed (and still does).
- Mutations (`RevocationUnknownTest`, `RevocationSourceTest`,
  `tests/Unit/Trust`): eight, all caught — `unknown` not asked, the fetched
  answer always winning, `revoked` or `good` not decisive, nothing or the
  wrong side demoted in the evidence, the demoted entry keeping its code,
  the explanation dropping what the source gave.
- Corpus, 3,066 runs (no settings, `trust/full.settings.json`, the
  programme's lists) at `2dd9ad3` (worktree with a copied `vendor/`) and
  now: identical.
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.
