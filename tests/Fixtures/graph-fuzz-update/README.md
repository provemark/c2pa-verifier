# Fixtures from the graph fuzzer: update manifests (step 415)

Calibration plans of `bin/graph-fuzz.php`, written out with

    php bin/graph-fuzz.php 1 0 <scratch> --calibrate-only --export tests/Fixtures/graph-fuzz-update \
        --only 'an unreached update manifest with a fault' --only 'an unreached standard manifest with two parents' \
        --only 'update below, its fault' --only 'standard: two parents' --only 'update on a standard manifest'

Signed as in `../graph-fuzz/` (a throw-away hierarchy per run, keys
outside the repository and deleted at once), with this run's own root in
`graph-fuzz-root.pem` and `graph-fuzz-root.settings.json`. Regenerating
changes the root and every signature, and the `c2patool` output under
`../c2patool/graph-fuzz-update/` with it.

| file | the plan | `c2patool` 0.28.2 and 0.27.22 (with the settings) |
|---|---|---|
| `an-unreached-update-manifest-with-a-fault.png` | A takes B as its parent; C, an update manifest of B with the action `c2pa.edited`, is named by nothing | `Trusted` |
| `an-unreached-standard-manifest-with-two-parents.png` | A takes B as its parent; C, a standard manifest with B twice as `parentOf`, is named by nothing | `Trusted` |
| `update-below-its-fault.png` | A takes B; B, an update manifest of C, has the action `c2pa.edited` | `Invalid`, `manifest.update.invalid` |
| `standard-two-parents.png` | A takes B and C, both as `parentOf` | `Invalid`, `manifest.multipleParents` |
| `update-on-a-standard-manifest.png` | A, an update manifest, takes B as its parent (the control) | `Trusted` |
