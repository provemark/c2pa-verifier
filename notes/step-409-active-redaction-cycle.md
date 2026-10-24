# Step 409 — SPEC-035 amendment 7: no redaction of the active manifest, through a cycle either

*2026-10-24.* The second of the two wrong verdicts the graph fuzzer found
in 0.8.0 (step 407). Maurice chose the narrow fix ("akkoord, keuze 1"):
hold step 390's rule where it was not held, and leave the question of
acknowledging a cyclic reference to a step of its own.

## The fault

SPEC-035 amendment 6 (step 390) says a redaction applies only below the
manifest that declares it, and never to the active manifest.
`ManifestStore::scopedRedactions()` enforced the second half only through
`ManifestGraph::below()`. That walk follows a cycle, so a reached
ingredient B whose references lead back to the active manifest A has A
below it. B could then declare an assertion of A redacted, and A's box
could be removed. The cycle itself is `assertion.ingredient.malformed`,
and a record of that status in A could acknowledge it (SPEC-070), so the
store came out `Trusted` with an assertion of the active manifest gone.
`c2patool` 0.28.2 refuses the store ("cyclic ingredient found in path",
exit 1).

## Built

- `scopedRedactions()` takes the active manifest out of each declaring
  manifest's set before matching. The removed box is then `assertion.missing`
  and the store is refused, as for any box removed without a redaction
  that may take it. A redaction of a non-active manifest that is reached
  only through a cycle still applies, because the declaring manifest is
  above it.
- Fixtures `tests/Fixtures/graph-fuzz-cycle/` (two calibration plans,
  exported with `bin/graph-fuzz.php --export`), with `c2patool` 0.28.2's
  output in `tests/Fixtures/c2patool/graph-fuzz-cycle/`.
- SPEC-035 amendment 7 and AC12.

## A correction to the instrument

Seed 409 gave two STRICTER runs (409-1339, 409-1796): predicted `Trusted`,
got `Invalid` with `assertion.selfRedacted`. They came out the same on the
code before this step, so the change did not cause them. In both, a
reached manifest lists its own probe as redacted while its parent redacts
the same box legitimately, so the store is read and the claim's
self-redaction is reported (SPEC-035 AC4). The oracle never predicted
`assertion.selfRedacted`. It now does, and a calibration plan holds the
case. `c2patool` 0.28.2 on that plan: `Invalid`, `assertion.selfRedacted`
in the ingredient's delta.

The records of a random plan are drawn from the faults the oracle
predicts, so after this correction some plans of seeds 408 and 409 differ
from before. A plan number replays within one version of the tool.

## Measured

- Red first: the three cycle cases of AC12 and the end-to-end cycle file
  failed (4 tests); the non-active redaction through a cycle and
  `redaction-by-the-parent` (`Trusted`) were green before and after. Run
  again against the old `ManifestStore.php` after the test rewrite: 4
  failed.
- Three mutations, each caught: the declaring manifest left out instead of
  the active one (4 failed), nothing left out (4), the first manifest below
  left out (6).
- Corpus (`corpus-norm241.php`): 0 of 1,854 runs moved against step 408's
  run; 4 new runs, the fixtures.
- `bin/graph-fuzz.php`, 2,000 plans each, seeds 407, 408, 409 and 410: 0
  wrong, 0 stricter, 0 order, 0 faults; calibration 0 of 29 with a
  finding. Seed 409 run twice gives the same counts.
- `composer check`: exit 0, 1,327 passed.

## Measured and reasoned

- Measured: as above, and `c2patool` 0.28.2 on the fixtures and on the new
  calibration plan.
- Reasoned: that the cycle case needs B's author to know A's label in
  advance (as for amendment 6), so it is hard to use. It is still a
  verdict the rules do not allow.
- Not changed: a cyclic store is read here and reported with
  `assertion.ingredient.malformed`, where `c2patool` refuses it.
- Decided by Maurice: choice 1, the narrow fix; the acknowledgeability of a
  cyclic reference is a separate step.
