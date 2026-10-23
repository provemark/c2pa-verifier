# Step 388 — Gap #1 set aside: a rule the programme checks itself

*2026-10-23.* `PRED-ASSE-024` (issue #1): C2PA 2.4 §18.3.5, "A
reviewRatings shall not be present if a dataSource object is present
with a type field whose value is either humanEntry.anonymous or
humanEntry.credentialed." Measured before writing a spec, and set aside
by Maurice's decision. No code.

## Measured

- Chapter 15 (validation) names neither `reviewRatings`, `dataSource`
  nor `humanEntry`; C2PA 2.4 has no status code for the rule. The terms
  occur in §18.3.3–§18.3.5 only (searched in the published 2.4 HTML).
- §18.3.1: "A Manifest Consumer is not required to read any portion of
  assertion metadata."
- The CDDL's `$source-type` has `humanEntry`; `humanEntry.anonymous` and
  `humanEntry.identified` are "deprecated as of 2.0";
  `humanEntry.credentialed` is not defined.
- `c2pa-rs` 0.91.1 models the fields (`assertion_metadata.rs`, with
  `humanEntry.identified`) and does not enforce the rule.
- The programme's asset rubrics check it themselves
  (`asset-rubric-conformance0.1-spec2.2.yml`, `0.2-spec2.2.yml`,
  `0.2-spec2.4.yml`, with `humanEntry.anonymous` and
  `humanEntry.credentialed`), on an asset's crJSON, which this verifier
  writes with every assertion as it is (SPEC-074, SPEC-075).

## Decided

A rule on the content of a manifest, not on its validation: by design.
`docs/conformance.md`: 71 yes, 10 partial, 4 closed, 24 by design, 2
gaps (#7, #8, both parked). The issue is Maurice's to close.
