# Step 149 — Only a validated manifest may acknowledge a fault (SPEC-021 amendment 6)

*2026-09-25. Found by the security review of the same day. A wrong
`Valid` or `Trusted`, present in 0.1.0, 0.2.0 and 0.2.1.*

## The flaw

An ingredient assertion may record the validation results its writer saw
for the ingredient. A fault it recorded is not reported again: that is how
a writer acknowledges a known problem in what it imported (C2PA 2.4
§15.11.3.3). Amendment 4 made the set of acknowledged faults the store's,
not one assertion's, because a v3 assertion records the whole tree it
validated.

That set was built from every ingredient assertion in the store. It
included manifests that the ingredient graph never reaches, and that are
therefore never validated. Such a manifest vouches for nothing, but what
it recorded could still cancel a real fault of a manifest that is
validated. That includes the fault showing that an update manifest's
asset no longer matches the hard binding it borrows from its parent.

## 149a — the tests seen red

`tests/Unit/Verifier/IngredientManifestCheckTest.php`, two tests for the
new AC11, on synthetic graphs through `ManifestGraph::fromIngredients()`.
No crafted file is committed.

- *An ingredient assertion of a manifest the graph never reaches records
  nothing.* Run first against the new signature it failed on a
  `TypeError`; that does not count. Run against the old call
  (`recordedInStore($graph->ingredients)`) it failed on the behaviour:
  the unreached manifest's `assertion.hashedURI.mismatch` was in the set
  next to the reached manifest's `ingredient.unknownProvenance`.
- *A fault of the update manifest's binding manifest is never dropped;
  another manifest's still is.* Failed: the binding manifest's
  `assertion.hashedURI.mismatch` was dropped (`[]` where one status was
  expected).

## 149b — built

- `IngredientManifestCheck::recordedInStore()` now takes the
  `ManifestGraph` and reads the ingredient assertions of the active
  manifest and of the manifests the graph reaches, nothing else.
- `drop()` takes the label of the binding manifest of an update manifest
  (null without an update manifest) and never drops a *failure* whose url
  lies inside it. Informational lines of that manifest, such as a
  recorded `ingredient.unknownProvenance`, are still dropped as before,
  so `update_manifest.jpg` keeps `c2patool`'s report.
- `Verifier` passes `UpdateManifestCheck::bindingManifest()`'s label when
  the store holds an update manifest.

Measured:

- `vendor/bin/pest --group=SPEC-021`: 15 passed.
- `composer check`: exit 0, 512 passed.
- **Every signed fixture under no settings and under every settings file
  in `tests/Fixtures/trust/` and `tests/Fixtures/*/`, 18,850 runs,
  before and after: 50 moved.** They are exactly the review's probe under
  its 50 settings, all to `Invalid`; it is kept outside the repository.
  No fixture in the repository changed state or status list.

**Weight A:** a file that was wrongly `Valid` or `Trusted` becomes
`Invalid`.

## Disclosure

The fix is local until the other findings of the review are fixed too.
They go out together as a security release. Pushing a probe or a test
before then would publish the flaw.
