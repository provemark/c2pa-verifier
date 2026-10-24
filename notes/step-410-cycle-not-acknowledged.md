# Step 410 — SPEC-070 amendment 4: no record speaks for a cycle

*2026-10-24.* The question step 409 left open: may a cyclic reference's
`assertion.ingredient.malformed` be acknowledged at all? Maurice: "akkoord,
begin met keuze 2", that is no, with a corpus measurement first.

## Why

A reference that leads back into the path of the walk is a cycle.
SPEC-020 reports it as `assertion.ingredient.malformed` on the assertion
that closes it, and the walk does not follow it. Under SPEC-070
amendment 3, a record in a manifest above that assertion could
acknowledge it. The store then came out `Valid` or `Trusted`, and step
409's redaction of the active manifest used exactly that route.
`c2patool` 0.28.2 refuses every cyclic store ("cyclic ingredient found in
path", exit 1, no report).

C2PA 2.4 §18.16.12.4 lets a claim generator acknowledge validation errors
"in the ingredient's C2PA Claim". A cycle is not such an error. It is the
shape of the whole store, and the acknowledging manifest's own reference
is part of it.

## Measured first

`cyclic-scan.php` (scratch): every fixture under `tests/Fixtures`, without
settings, with `trust/full.settings.json` and with every
`*.settings.json` in the fixture's own directory. Result: 7,159 runs, 0
cyclic statuses. No existing file can move.

## Built

- `ManifestGraph` gains `$cyclic`: the urls of the assertions whose
  reference closes a cycle, collected where the walk reports the status.
- `IngredientManifestCheck::recordedInStore()` leaves out every record of
  `assertion.ingredient.malformed` on one of those urls. The rule sits
  where the records are collected, so `acknowledge()` and its caller in
  `Verifier` are unchanged, and no caller can forget it.
- A malformed assertion that closes no cycle (one this verifier could not
  decode) is still acknowledgeable. So is any other fault in a cyclic
  store, under amendment 3.
- The graph-fuzz oracle: a cyclic fault can no longer be acknowledged.
  Two calibration plans were added, exported with a control as
  `tests/Fixtures/graph-fuzz-cycle-acknowledged/`, with `c2patool`
  0.28.2's output.
- SPEC-070 amendment 4 with AC12 and AC13; a row in `docs/comparison.md`.

## Measured

- The oracle first. With the cyclic fault unacknowledgeable, the
  unchanged verifier was WRONG on both new calibration plans (`Trusted`)
  and on 61 to 72 of every 2,000 random plans (seeds 407–410). The number
  of acknowledgements the oracle predicts fell from about 1,200 to about
  420 per seed: most acknowledgements the random plans drew were of
  cycles. The fuzzer therefore exercises acknowledgement much less from
  now on, and a generator that aims records at other faults would win
  that back.
- Red first: 5 tests failed (the graph's `cyclic` missing, the record
  acknowledging, both fixtures `Trusted`); the controls passed. After the
  test rewrite for PHPStan, 6 failed without the fix (the non-cyclic
  control too, since it reads `cyclic`).
- Four mutations: the skip removed (4 failed), the wrong code in the key
  (4), the graph naming no cycle (5), and every malformed record skipped.
  The last one first survived. A test that a non-cyclic malformed
  assertion is still acknowledged was added, and then it failed (1).
- Corpus (`corpus-norm241.php`): 0 of 1,866 runs moved against step 409's
  run; 6 new runs, the fixtures.
- `bin/graph-fuzz.php`, 2,000 plans each, seeds 407–410: 0 wrong, 0
  stricter, 0 order, 0 faults; calibration 0 of 31 with a finding.
- `composer check`: exit 0, 1,335 passed.

## Measured and reasoned

- Measured: as above, and `c2patool` 0.28.2 on the fixtures.
- Reasoned: that §18.16.12.4's "errors in the ingredient's C2PA Claim"
  does not cover a cycle, which no single claim holds.
- Decided by Maurice: choice 2, with the corpus measured first.
