# Step 459 — The time judged at, and the anchor reached

*2026-10-27.* SPEC-082 built, with amendment 1. No verdict changes; the
public contract grows by two classes and one method, so this waits for the
next minor release, together with SPEC-081.

## Why

The report said *that* a manifest is `Trusted`, not *why*. The two facts
that decide it were computed and thrown away: the time the signer was
judged at (a trusted time-stamp's, the caller's, or the clock), and the
anchor the chain reached, with the list that made it count. A common name
in an explanation does not identify a certificate; two of the test roots
share one subject and differ in key.

## What changed

- `VerificationReport::evidence()`: one `Verifier\ManifestEvidence` per
  judged manifest, by label, the active manifest first, then each
  ingredient manifest in the order checked. Each holds `judgedAt`,
  `timeSource` (`timestamp`, `given`, `clock`), and `signer` and `tsa`.
- `Trust\AnchorEvidence`: the route (`anchor` or `allowedList`), the
  SHA-256 of the anchor's DER (the leaf's for the allowed list), the depth,
  the `TrustListIdentity` of the list that made it count (SPEC-081), and the
  stretch of that list's dates that held the time judged at (SPEC-079).
- **The walk hands it out.** `ChainCheck::walk()` returns the anchor beside
  the status; `checkCertificates()` and `check()` are that walk's first
  half, so there is no second implementation that could drift. The list is
  found in the order `listedFault()` consults: the legacy `trust_anchors`
  first. `TimestampResult` carries the TSA's anchor; `tsaSettings()` now
  keeps a list's identity when it turns the "tsa" entries into the TSA
  check's own.
- **Amendment 1.** `AnchorEvidence` lives in `Trust`, since the layer rules
  let `Trust` depend on nothing in `Verifier`. And an ingredient manifest
  whose success lines the writer recorded keeps its evidence, although the
  report drops those lines as `c2pa-rs` does (SPEC-021 amendment 1).

## Measured

- **Red first.** The 13 new tests all failed on the missing `evidence()`;
  `ApiSurfaceTest` AC1 on the nineteen new symbols. Then green:
  `composer check`, 1,446 passed, 1 skipped (the same skip as before).
- **AC7 over the corpus.** Every file under `tests/Fixtures/` (1,020) under
  no settings, `trust/full.settings.json` and the programme's LoTE lists:
  2,265 judged manifests, 224 with a signer anchor, 1 with a TSA anchor
  (the TSA side is exercised by the tsa-matrix probes under their own
  settings, in the test), and no disagreement between the evidence and the
  statuses. 8 ingredient manifests, all in update-manifest files under
  `full.settings.json`, have no line in the report and were not compared:
  that is amendment 1.
- **AC9 over the corpus.** The same 3,060 runs before (a worktree at
  `4ef8b02` with its own copy of `vendor/`, checked to load its own `src/`)
  and after: the SHA-256 of `toJson()` and `toCrJson()` is the same in
  every run.
- **Mutations.** Eight changes to the new code, each caught: the depth off
  by one, the stretch dropped, the TSA's anchor dropped, the time sources
  swapped, the allowed-list route called an anchor, the list dropped, the
  evidence leaked into `toArray()`, the TSA list's identity dropped.

## For the next release

A contract change: 171 symbols, fourteen classes. The CHANGELOG entry of the
next minor names `Trust\AnchorEvidence`, `Verifier\ManifestEvidence` and
`VerificationReport::evidence()`, beside SPEC-081's symbols. Next in C1: the
certificates of `x5chain`, then the time-stamps found, then the OCSP
responses.
