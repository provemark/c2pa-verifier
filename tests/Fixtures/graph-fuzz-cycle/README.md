# Fixtures from the graph fuzzer: a redaction and a cycle (step 409)

Calibration plans of `bin/graph-fuzz.php`, written out with

    php bin/graph-fuzz.php 1 0 <scratch> --calibrate-only --export tests/Fixtures/graph-fuzz-cycle \
        --only 'redaction of the active through a cycle' --only 'redaction by the parent'

Signed as in `../graph-fuzz/` (a throw-away hierarchy per run, keys
outside the repository and deleted at once), with this run's own root in
`graph-fuzz-root.pem` and `graph-fuzz-root.settings.json`. Regenerating
changes the root and every signature, and the `c2patool` output under
`../c2patool/graph-fuzz-cycle/` with it.

| file | the plan | `c2patool` 0.28.2 (with the settings) |
|---|---|---|
| `redaction-by-the-parent.png` | the active manifest A takes B and redacts B's probe assertion, whose box is removed | `Trusted` |
| `redaction-of-the-active-through-a-cycle.png` | A takes B; B takes A back (a cycle) and redacts A's probe assertion, whose box is removed; A's ingredient assertion records B's `assertion.ingredient.malformed` for the cycle | refused: "cyclic ingredient found in path" (exit 1, no report) |
