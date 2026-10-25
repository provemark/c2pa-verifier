# Step 425 — The reading of C2PA 2.4 brought up to date

*2026-10-25.* Maurice wants to know that all of C2PA 2.4 is implemented
before 2.5 is taken on. The source for that is
`docs/reading-c2pa-2.4.md`, which lists every normative sentence a
validator has to meet. This step only brings it up to date; sorting what
is left is the next step.

## Why it was out of date

The rows were kept up to date until step 338. The steps after it (SPEC-068
to SPEC-073, SPEC-079, SPEC-035 amendments 6 and 7, SPEC-007 amendment 9,
SPEC-062 amendments 1 and 3) changed the verifier without touching the
document: none of them was named in it. Step 325 had also left rows that
repeat a rule closed elsewhere. One example: the §15.4.1 row still said a
BMFF hash without `alg` falls back to SHA-256, which step 317 had fixed.

## What was done

- Every row whose verdict was not *covered*, *by design* or *n/a* (74
  rows), and every row in an area a later spec touched, was read against
  the code, not only against the notes.
- 38 rows changed, 19 of them in the verdict column. Among them: the
  manifest-label, generator-info and timestamp-token rows closed by steps
  316 to 318; label 33 in both headers (step 324); the dispatch of cloud
  data, time-stamp and alternative-content assertions (SPEC-063 to
  SPEC-065); the time-stamp assertion's token (SPEC-064); the exclusions a
  data hash and a BMFF hash may make (SPEC-068, SPEC-069, SPEC-071); indexed
  `xpath` steps (SPEC-072); dated trust-list anchors (SPEC-079); the
  acknowledged ingredient failures (SPEC-070); the redaction scope
  (SPEC-035 amendments 6 and 7). Where a rule stays partial, the text now
  says what is left: a BMFF hash with no `alg` on either side still falls
  back to SHA-256 rather than `algorithm.unsupported`.
- Each new *covered* names a test. Every cited test was checked to exist
  in the file named; three were in another file than first written, and
  were corrected.
- The status codes were measured again: the 2.4 code table extracted from
  the HTML (115 codes; two review-rating codes of §18.3.5 Table 6 left out)
  and compared with the enum with `comm`. 68 of 115 are held now (59 on
  2026-10-09); the 47 missing are listed by reason. The page's SHA-256 has
  changed since 2026-10-09 (`507b8880…`); its code table has not.

## The tally

| | rules | covered | partial | by design | n/a | candidate |
|---|---|---|---|---|---|---|
| 2026-10-09 (as written) | 562 | 310 | 63 | 79 | 101 | 8 |
| 2026-10-25 (counted by script) | 563 | 327 | 53 | 77 | 98 | 7 |

The 2026-10-09 table was off by one against its own rows; the script
counts the rows as they stand.

## What this does not settle

- The 53 partial rows and 7 candidates are not yet sorted into "may give a
  wrong `Valid`", "stricter than `c2patool`", "by design" and "to build".
  That is the next step.
- The 327 covered rows were not re-checked here, beyond those this step
  rewrote. Step 312 checked 10 of them by hand.
- The pack notes under the *Candidates* headings are kept as written on
  2026-10-09.

Documentation only; no code, no tests changed.
