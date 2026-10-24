# Step 408 — SPEC-021 amendment 7: the hash of every reference is checked

*2026-10-24.* The first of the two wrong verdicts the graph fuzzer found in
0.8.0 (step 407). Maurice: "eerst repareren, begin met bevinding 1", then
"akkoord, keuze A". The fuzzer runs on 0.8.0 that step 406 planned as step
408 move to after both fixes.

## The fault

An ingredient assertion records the hash of the manifest it names; it is
the signer's statement that it took exactly that manifest. Two assertions
naming one manifest make that statement twice. `IngredientManifestCheck::
check()` walked the graph's referenced manifests, each once, through the
first assertion naming it (`$urls[0]`), and compared only that assertion's
hash. A second assertion with a wrong hash was never compared: the file
was `Trusted`. C2PA 2.4 §15.11.3.3.2 checks the box hash per ingredient
assertion (as `docs/reading-c2pa-2.4.md` paraphrases it, read from the
2.4 page on 2026-10-09).

`c2patool` 0.28.2 on the step 407 calibration file: `Invalid`, the first
assertion's delta with `ingredient.manifest.validated` and the whole
manifest validation, the second's with `ingredient.manifest.mismatch`
("ingredient hash incorrect") and the whole validation again.

## The choice

A (chosen): every assertion's hash is checked, under that assertion; the
manifest itself is validated once, under the first (AC9 unchanged). B
would repeat the whole validation per assertion, as `c2patool` does, with
the same verdict and duplicated statuses. The difference is named in
`docs/comparison.md`.

## Built

- `check()`: after the first reference's hash and the manifest's
  validation, `hash()` for every further assertion naming it. `hash()`
  already picks the route per assertion: the box hash, or for a manifest
  redacted from the claim-signature hash (§15.11.3.3.1).
- Fixtures `tests/Fixtures/graph-fuzz/` (four calibration plans exported
  by a new `--export <dir> --only <plan>` option of `bin/graph-fuzz.php`,
  with the run's public root), and `c2patool` 0.28.2's JSON for each in
  `tests/Fixtures/c2patool/graph-fuzz/`. A new calibration plan, the
  redacted variant.
- SPEC-021 amendment 7 and AC12; the §15.11.3.3.2 row of
  `docs/reading-c2pa-2.4.md`; `docs/comparison.md`.

## Measured

- Red first: the two AC12 cases failed for the one missing status each
  (`ingredient.manifest.mismatch c2pa.ingredient.v3__1`,
  `ingredient.claimSignature.mismatch c2pa.ingredient.v3__1`); AC9's
  once-only case and the two controls (`one-manifest` `Trusted`,
  `ingredient-wrong-hash` `Invalid`) green before and after.
- Four mutations, each caught: the first hash checked twice (3 failed),
  the second reference skipped (2), the first ingredient's hash again (2),
  only successes kept (2).
- Corpus (`corpus-norm241.php`, every fixture without settings and with
  `full.settings.json`): 0 of 1,854 runs moved; 8 new runs, the fixtures.
- `bin/graph-fuzz.php 407 2000`: calibration 1 of 28 with a finding (the
  cycle redaction, finding 2); random plans 2 WRONG (407-359, 407-1250,
  both finding 2), 0 stricter, order or faults. 407-1440 no longer WRONG.
- `composer check`: exit 0, 1,321 passed.

## Measured and reasoned

- Measured: as above, and `c2patool` 0.28.2 on the four fixtures.
- Reasoned: that §15.11.3.3.2 is per assertion, from the paraphrase in
  `docs/reading-c2pa-2.4.md` and `c2patool`'s behaviour, not from a fresh
  reading of the specification text.
- Decided by Maurice: fix before more fuzzing; choice A.
