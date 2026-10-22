# Step 365 — An independent review of SPEC-070 amendment 2

*2026-10-22.*

Before tagging 0.7.1, Maurice asked for a thorough review of the new code
and its effect on the rest. An independent agent, given the commits and
the spec but not the author's conclusions, reviewed steps 363 and 364.

## What it found

- **Nothing that can make a file wrongly `Valid` or `Trusted`.** The
  change only removes keys from what `recorded()` returns, so it can take
  acknowledgements away, never add one. The guards (active manifest,
  update-manifest binding) run before the key lookup and are unchanged.
- **Measured by the reviewer:** `composer check` green; 747 fixture files
  verified with the filter on and off (no settings), identical outputs
  except the new `recorded-success.jpg`; the filter forced open, 5 tests
  fail; the generator re-run in a copy gives the same verdicts.
- **Two untested parts**, each changeable with the suite still green: the
  filter on `ingredientDeltas[].validationDeltas` inside a v3 record (every
  test and fixture records under `activeManifest`), and an unknown code
  being kept.
- **Reasoned from the `c2pa-rs` source:** a writer files a status in the
  list of the kind it was logged with, and for every code this verifier
  knows that kind agrees with `StatusCode`. `log_kind()` calls
  `ingredient.claimSignature.validated` and `ingredient.manifest.missing`
  success where the logging sites do not; it is used only by `c2pa-rs`'s
  own reader. One site logs `assertion.missing` as informational
  (`reader.rs`), after the point where an ingredient record is written.
  Should such a record ever appear, this verifier would read the file
  `Invalid` where `c2patool` reads `Valid`: the safe side, but a wrong
  verdict. Older `c2pa-rs` versions were not checked.
- **Wording:** AC5 said `c2patool` 0.28.2 reads both files `Valid`; with
  the settings it reads them `Trusted`.

## What changed

- Two tests in `AcknowledgedKindTest.php`: a failure filed under `success`
  and `informational` inside a record's `ingredientDeltas` is not kept,
  and a code this verifier does not know is kept in either list. Each was
  seen failing under its mutation.
- AC5's wording, and the Traceability section.
- Not changed, on purpose: the generator does not check that its scratch
  directory lies outside the repository, as none of the other generators
  do; it is tooling run by hand.

`composer check`: 1,117 passed, 1 skipped. `src/` is unchanged by this
step, so the CHANGELOG for 0.7.1 stands.

## Addendum: CI red on the push

The push of steps 363–365 failed CI on PHP 8.3, 8.4 and 8.5 (run
39830291243): AC6 called `spec070Informational()`, a helper defined in
`AcknowledgedIngredientTest.php`. `composer check` runs Pest in one process,
where that file is loaded too; CI runs `composer test:parallel`, where it
was not. AC6 now uses its own helper and the statuses' `acknowledged` mark.
Run alone, the file passes (8 tests), and `composer test:parallel` passes
locally (1,117). Before a push, run the parallel suite as CI does.
