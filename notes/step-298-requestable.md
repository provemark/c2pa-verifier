# Step 298 — A description box without Requestable is an error (SPEC-005 amendment 2)

*2026-10-09.*

Step 297's fuzzing with trust settings found that the parser reads a JUMBF
description box whose toggles have *Requestable* (bit 0) cleared and
*Label Present* (bit 1) set. A signed file whose store, manifest,
assertion store, claim or signature box was changed that way stayed
`Trusted`. Both `c2patool` versions refuse it ("unexpected end of file").
SPEC-005 already said, from the start, that within a C2PA manifest every
description box shall have Label Present and Requestable set (C2PA 2.4
§11.1.4.1.2). `JumbfParser::description()` checked the label only.

These boxes are outside the claim signature and the assertion hashes, so
no signed byte went unchecked. An assertion's own description box is
covered by its hash; there a cleared bit already gave `Invalid`.

## What changed

- **SPEC-005 amendment 2** and a new AC18. The amendment was confirmed by
  Maurice van Loon with the step's proposal.
- **`JumbfParser::description()`** throws `JumbfException` ("Requestable
  is not set", naming the box's offset) right after the Label Present
  check, in the same way.

## Measured

- **Tests first.** `tests/Unit/Jumbf/JumbfParserTest.php` AC18: Requestable
  cleared in each description box of the PNG fixture's store in turn
  (parser), and in the store's box of the signed JPEG, PNG (CRC
  recomputed) and FLAC (verifier, under `trust/full.settings.json`).
  Before the change: 2 failed (no exception; `Trusted` where `Invalid`,
  the unchanged copies `Trusted`). After: 2 passed, 23 assertions.
- **The corpus.** Every fixture under `tests/Fixtures`, 829 files, under no
  settings and each of the 141 settings files that load, with the code
  before the change (a worktree of `240e3a5` with its own `vendor/`) and
  after. Of 117,718 runs, 0 changed state or report.
- **The fuzzer, same seeds as step 297.** `20261005 60`: the same 126
  suspects. `20261005 60 --trust`: the same 100. `20261009 200 --trust`:
  the same, less one: the FLAC case that found it (344, not 345). 0 faults
  and 0 raised throughout.
- `composer check`: 945 passed. PHPStan in Docker `php:8.3-cli`: no
  errors.
