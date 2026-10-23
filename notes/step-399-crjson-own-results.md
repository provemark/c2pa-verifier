# Step 399 — SPEC-075 amendment 3: a manifest's own crJSON results follow the checker's scope

*2026-10-23.* A low item of the review of 2026-10-23.

## The finding

`CrJsonDocument::ownResults()` gave an ingredient manifest the delta of
the first ingredient assertion, in store order, that named it and had a
delta. The checker (`IngredientManifestCheck::check()`) scopes the
manifest's own statuses under its first reference in walk order. A
manifest named by two parents, with the store listing the later one
first, could show the other parent's delta as its own results. The
reviewer could not trigger it with the fixtures.

## The fix

`ownResultsOf($graph, $byUri)` takes the delta of
`$graph->referenced[$label][0]`, and none where that reference has none.

## Measured

- Red first: the first build's logic, moved unchanged into
  `ownResultsOf()`, gave P2's delta where the checker scopes under P1.
  Green after.
- The crJSON of every fixture before and after, at a fixed `--at`: 0 of
  927 documents moved.
- `composer check`: 1,311 passed, 1 skipped.
