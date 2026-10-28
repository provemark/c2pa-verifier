# Step 500 — The release commit for 0.10.0

*2026-10-28.* Maurice: "akkoord, bereid 0.10.0 nu voor".

0.10.0 carries steps 495–499: the evidence as a document to keep
(SPEC-089, 495), the command line writing U+FFFD as SPEC-019 amendment 4
promised (496), and findings 9, 8 and 5 of the review of 0.9's code (497,
498, and 499 with SPEC-086 amendment 6 and SPEC-087 amendment 2).

**A minor, and why now.** SPEC-089 adds two contract symbols, so this is a
minor. It was meant to gather group F of the 2.4 reading and the C2PA 2.5
additions as well. Group F was already done: SPEC-088 brought three of its
eight rows to the letter in 0.9.0 and records why the other five stay.
The 2.5 additions wait until `c2patool` applies them, and nothing upstream
has moved: `c2pa-rs` 0.91.7 and `c2patool` 0.28.4 (2026-10-26) are the
latest releases, no pull request in `c2pa-rs` takes up the rows of
`docs/milestones.md`, and its `main` since 0.91.7 holds tidying and a fix
for v1 claims (read with `gh` on 2026-10-28). Waiting would hold up the
library's route to the evidence and the Drupal module's archive behind it
for an unknown time; Maurice chose to release now. The 2.5 additions will
likely need a minor of their own, since new status codes change the
contract.

What the release commit does:

- The CHANGELOG's Unreleased section becomes **0.10.0 — 2026-10-28**, with
  an introduction; one line names the two internal tidyings (497, 498).
  The README names the new tag. `Verifier::VERSION` is `0.10.0`.
- `SECURITY.md` is unchanged: no verdict moved, and no wrong `Valid` or
  `Trusted` was found or fixed in this round.

## Measured

- The corpus against `v0.9.0`: a worktree at `v0.9.0` with a copied
  `vendor/` (checked to load its own `src/`, version `0.9.0`), the current
  `tests/Fixtures/` copied once and read by both, no settings,
  `trust/full.settings.json` and the programme's lists, each without and
  with a revocation source that answers null, the validation time
  2026-10-27T12:00:00Z, the version string removed from `toJson()` and
  `toCrJson()` before hashing. 6,264 runs, compared row by row: none
  differs.
- `bin/package-check.php`: 639 files before this note, 6.1 MB in the
  dist, 2.1 MB as a zip; every top-level path classified.
  `bin/api-check.php`: the recorded surface matches (211 symbols).
- `composer check` and `vendor/bin/pest --parallel`: both exit 0.
  `bin/spec-check.php`: 89 specs.
- Found on the way, not changed: `composer check` failed three times in a
  row on this commit, each time with PHP's 128 MB limit exhausted in
  `HostileInputSecondRoundTest` (AC2, "one assertion referenced many times
  is hashed once", allocating 8.5 MB in `crc32()`). The same suite passed
  serially through `vendor/bin/pest` and `composer test`, through
  `composer check -vvv`, and then twice through `composer check`; the
  commit before (`99d12ce`) passed it too. The serial suite's peak, read
  with a shutdown function and no limit, is 88–90 MB. The margin depends
  on something outside the code — a time-limited test nearby is a
  suspect — and is a finding of its own.

Not done here: the push, the tag, Packagist, and the library, Drupal,
plugin and demo updates wait for Maurice's word.
