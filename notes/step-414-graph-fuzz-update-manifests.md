# Step 414 — Update manifests in the graph fuzzer

*2026-10-24.* The graph fuzzer of steps 407–411 built only standard
manifests (`c2ma`). Update manifests (`c2um`, C2PA 2.4 §11.2.3) are
graph logic of the same kind as the faults it found: an asset whose
active manifest is an update manifest is bound by a manifest up a chain
of `parentOf` references (§15.12). The security review of 2026-09-25
found two wrong verdicts there (steps 149 and 150). Maurice approved the
extension ("akkoord, begin met step 414"). No code in `src/`.

## What was added

To the plan and the builder of `bin/graph-fuzz.php`:

- **Update manifests.** About a quarter of the manifests (30% of active
  ones) are written as a `c2um` box: no hard binding, the action
  `c2pa.edited.metadata`, and mostly one `parentOf` ingredient. In a tree
  plan the last manifest is a standard one, and an update manifest's
  parent there is always a manifest of the store, so that most chains
  end in a binding.
- **Faults against §11.2.3**: a hard binding of its own (`uhash`), the
  action `c2pa.edited` (`uaction`), no ingredient, only a `componentOf`
  one, or a second ingredient beside the parent.
- **Faults against §15.10.1.2**: a standard manifest with a second
  `parentOf` now and then.
- **Every manifest's `c2pa.hash.data` states the hash of this file.**
  Only the active standard manifest's exclusion has the store's length;
  every other one is written for a smaller store, as when an update
  manifest was appended later. It binds this file only once the
  exclusion is adjusted to the store as it is (§15.12.1.1), which only an
  active update manifest does (SPEC-022 amendment 6).

To the oracle:

- `gfBinding()`: the binding manifest. The active manifest when it is a
  standard one with a binding, else the first such manifest up the
  `parentOf` chain, each manifest following its last `parentOf` that
  names a manifest; none when the chain ends, leaves the store or comes
  round. An active update manifest without one is
  `claim.hardBindings.missing` on its claim.
- The §11.2.3 rules and §15.10.1.2 on the claim of every manifest in the
  store, with c2pa-rs's codes (`manifest.update.invalid`,
  `manifest.update.wrongParents`, `manifest.multipleParents`).
- The failures of the binding manifest, where that is not the active
  one, are never acknowledged (SPEC-021 amendment 6).

Calibration: 16 new fixed plans (47 in all). Among them two plans that
must be `Trusted` (an update manifest on a standard one, two in a chain).
If either is not, the run stops. And the shapes of steps 149 and 150.

`gfJudge()` now also judges checkouts older than the `acknowledged` flag
on a status. Before that flag, an acknowledged status was left out of
the list.

## What the first run showed

One calibration plan was `STRICTER`: an update manifest that nothing
references, with an action §11.2.3 does not allow. This verifier reports
`manifest.update.invalid` on it, as SPEC-022's Scope says ("run on every
manifest in the store"). `c2patool` 0.28.2 says `Trusted`: it checks only
the manifests it reaches. The oracle was written to follow SPEC-022, and
the difference is noted here. It is stricter, not a wrong `Valid`. Whether
it should stay (ADR-0005: stricter only where it protects a verdict) is a
question for its own step.

The other new plan whose codes differ from the prediction is the step-150
shape. The oracle predicts only `Invalid` there, and the verifier gives
`assertion.dataHash.mismatch`, as SPEC-022 AC10 says.

## The instrument tested

The same calibration and seed 414 (2,000 plans), judged by `v0.2.1`
(before the security review's fixes) and `v0.2.2` (the security release),
from worktrees with a copied `vendor/`, removed afterwards:

| | `v0.2.1` | `v0.2.2` |
|---|---|---|
| calibration plans with a finding | 12 of 47 | 10 of 47 |
| random plans WRONG | 288 | 219 |
| WRONG on `v0.2.1`, not on `v0.2.2` | 69 | — |
| WRONG on `v0.2.2`, not on `v0.2.1` | — | 0 |

The two calibration plans that `v0.2.2` no longer gets wrong are those of
steps 149 and 150. Of the 69 random plans fixed in `v0.2.2`:

| plan shape | plans |
|---|---|
| active update manifest | 44 |
| active standard manifest without a binding, a `c2um` in the store (step 150) | 5 |
| active standard manifest, a `c2um` in the store | 7 |
| no `c2um` | 13 |

The generator finds step 150's class (a borrowed binding widened because
a `c2um` sat in the store) five times per 2,000, without being written for
it. The 44 with an active update manifest fit step 149's fix (the
binding manifest's failures never acknowledged). `v0.2.2` carried all 13
fixes of that review, so not every one of the 69 belongs to these two
steps. The remaining WRONG on both versions are the later classes (steps
389, 390, 408–410), which the calibration also shows.

## On the current code

Seeds 414–416, 2,000 plans each: 0 wrong, 0 stricter, 0 order, 0 faults.
Calibration 0 of 47 with a finding. Per 2,000 plans of seed 414 (counted
with an instrumented copy, not committed):

| | plans |
|---|---|
| with an update manifest | 1,120 |
| active update manifest | 553 |
| of which the binding is found | 305 |
| of which the verdict is not `Invalid` | 76 |
| a binding borrowed from another manifest | 324 |
| `manifest.update.invalid` predicted | 215 |
| `manifest.update.wrongParents` predicted | 211 |
| `manifest.multipleParents` predicted | 99 |
| `claim.hardBindings.missing` predicted | 248 |
| the step-150 shape | 25 |

The plans of a seed differ from before this step. A plan number replays
within one version of the tool.

`composer check`: exit 0, 1,335 passed, twice. A first run had one failure
in `HostileInputSecondRoundTest` "AC2" (0 hashed-URI statuses where 1,000
were expected). The test passes alone, four times out of four, with and
without this step's change, and in the two full runs after. Seen once and
not investigated. `src/` is unchanged in this step.

## Not in this step

- A thumbnail in an update manifest (§11.2.3). It is an embedded-file box,
  not CBOR, and the builder writes only CBOR assertions.
- Duplicate manifest labels, v2 ingredients with `validationStatus`, the
  `c2pa.time-stamp` assertion across manifests: each its own step.

## Measured and reasoned

- Measured: the calibration and seeds 414–416 on this tree; the
  calibration and seed 414 on `v0.2.1` and `v0.2.2`, the WRONG plans
  compared; `c2patool` 0.28.2 on the unreached-update-manifest plan; the
  counts above; `composer check`.
- Reasoned: that the 44 active-update plans belong to step 149 (from the
  shape, not from bisecting the 13 fixes).
- Decided by Maurice: the extension as explained; calibration against an
  old checkout.
