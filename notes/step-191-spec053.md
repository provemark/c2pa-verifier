# Steps 190–191 — SPEC-053 built: a BMFF hash's exclusions are bounded

*2026-09-30. SPEC-053, drafted in step 189 and approved the same day,
built tests-first. PHP 8.5.8.*

## Why

`BmffHashCheck::plan()` turns a BMFF hash assertion's exclusions into the
ranges the digest covers:

- every exclusion that matches a box adds ranges to one list;
- then `remaining()` walks that list once for every top-level box.

So the cost grows with boxes × boxes × exclusions. There was no limit on
exclusions, although `DataHashCheck` has one (1024). The hash runs even
when the assertion no longer matches the claim, so no key is needed.

## The tests, red first (step 190)

`tests/Unit/Hash/BmffExclusionLimitsTest.php` builds every variant in
memory from `fixture-signed.mp4` (and the fragmented `init.mp4`):

- the BMFF hash assertion is re-encoded with other exclusions;
- every enclosing JUMBF box and the top-level `uuid` box are resized;
- top-level `free` boxes are appended where needed.

The claim is not signed again. A support test shows that the builder,
given the file's own exclusions, gives back a file the verifier reads
with `assertion.bmffHash.match`.

Writing the tests showed that SPEC-053's "4096 appended boxes" cannot be
built: the extractor refuses more than 4096 boxes in all, and the file
has 29. Amendment 1, confirmed the same day:

- AC3 uses 4000 appended boxes × 8 exclusions, which is 32,008 ranges.
- AC4 reaches exactly 4096 ranges in two ways: 2048 boxes × 2 subsets,
  and 64 exclusions × 64 subsets.

Before the change, `vendor/bin/pest --group=SPEC-053`: **4 failed,
3 passed**.

| test | before |
|---|---|
| support: the builder | passed |
| AC1: 65 exclusions | no `malformed` |
| AC2: 65 subsets | no `malformed` |
| AC3: 32,008 ranges | no `malformed`, and **11.4 s** through `Verifier::verify()` |
| AC4: at the limits (a guard) | passed, 0.77 s and 0.02 s |
| AC5: fragmented, 65 exclusions | no `malformed` |

AC3 is the end-to-end measurement the spec had only reasoned: a keyless
MP4 of about 50 KB holds a verification for 11 seconds.

## What changed (step 191)

- **`BmffHashCheck`** gets three limits: `DEFAULT_MAX_EXCLUSIONS` 64,
  `DEFAULT_MAX_SUBSETS` 64 and `DEFAULT_MAX_RANGES` 4096. They can be set
  through the constructor.
- **`bound()`** checks the exclusion and subset counts right after the
  assertion is read, before any box is walked. The fragments share the
  init segment's exclusions, so this bounds them too.
- **`plan()`** takes `$maxRanges` and stops collecting ranges when it is
  reached, before `remaining()` sees them.
- **A new `@internal` exception, `BmffLimitException`**, carries the
  refusal. `check()` reports it as `assertion.bmffHash.malformed`. It is
  not a `HashException`, because that one is reported as a mismatch.

After:

- `vendor/bin/pest --group=SPEC-053`: **7 passed**. AC3 now takes
  0.02 s; AC4 takes 0.74 s and 0.02 s.
- `composer check` exit 0, **606 passed**.
- `bin/api-check.php`: the recorded surface matches.

## The corpus, before and after

Step 185's script: 844 runs over 422 fixtures plus the fragmented set,
with today's timestamps masked. The reports are **identical** before and
after. No real file comes near a limit; the highest use is 8 exclusions
and 2 subsets.

## Not needed

The linear `remaining()`, in which each box looks only at its own ranges,
was kept for a later step only if the cost at the limits was too high. At
0.74 s it is not, so that step is dropped.
