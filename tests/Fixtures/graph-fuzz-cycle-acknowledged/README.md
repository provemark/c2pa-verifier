# Fixtures from the graph fuzzer: an acknowledged cycle (step 410)

Calibration plans of `bin/graph-fuzz.php`, written out with

    php bin/graph-fuzz.php 1 0 <scratch> --calibrate-only --export tests/Fixtures/graph-fuzz-cycle-acknowledged \
        --only 'cycle back to the active, acknowledged' --only 'cycle below the active, acknowledged' \
        --only 'a chain of three'

Signed as in `../graph-fuzz/` (a throw-away hierarchy per run, keys
outside the repository and deleted at once), with this run's own root in
`graph-fuzz-root.pem` and `graph-fuzz-root.settings.json`. Regenerating
changes the root and every signature, and the `c2patool` output under
`../c2patool/graph-fuzz-cycle-acknowledged/` with it.

| file | the plan | `c2patool` 0.28.2 (with the settings) |
|---|---|---|
| `cycle-back-to-the-active-acknowledged.png` | A takes B, B takes A back; A's ingredient assertion records B's `assertion.ingredient.malformed` for the cycle | refused: "cyclic ingredient found in path" |
| `cycle-below-the-active-acknowledged.png` | A takes B, B takes C, C takes B back; A records C's `assertion.ingredient.malformed` | refused: "cyclic ingredient found in path" |
| `a-chain-of-three.png` | A takes B, B takes C, no fault (the control) | `Trusted` |
