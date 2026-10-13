# Step 361 — The release commit for 0.7.0

*2026-10-13.*

0.7.0 carries steps 349–360:

- SPEC-068: a data hash over nothing is a mismatch.
- SPEC-069 and its amendment 1: where a data-hash exclusion may lie, and
  the note that those bytes are unsigned.
- SPEC-070: acknowledged ingredient failures are reported.
- SPEC-071: which boxes a BMFF hash may exclude.
- SPEC-072: Pixel Camera video.

What the release commit does:

- The CHANGELOG gains **0.7.0 — 2026-10-13** (Security, Added, Changed),
  with the warning for callers who use `isFailure()` on
  `result->statuses`. The README names the new tag.
- **A minor release.** `bin/api-check.php` reports 136 contract symbols
  (`ValidationStatus::$acknowledged` added). `provemark/content-credentials`
  requires `^0.6.1` and conflicts with `>=0.7`, and the Drupal module
  requires `^0.6`. Neither takes 0.7.0 until it is updated. That is their
  own step.
- **The corpus against `v0.6.1`:** a worktree at the tag, with `vendor/`
  copied, not symlinked (it loads its own `src/`). Over 793 fixture files
  in two modes (no settings, `trust/full.settings.json`), outside the new
  fixture directories, **no state differs**. 40 files change in codes or
  data-hash explanations: the acknowledged lines (SPEC-070), the Pixel 10
  informational, the two `binding/` extras and `bmff/xpath-nested.mp4`,
  all named in their steps.
- CI on `bd29cb5` (step 360): 8/8 green. `composer check`: 1,109 passed,
  1 skipped. `bin/package-check.php`: 486 files, 4.9 MB in the dist, 1.7 MB
  as a zip.

Maurice gave the word to release 0.7.0 once CI was green.
