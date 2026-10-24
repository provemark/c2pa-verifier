# Step 411 — The graph fuzzer aims at what may be acknowledged

*2026-10-24.* After step 410, a cyclic reference can no longer be
acknowledged, and the oracle's count of predicted acknowledgements fell
from about 1,200 to about 420 per 2,000 plans. Maurice: "akkoord, begin met
de generatorverbetering", then "akkoord, bouw het zo" once the plan below
was explained. Only `bin/graph-fuzz.php` changes; nothing in `src/`.

## Measured first

The question is how often a plan's verdict rests on an acknowledgement:
every fault is acknowledged, so a wrong acknowledgement rule would turn
`Invalid` into `Valid` or `Trusted`. That was step 389's fault in 0.7.1.
Measured with an instrumented copy of the script (not committed), seed
407, 2,000 plans:

- 13,692 records. 56% aimed at a fault nothing may acknowledge (a cycle, a
  missing manifest, a box removed without a redaction), 8% at a fault of
  the active manifest, 24% at no fault, and 11% at a fault a record may
  acknowledge.
- 514 plans had an acknowledgeable fault, and in **30** the verdict rested
  on an acknowledgement.

The reason: nearly every plan held a fault that nothing may acknowledge,
mostly a cycle, since a random reference often points back up the list.

## Built

- **Tree plans.** Half the plans are trees. A manifest refers only to a
  manifest further down the list (or, in 9% of references, to none), so
  there is no cycle and no missing manifest. The other half are drawn as
  before.
- **Aimed records.** Of the records aimed at a fault, 70% draw from the
  faults some record may acknowledge (not about the active manifest, not
  unnameable), where the plan has any. The rest draw from every fault as
  before. The misfiled kind, the relative url and the holder at any level
  stay, so records that must not acknowledge are still made.

## Measured after

The same instrumented count, seed 407 and 408:

| | before (407) | after (407) | after (408) |
|---|---|---|---|
| records aimed at an acknowledgeable fault | 1,526 | 4,345 | 4,093 |
| plans with an acknowledgement | 339 | 514 | 490 |
| plans whose verdict rests on an acknowledgement | 30 | 166 | 150 |

About five times as many, less than the "several hundred" predicted
beforehand. The rest of the plans keep a fault of the active manifest or
a redaction that refuses the store.

**The instrument tested again.** The old and the new generator, the same
seeds, judged by `v0.7.1` and `v0.8.0` (worktrees with a copied
`vendor/`, removed afterwards), each WRONG run classed by the predicted
failures the verifier did not report:

| class, per 2,000 plans | old 407 / 408 | new 407 / 408 |
|---|---|---|
| `v0.7.1`: acknowledged by a manifest that may not (step 389) | 1 / 2 | 37 / 24 |
| `v0.7.1`: removed under a redaction that may not take it (step 390) | 27 / 32 | 78 / 62 |
| `v0.7.1`: an acknowledged cycle (step 410) | about 150 / 160 | about 50 / 50 |
| `v0.8.0`: an acknowledged cycle (step 410) | 73 / 74 | 25 / 21 |
| `v0.8.0`: second reference's hash (step 408) | 1 / 2 | 0 / 1 |
| `v0.8.0`: active redacted through a cycle (step 409) | 2 / 0 | 0 / 0 |

The total WRONG count fell (`v0.7.1` 208 → 182 and 212 → 152), because
the cycle class shrinks with half the plans being trees. The classes the
change aims at grew: step 389's about fifteen-fold, step 390's more than
double. The cycle-dependent classes are now found less often, and step
409's not at all in these two seeds. All of them stay in the calibration
as fixed plans, which run on every invocation.

## On the current code

Seeds 407–410, 2,000 plans each: 0 wrong, 0 stricter, 0 order, 0 faults;
calibration 0 of 31 with a finding. `composer check`: exit 0, 1,335
passed. A seed's plans differ from before this step. A plan number
replays within one version of the tool.

## Measured and reasoned

- Measured: as above.
- Reasoned: that a plan's verdict resting on an acknowledgement is the
  case in which an acknowledgement fault becomes a wrong verdict.
- Decided by Maurice: the change as proposed (50% trees, 70% aimed).
