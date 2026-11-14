# Step 545 — 0.11.2 released

*2026-11-13.* Maurice: "tag en release v0.11.2 maar"; recorded at "leg de
0.11.2-release vast".

- `main` pushed up to `c074b44` (steps 542–544); CI green on `84c52a7`
  (run 43481550559) and on `c074b44` (run 43487209961), 8 of 8 each.
- Tag `v0.11.2` (annotated) on `c074b44`, pushed.
- **CI on the tag** (run 43488766666): 8 of 8 jobs `success` on the first attempt.
- GitHub release `v0.11.2`, its text the CHANGELOG section:
  https://github.com/provemark/c2pa-verifier/releases/tag/v0.11.2
- Packagist lists `v0.11.2`, source `c074b44`.
- A fresh `composer require provemark/c2pa-verifier:^0.11` in an empty
  directory installed `v0.11.2` from `c074b44`; `Verifier::VERSION` is
  `0.11.2`; 684 files, equal to the tag's `git archive` and to step 544's
  count with its note committed.
  - `verify()` on `fixture-signed.png` at `@253402300800`:
    `InvalidArgumentException`, "The validation time
    10000-01-01T00:00:00Z cannot be written as RFC 3339: its UTC year
    must lie within 0001–9999."
  - At `9999-12-31T23:59:59Z`: a report, and crJSON's `validationTime` is
    `9999-12-31T23:59:59Z`.
- No advisory: no verdict moves.

## Left

- Downstream: nothing has to move. The library (`^0.11`, conflict
  `>=0.12`) and the Drupal module (`^0.11`) receive 0.11.2 on their next
  `composer update`. The library's SPEC-047 refusal stays in front of
  this one.
- Of step 535's findings, the 162 files with other failure codes on the
  same verdict stay open without a spec: whether they count depends on
  whether the programme compares codes, which is not public. The 61
  files where `c2patool` gives no output need nothing.
