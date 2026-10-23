# Step 372 — SPEC-006 amendment 3: maps kept as maps, on request

*2026-10-23.* Building SPEC-074 (approved the same day, with the closing
quote, refusal of tags other than 0, and floats as PHP's double) stopped
at its AC2: the CBOR decoder returns an empty map and an empty array
alike, as `[]`, and a map keyed 0, 1, 2… as a list. A crJSON mapping
cannot tell them apart afterwards. Maurice chose to give the decoder a
mode for it ("kies 1") and approved both amendments' text.

## What changed

- `CborDecoder(keepMaps: true)` returns every map as a `CborMap`, whose
  `entries` hold the keys and values in order. Arrays stay lists. The key
  rules (AC13) and every limit are unchanged.
- The default is `false`. Every check keeps decoding as before; only the
  crJSON route will use the mode (SPEC-074 amendment 1).
- SPEC-074 amendment 1: AC2 decodes with the mode; AC6 drops the key
  collision `{1: "a", "1": "b"}`, which the decoder already refuses
  (AC13: a text key that reads as an integer), and gains a guard against
  a non-list PHP array (a map decoded without the mode).

## Measured

- Why it matters: in `c2patool` 0.28.2's crJSON of 59 fixture files,
  assertions hold 0 empty objects and 29 empty arrays. Nothing real is
  wrong today; a mapping without the mode would be silently wrong the
  day an empty map occurs.
- AC16 red first (`Unknown named parameter $keepMaps`, 3 failed), then
  green. One mutation watched failing: `map()` returning the array
  whatever the mode (1 failed).
- The corpus without the mode, before (a worktree at `52378dd`, `vendor/`
  copied) and after: 1,842 runs, 0 differ.
- `composer check`: green, 1,134 passed, 1 skipped.
