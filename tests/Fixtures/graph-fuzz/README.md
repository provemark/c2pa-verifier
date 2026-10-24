# Fixtures from the graph fuzzer (step 408)

Calibration plans of `bin/graph-fuzz.php` (step 407), written out with

    php bin/graph-fuzz.php 1 0 <scratch> --calibrate-only --export tests/Fixtures/graph-fuzz \
        --only 'one manifest' --only 'ingredient: wrong hash' \
        --only 'ingredient: second reference, wrong hash' \
        --only 'redacted ingredient: second reference, wrong hash'

Every manifest is a claim v2 signed with a throw-away P-256 hierarchy made
for that run (keys outside the repository, deleted at once); the public
root is `graph-fuzz-root.pem`, and `graph-fuzz-root.settings.json` trusts
it with `trust/store.cfg`. Regenerating changes the root and every
signature, so the `c2patool` JSON under `../c2patool/graph-fuzz/` is
regenerated with them.

| file | the plan | `c2patool` 0.28.2 (with the settings) |
|---|---|---|
| `one-manifest.png` | one manifest, no fault | `Trusted` |
| `ingredient-wrong-hash.png` | the active manifest names one ingredient manifest, with a wrong `activeManifest` and `claimSignature` hash | `Invalid`, `ingredient.manifest.mismatch` under `c2pa.ingredient.v3` |
| `ingredient-second-reference-wrong-hash.png` | the active manifest names the same ingredient manifest twice; the first reference's hashes are right, the second's wrong | `Invalid`, `ingredient.manifest.mismatch` under `c2pa.ingredient.v3__1` only |
| `redacted-ingredient-second-reference-wrong-hash.png` | the same, and the active manifest redacts an assertion of the ingredient manifest, so both references are checked by the claim-signature hash (C2PA 2.4 §15.11.3.3.1) | `Invalid`, `ingredient.claimSignature.mismatch` under `c2pa.ingredient.v3__1` only |
