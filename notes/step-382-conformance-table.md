# Step 382 — The conformance table brought up to date

*2026-10-23.* `docs/conformance.md` was last brought up to date on
2026-09-28 (step 176). Specs since then closed rows it still called
gaps. Maurice asked for the list of gaps, then for the table first.
No code.

## Rows changed

| predicate | was | now | by |
|---|---|---|---|
| `PRED-TIME-001` | partial | yes | SPEC-064 (the time-stamp assertion as fallback) |
| `PRED-TIME-002` | gap | yes | SPEC-064 (tokens by manifest label) |
| `PRED-TIME-003` | gap | yes | SPEC-064 (`assertion.timestamp.malformed`) |
| `PRED-CRYP-010` | partial | yes | SPEC-017 amendment 9 (more than one token: malformed) |
| `PRED-CRYP-019` | gap | by design | issue #10, closed as not planned |
| `PRED-ASSE-013` | gap | yes | SPEC-065 (shape) |
| `PRED-ASSE-014` | gap | yes | SPEC-065 (the embedded image's hash) |
| `PRED-ASSE-026` | gap | yes | SPEC-065 (exactly one of the two fields) |

The count: 63 → 70 yes, 12 → 10 partial, 21 → 22 by design, 11 → 5
gaps; 111 in all. Recounted from the rows themselves, not only from the
summary. The five gaps are the five open issues: #1 (`PRED-ASSE-024`),
#7 (`PRED-ASSE-021`), #8 (`PRED-CRYP-024`), #9 (`PRED-CRYP-006`), #12
(`PRED-STRU-002`). The lists that sort the gaps by cost lost the rows
that closed.

## Measured and reasoned

- Measured: each changed row against the code (the status codes in
  `src/Report/StatusCode.php`, `TimestampCheck::checkHeader()` and
  `forManifest()`, SPEC-065's criteria) and the issues' states on
  GitHub.
- Reasoned: the predicates' wording against what the code does; the
  table remains a reading, as its own last section says.
