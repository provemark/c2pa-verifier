# Step 253 — `storeReached` in one place

*2026-10-06. The first of three clean-up steps the reviews of step 243 and
247 named; none changes what the verifier says.*

**What.** When a container extractor fails, the fault says whether the
manifest store had been reached, and the verifier reports `has_manifest`
from it (SPEC-013 amendments 16 to 18). Each of the five extractors (JPEG,
PNG, ISOBMFF, RIFF, ID3) turned what its walk had seen into that flag with
the same line, and the RIFF extractor once more with `false`:

```php
throw $e->storeReached === $reached ? $e : new ContainerException($e->getMessage(), previous: $e, storeReached: $reached);
```

Now `ContainerException::withStoreReached(bool $reached)` holds the rule —
the same fault when it already says so, else a new one with the same
message and the old one as its cause — and the six sites call it. A new
extractor (GIF, TIFF) no longer copies the line, and forgetting it is no
longer the easy mistake. The class is `@internal`; the recorded API surface
is unchanged.

**Why not a callback.** The review of step 243 proposed
`ContainerException::tracking(callable $walk)`. The extractors pass their
`$reached` by reference into walks of different shapes (the RIFF walk takes
five more arguments), so a method on the fault is the simpler of the two.

## Measured

- `tests/Unit/Container/ContainerExceptionTest.php` red first (`Call to
  undefined method … withStoreReached()`), then green.
- `composer check`: exit 0, 803 tests.
- The corpus against step 249: 0 of 1,400 measurements moved.
- `bin/fuzz.php 20261005 60` over the release set: 16,041 runs, 0 faults,
  the same 118 files `Valid` as in step 249.

One difference is by design and invisible in a report: where the RIFF
extractor could not measure its stream, it always wrapped the fault anew;
now it returns the fault itself when that already says "not reached". The
message and the flag are the same.
