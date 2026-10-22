# Step 364 — The release commit for 0.7.1

*2026-10-22.*

0.7.1 carries step 363: SPEC-070 amendment 2, a v3 ingredient failure
counts as acknowledged only when it was recorded under `failure`.

What the release commit does:

- The CHANGELOG gains **0.7.1 — 2026-10-22** (Changed). The README names
  the new tag.
- **A patch release**, as Maurice decided: `bin/api-check.php` still
  reports the recorded surface (136 contract symbols), and no method or
  property moved. What changes is a verdict, on files that file a failure
  in another list: `Valid`/`Trusted` in 0.7.0, `Invalid` in 0.7.1.
- The corpus against `v0.7.0` (step 363): no fixture outside
  `acknowledged-kind/` moves.
- `bin/package-check.php`: 489 files, 4.9 MB in the dist, 1.7 MB as a zip;
  against `v0.7.0` the dist gains only notes.

Not done here: the tag, the push and Packagist wait for Maurice's word.
`provemark/content-credentials` and the Drupal module are not affected by
the version range: both are still on `^0.6`.
