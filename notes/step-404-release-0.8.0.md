# Step 404 — The release commit for 0.8.0

*2026-10-23.*

0.8.0 carries steps 367–403: a validation time (SPEC-073), crJSON
(SPEC-074, SPEC-075, SPEC-076), the conformance programme's trust lists
as PEM or JSON with dates (SPEC-077, SPEC-079), fixtures signed with the
programme's test certificate (SPEC-078), and the fixes from the review of
2026-10-23 (steps 389–403).

What the release commit does:

- The CHANGELOG gains **0.8.0 — 2026-10-23** (Security, Added, Changed).
  The README names the new tag. `Verifier::VERSION` is `0.8.0`.
- `SECURITY.md` records the review's five findings, four of them in
  released versions, with the versions each affected.
- **A minor release:** `bin/api-check.php` reports 142 contract symbols,
  136 in 0.7.1; the six new ones are `TrustAnchorSet::$windows` and
  `$reasons`, `TrustSettings::fromTrustLists()`,
  `VerificationReport::toCrJson()` and `$validationTime`, and
  `Verifier::VERSION`. Nothing was removed or renamed.
- The corpus against `v0.7.1` (`vendor/` copied into the worktree): 20 of
  1,842 runs move, ten files; by verdict only `c2pa-rs/video1.mp4`
  (SPEC-071 amendment 1), the other nine by the wording of a refusal. 12
  runs are new fixtures.
- `bin/package-check.php`: 542 files, 5.2 MB in the dist, 1.8 MB as a zip;
  every top-level path classified.
- `composer check`: 1,316 passed, 1 skipped.

Not done here: the push, the tag and Packagist wait for Maurice's word,
as does whether the four findings get an advisory (SECURITY.md: once the
package has users outside the project).
