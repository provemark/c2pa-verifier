# Step 389 — SPEC-070 amendment 3: a record speaks only for what lies below it

*2026-10-23.* The first fix from the review of 2026-10-23 (four
reviewers over `v0.7.1..HEAD` and the whole of `src/`). Approved by
Maurice, with the order of the fixes and the quiet approach of
`SECURITY.md`: these commits stay local until a release.

## The finding

`IngredientManifestCheck::recordedInStore()` pooled the recorded
statuses of every ingredient assertion of every reached manifest, and
`acknowledge()` matched a status against the pool, whoever had recorded
it. A manifest could therefore acknowledge a status about itself: an
altered ingredient B, whose own ingredient assertion records B's
`claimSignature.mismatch` and its parent's `ingredient.manifest.mismatch`
of B, made the file `Valid`, or `Trusted`. Confirmed with the real classes
(`Invalid` before the acknowledgement, `Valid` after). `c2pa-rs` 0.91.1
pools the same way (`validation_results.rs`, `from_store()`, read, not
run).

## The fix

A record acknowledges a status about manifest X only when the manifest
holding it is a proper ancestor of X in the ingredient graph (C2PA 2.4
§18.16.12.4: a statement by the manifest that takes the ingredient,
about it). `recordedInStore()` returns each record's key with the
manifests below its holder (`below()`, the holder left out even through a
cycle); `acknowledge()` asks `recordedBelow()`. A status whose url names
no manifest is never acknowledged.

## Measured

- Red first: 5 of 8 new tests (every case where a record spoke for its
  own manifest or one above it); the three allowed cases passed.
- Mutations: the old pooling (4 failed); a url without a manifest
  acknowledged (2 failed); the holder below itself, which survived until
  two cycle cases (B names itself; B and C name each other) were added,
  then failed.
- The corpus before and after: 0 of 1,854 runs moved;
  `adobe-20220124-CIE-sig-CA.jpg` and the other acknowledged files keep
  their acknowledgements.
- `composer check`: 1,250 passed, 1 skipped.
