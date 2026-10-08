# Step 276 — Only the active manifest's own signer makes a file `Trusted` (SPEC-014 amendment 6)

*2026-10-08. Reported privately together with amendment 5, as a related
point. Not reachable in any release; a rule that leaned on another part of
the code now holds on its own.*

## What the rule was

`ValidationResult::fromStatuses()` set the state to `Trusted` when any
status was `signingCredential.trusted` and nothing failed, whatever its
scope. A status found while walking an ingredient (its `ingredientUri`
set, SPEC-020) counted as much as the active manifest's own.

`c2pa-rs` 0.91.1 (`validation_results.rs`, `validation_state`) asks for
`signingCredential.trusted` in the active manifest's success list. An
ingredient's does not count.

## Why it was not reachable (reasoned from the code)

With `verify_trust` on, `Verifier` always runs `ChainCheck::check()` on
the active manifest (`Verifier.php`, the trust block), and that check
always reports `trusted`, `untrusted` or a failure. `untrusted` keeps the
state from `Trusted`, and a failure makes it `Invalid`. With `verify_trust`
off, neither the active manifest nor `IngredientManifestCheck` reports
trust. So an ingredient's `trusted` never stood alone. The rule depended
on that.

## The amendment and the test seen red

SPEC-014 amendment 6, confirmed by Maurice van Loon the same day: only a
`signingCredential.trusted` without an `ingredientUri` makes the state
`Trusted`. AC9 gains one case.

`tests/Unit/Trust/ChainCheckTest.php`, *"AC9: only the active manifest's
own trusted makes the state Trusted (amendment 6)"*: `claimSignature.validated`
for the active manifest and `signingCredential.trusted` for an ingredient
only. Red before the change:

```
-ValidationState Enum (Valid, 'Valid')
+ValidationState Enum (Trusted, 'Trusted')
Tests: 1 failed, 1 passed
```

## Built and measured

One condition in `fromStatuses()`. `composer check`: 919 passed, PHPStan,
Deptrac, Pint and the spec check clean. Every file under `tests/Fixtures`
(729) under no settings and the 63 settings files, against the run of
step 275 on the code before this change: 0 of 45,927 reports differ, as
reasoned above.
