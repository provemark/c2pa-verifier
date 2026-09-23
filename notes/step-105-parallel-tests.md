# Step 105 — The suite runs in parallel, and why it could not

*2026-09-23.* `pest --parallel` failed with **24 errors** while the serial
run was green. That is not a flaky suite; it is a coupling the serial
runner hides.

## Why a green suite failed in parallel

Pest loads every test file into one process. A function declared in
`DerReaderTest.php` is therefore visible from `TimeStampTokenTest.php`, and
nothing says otherwise. `--parallel` gives each worker a subset of the
files, the second file calls a function that was never declared in that
worker, and the run dies with `Error`.

Measured, the coupling was small and specific: of **190 functions** declared
across the test suite, **eight** were used from a file other than the one
that declared them, plus **one constant** of 45.

```
spec016Der              DerReaderTest.php               → TimeStampTokenTest.php
spec020Deltas           IngredientAssertionTest.php     → 4 files
spec020Failures         IngredientAssertionTest.php     → 1
spec020Multi            ManifestGraphTest.php           → 2
spec020Oracle           IngredientAssertionTest.php     → 4
spec020OracleManifest   IngredientAssertionTest.php     → 3
spec020Verify           IngredientAssertionTest.php     → 3
spec021OracleFailures   IngredientManifestCheckTest.php → 1
SPEC021_SETTINGS        IngredientManifestCheckTest.php → UpdateManifestTest.php
```

The nine now live in `tests/Shared.php`, which `tests/Pest.php` requires —
and Pest loads that bootstrap for every worker, which is what makes them
reachable. Everything else stays where it is used, which is how this suite
prefers it: open one test file and you see the whole of it.

## The constant was the part I missed

The first pass looked for functions only, moved eight, and took the parallel
run from 24 failures to four — all in `UpdateManifestTest`, all
`Undefined constant "SPEC021_SETTINGS"`. Declared in one test file, used
from another, exactly the same coupling in a different shape. Worth
recording because the analysis that found the eight was written to find
functions and answered the question it was asked rather than the one that
mattered.

## Two mistakes while moving them

The first cut took "any comment lines directly above the function" along
with it, which walked up into `DerReaderTest.php`'s **file header** and left
a dangling `* SPEC-016, AC1–AC2: …` behind in both files. The tests were
restored from git and the rule narrowed to "a complete `/** … */` block, or
nothing".

The second is smaller: `tests/Shared.php` was written before the removal
succeeded, so for a moment every helper was declared twice. Serial and
parallel both fail loudly on that, which is the right kind of failure.

## What it buys

```
serial     7.35s
parallel   2.62s
```

`composer test:parallel` is the fast loop while working. **`composer check`
stays serial on purpose**: it is the definition of green, the drift alarms
print in order there, and five seconds is not worth the determinism.

## What would let this rot again

Nothing runs `--parallel` automatically. The next helper that a second file
reaches for will break it again, silently, exactly as this one did — the
suite went green on every push while parallel was broken. A CI job that
runs it costs 2.6 seconds and is the only thing that would hold the
property; it is not added yet, and that is a decision for the maintainer
rather than an oversight.
