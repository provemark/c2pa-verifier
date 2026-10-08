# Step 281 — The version 1 actions label in a version 2 claim, measured (SPEC-018 amendment 6)

*2026-10-08. The one open question about a verdict that step 279 could
answer only by reading.*

## The question

SPEC-018's open question: *"`c2pa.actions` (the v1 label) inside a v2
claim — tolerated here as c2pa-rs tolerates it; if a corpus file ever
shows c2patool refusing it, an amendment follows."* "Tolerated" could
mean "skipped". If this verifier skipped the version 2 rules for such an
assertion while `c2pa-rs` applied them, a manifest opening with
`c2pa.edited` would be `Valid` here and `Invalid` there.

Read in `c2pa-rs` 0.91.1: `verify_actions` (`claim.rs`) finds actions
assertions with `assertions_by_type`, which compares `label_root()`, the
label without its version (`assertion.rs`, `assertions_eq`). Here,
`ActionsCheck::isActionsLabel()` takes both labels. Both apply the
version 2 rules to either label in a version 2 claim.

## The probes

`c2patool` 0.27.22 and 0.28.1 cannot write such a claim: signing a
manifest whose actions assertion is labelled `c2pa.actions` gives a file
whose label is `c2pa.actions.v2` (measured on four files, two per
version). So `bin/make-actions-label-variants.php` edits the PNG
fixture's store, in the shape of step 48: the label changed in the
assertion's description box and in the claim's hashed URI, the
assertion's hash, the data hash and the claim's hashed URIs recomputed,
the claim re-signed with a throw-away P-256 hierarchy, the keys deleted.

| file | 0.27.22 | 0.28.1 | this verifier |
|---|---|---|---|
| `control-resigned` (unchanged) | `Trusted` | `Trusted` | `Trusted` |
| `actions-v1-label-in-v2-claim` | `Trusted` | `Trusted` | `Trusted` |
| `actions-v1-label-first-edited` | `Invalid`, `assertion.action.malformed` | the same | the same |

All three put the fault on the manifest (`urn:c2pa:…`), as SPEC-018 AC1
says. AC7 first said "on the `c2pa.actions` assertion"; writing the test
showed otherwise, and AC7 was corrected before the test was green.

## The test seen red

`tests/Unit/Manifest/ActionsCheckTest.php`, SPEC-018 AC7: each state and
each failure code equal to both recorded `c2patool` answers; on the third
file the url equal to the oracle's and the explanation naming the
assertion. The behaviour was already right, so red was shown on the code:
with `isActionsLabel()` mutated to accept `c2pa.actions.v2` only,

```
⨯ … with dataset "c2pa.actions in a v2 claim"
⨯ … with dataset "the same, first action c2pa.edited"
-'Trusted'
+'Invalid'
Tests: 2 failed, 1 passed
```

Restored: 3 passed. `composer check`: 923 passed.
