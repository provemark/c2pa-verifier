# Step 470 — The OCSP responses offered

*2026-10-27.* SPEC-085 built, with amendment 1. No verdict changes; the
public contract grows by one class and one property, so this waits for the
next minor release, together with SPEC-081 to SPEC-084. With it, the four
specs that hand out the evidence behind a verdict (candidate C1) are done.

## Why

An OCSP response is the issuer's signed statement that a certificate was not
revoked at a given moment (RFC 6960). A manifest can carry such responses in
the unprotected `rVals` header and in `c2pa.certificate-status` assertions
(C2PA 2.4 §14, §15.9, §18.19). The report said what the check concluded,
not which response it rested on. An issuer only has to answer OCSP until a
year after the certificate expires; after that, the response in the file is
the only evidence left that the certificate was good when the file arrived.

The corpus shows it plainly: the stapled responses of the real writers'
files (OpenAI, Adobe, Amazon, `c2pa-rs`) are readable, about the signer and
verified, and every one of them is `skipped` today, because each was current
for a week. At a validation time inside that week, the same bytes give
`notRevoked`.

## What changed

- `ManifestEvidence::$ocsp`: one `Trust\OcspEvidence` per response in the
  pool the signer's revocation check walks — stapled first, then the store's
  certificate-status responses, at most four of each. Each holds the source
  (`rVals`, `assertion`), the url, the response's DER as the file carries
  it, the outcome (`chosen`, `notChosen`, `unusable`, `notTried`), the code
  the chosen one gave, and for a usable one its status and times.
- **The check hands it out.** `OcspCheck::checkWithEvidence()` records each
  response where the loop judges it; `check()` is its first half, so there
  is no second reading that could drift. The evidence is now recorded after
  the revocation check, in `Verifier::check()` and in
  `IngredientManifestCheck::manifest()` alike.
- An ingredient manifest whose chain cannot be read is not checked for
  revocation at all, as before, so it has no entries; the active manifest in
  that case is checked against an empty chain, and its responses are listed
  as `unusable`. Both are what the check already did.
- **Amendment 1.** The *Problem* section claimed that all 18 manifests with
  a stapled response came from real writers and were usable. The build's
  corpus run showed 7 from real writers (usable, `skipped` today), 7 probes
  whose response is about a CA (unusable for the signer) and 4
  ingredient-staple probes (`notRevoked` or `revoked`). The claim had been
  generalised from five files; the text now says what was measured.

## Measured

- **Red first.** The 14 tests for the acceptance criteria all failed on the
  missing class and method; `ApiSurfaceTest` AC1 on the seventeen new
  symbols. Then green: `composer check` and `vendor/bin/pest --parallel`,
  both exit 0, 1,486 passed, 1 skipped (the same skip as before).
- **AC8 over the corpus.** Every file under `tests/Fixtures/` under no
  settings, `trust/full.settings.json` and the programme's LoTE lists: no
  disagreement. Under no settings: 15 responses chosen, 2 not chosen, 17
  unusable.
- **AC9 over the corpus.** 3,060 runs before (a worktree at `afc911b` with
  its own copy of `vendor/`, checked to load its own `src/`) and after: the
  SHA-256 of `toJson()` and `toCrJson()` is the same in every run.
- **Mutations.** Twelve changes, each caught: never chosen, not-tried called
  unusable, a code on every entry, the times dropped, every source called
  `rVals`, every url the signature's, no entries without an issuer, an
  unusable response left unmarked, no entries when nothing is usable, the
  evidence not passed on for the active manifest, and for an ingredient. One
  — an assertion response over the size limit left unmarked (M8) — survived
  at first, since no corpus file has one; a test written after the build now
  catches it.

## For the next release

A contract change: 204 symbols, sixteen classes. The CHANGELOG entry of the
next minor names `Trust\OcspEvidence` and `ManifestEvidence::$ocsp`, beside
the symbols of SPEC-081 to SPEC-084. C1 is complete; next in the proposal is
C3 (revocation data from outside), which needs ADR-0007 first.
