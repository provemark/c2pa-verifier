# Step 433 — External references in an ingredient manifest

*2026-10-25.* The one gap step 432 left: no test noticed when the
external-reference check (§15.10.3.2.2) stopped running on an ingredient
manifest. C2PA 2.4 §15.11.3.3.1 validates an ingredient manifest's
assertions per §15.10, so it must run there. No code changes.

## What was measured

`bin/make-ingredient-reference-variants.php` has `c2patool` 0.28.3 sign
`fixture-unsigned.jpg` with a throw-away hierarchy, with a SPEC-032 probe
as its parent ingredient (`-p`). The keys were deleted when the script
ended.

| probe | parent | ingredient record | this verifier | `c2patool` 0.28.1 / 0.28.3 |
|---|---|---|---|---|
| `ingredient-reference-control` | `reference-hashed.jpg` | no fault | `Trusted` | `Trusted` |
| `ingredient-reference-empty-url` | `reference-empty-url.jpg` | `assertion.external-reference.malformed` | `Trusted`, the code informational ("acknowledged by the signer") | `Trusted`, the code not shown |
| `ingredient-reference-unrecorded` | the same | the code changed by one letter | `Invalid`, `assertion.external-reference.malformed` in the ingredient's failures | the same |

Signing with a parent, `c2patool` validates the parent and records what
it finds in the ingredient assertion's `validationResults`. A recorded
fault is acknowledged and not counted (§18.16.12.4, SPEC-021 AC4), so the
check is only decisive when the fault is not recorded. The third probe is
that case: the recorded code changed to `…malformeX`, the ingredient
assertion's hash recomputed where the claim and the `c2pa.opened` action
record it, the actions assertion's hash recomputed, and the claim signed
again with the same throw-away key. Every byte swap was checked to fall
inside one APP11 segment.

`IngredientManifestCheckTest` AC7 pins the three verdicts, ours and the
oracle's. With the check removed from `IngredientManifestCheck::manifest()`
it fails; restored, it passes. `composer check`: 1,357 passed, 1 skipped.
Two earlier runs failed one test, `HostileInputSecondRoundTest` AC2, which
asks for under two seconds, while the machine was busy (load average near
6); the same tree passed three times after, and so did the previous commit
in a separate worktree. Recorded because it is a timing test that can fail
under load.

## What was reasoned

Nothing beyond the reading: the rule is the specification's, and both
implementations keep it.
