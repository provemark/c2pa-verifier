# Step 390 — SPEC-035 amendment 6: a redaction reaches only below the manifest that declares it

*2026-10-23.* The fourth finding of the review of 2026-10-23, fixed
second (after step 389), with the rule Maurice approved. Kept local, as
step 389, until a release.

## The finding

Since amendment 5, the redactions of every manifest the graph reaches
counted, and each applied to whichever manifest its URI named, the active
one included. C2PA 2.4 §6.8 makes a redaction something a manifest does
to an ingredient it takes. An ingredient B could therefore declare one of
the active manifest's assertions redacted, and its missing box would be
excused. Reasoned from the code: since step 389 B can no longer
acknowledge a change to itself, so B would have to carry the redaction
when A took it, which needs A's label beforehand. Fixed anyway, by the
same rule.

## The fix

- `ManifestGraph::below()`: the manifests reachable from one, itself
  left out even through a cycle; used by step 389's
  `IngredientManifestCheck` too.
- `ManifestStore::scopedRedactions()`: of each reached manifest's
  declared redactions, those that name an assertion of a manifest below
  it. Without a graph, none.
- `Manifest` keeps two lists: the redactions in effect (`$redacted`, which
  may excuse a missing box) and every one a reached claim declares
  (`$declared`, which `HashedUriCheck::notRedacted()` checks for content
  still there, so `assertion.notRedacted` stays as `c2patool` reports
  it).

## Measured

- Red first: the scoping function did not exist.
- The first build scoped every use of a redaction and lost
  `assertion.notRedacted` on a self-redaction still present: four
  existing tests that compare with `c2patool` failed. Hence the two lists.
- Mutations, each failing: redactions applied wherever their URI points
  (5 tests); a manifest below itself in a cycle (3).
- The corpus before and after: 0 of 1,854 runs moved.
- `composer check`: 1,258 passed, 1 skipped.
- `Manifest::fromBox()` (only `bin/make-spec035-variants.php` uses it)
  still applies a lone manifest's own redactions to itself; not on the
  verification path.
