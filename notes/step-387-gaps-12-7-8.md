# Step 387 — Gaps #12, #7 and #8: one set aside, two parked

*2026-10-23.* Maurice asked for the remaining gaps "if it is not too
much work", and agreed with this order. No code.

- **#12, `PRED-STRU-002`, by design.** This verifier emits no custom
  status codes. It reads them only in an ingredient's recorded
  `validationResults`, where a recorded code acknowledges a status raised
  here only when code, type and url match (SPEC-070, amendment 2), and
  every code raised here is a C2PA 2.4 §15 name. A custom code, well
  formed or not, can change nothing in a report, and the rule asks for no
  status of its own. The issue is Maurice's to close.
- **#7, `PRED-ASSE-021`, parked.** The multi-asset hash covers assets the
  API is not given (one stream), no measured writer emits
  `c2pa.hash.multi-asset`, and `c2pa-rs` 0.91.1 does not read it: no
  fixture, no oracle, and an API question.
- **#8, `PRED-CRYP-024`, parked.** No measured writer emits
  `c2pa.session-keys`; `c2pa-rs` 0.91.1 does not recognise it (searched
  its source for `session_keys`, `signerBinding`); a fixture would rest
  on the specification alone.

`docs/conformance.md`: 71 yes, 10 partial, 4 closed, 23 by design, 3
gaps (#1, #7, #8), recounted from the rows.

## Measured and reasoned

- Measured: the issues and their comments (none); `c2pa-rs` 0.91.1's
  source.
- Reasoned: that a custom code cannot reach the report.
