# Step 385 — SPEC-079: the programme's JSON trust lists, with their dates, built

*2026-10-23.* Approved unchanged and built tests-first. Issue #9
(`PRED-CRYP-006`) is closed in the code; the issue itself is Maurice's
to close.

## What changed

- `--trust-list`, `--tsa-trust-list` and `TrustSettings::fromTrustLists()`
  read the programme's JSON lists (ETSI TS 119 602 LoTE) as well as PEM
  bundles, told apart by content.
- Each service's statuses, `ServiceInformation` and `ServiceHistory`,
  form a timeline; `TrustAnchorSet::$windows` holds the trusted
  stretches by anchor, `$reasons` why an anchor could not be placed.
- `ChainCheck::listedFault()`: a reached anchor counts only inside a
  stretch at the judged time (the trusted time-stamp's, else the
  validation time); otherwise `signingCredential.untrusted`, naming the
  anchor, the time and the stretches or the reason.
- `TimestampCheck::tsaSettings()` keeps the operator's TSA entries and
  their dates, so a TSA anchor is judged at the token's `genTime`.
- The official JSON lists as fixtures; `docs/conformance.md` (CRYP-006
  yes; 4 gaps left), `docs/comparison.md`, `docs/conformance-harness.md`.
- SPEC-077's Traceability corrected: `TrustAnchorSet` is a contract
  class, not internal, as its Scope had said.

## Measured

- Red: 16 of 16. Green after the build; five mutations caught (the dates
  ignored, the TSA dates dropped, an empty status read as trusted, the
  history ignored, no end to a stretch).
- The official JSON lists give 30 of 32 and 22 of 23 usable anchors, the
  PEM bundles' minus NETLOCK; Pixel 10 and OpenAI report the same through
  both forms.
- The corpus before and after: 0 of 1,854 runs moved.
- The contract: 140 → 142 (`TrustAnchorSet::$windows`, `$reasons`).
- `composer check`: 1,238 passed, 1 skipped.
