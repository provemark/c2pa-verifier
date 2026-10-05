# Step 224 — Built: `storeReached` for JPEG, PNG and ISOBMFF

*2026-10-05. SPEC-001 amendment 5, SPEC-002 amendment 3, SPEC-026
amendment 3, SPEC-013 amendment 18.*

## What changed in `src/`

Each of the three extractors has the RIFF walk's shape: the old body is a
private `walk()` that sets `$reached`, and `extract()` sets a fault's
`storeReached` from it. The store is reached at an APP11 segment whose
header starts with `JP` (JPEG), a `caBX` chunk header (PNG), and a top-level
`uuid` box with the C2PA UUID (ISOBMFF). The verifier already read the
flag (step 216).

## Two things the build found

1. **PHPStan**: the first ISOBMFF version gave `boxHeader()` an optional
   `?bool &$reached`, which PHPStan rightly said could leave the walk's
   flag `null`. `boxHeader()` is back to its earlier shape.
2. **The corpus moved one file that step 222 had predicted would not**:
   `isobmff/size-below-header.mp4`, a C2PA box whose size field is smaller
   than its own header. That fault is found before the UUID is read, so
   the flag went false. The amendment's own rule ("reached when a
   top-level `uuid` box carries the C2PA UUID") says true. Both are fixed
   by one change: when `boxHeader()` fails, `walk()` looks at the box in
   the stream (`carriesC2paUuid()`) and sets the flag if it is a C2PA
   `uuid` box whose UUID lies inside the file. The amendment's sentence
   that the UUID is read *before* the size check describes the effect;
   the code reads it *after* the check fails, which is the same answer
   without touching `boxHeader()`. The file is now in AC10's test.

## Measured

- Pest per group: SPEC-001 18, SPEC-002 16, SPEC-026 10, SPEC-013 21
  passed (step 223: one failure in each).
- `composer check`: exit 0, 690 tests.
- The corpus against step 220: **exactly the five predicted files moved**
  (`has_manifest` true → false, nothing else). Step 221's built files: the
  six unsigned faulty ones now say no manifest; the six signed ones keep
  `has_manifest: true`.
- The fuzzer over the full release set (seed 20261005, 60 rounds): 12,300
  runs, 0 faults; the 66 that stayed `Valid` are `Valid` in both
  `c2patool` versions.

## Mistakes on the way, and what was done

Step 223's red commit first said PHPStan was clean before the result was
read, and an attempted fix was committed before PHPStan was run again.
Both were corrected in the same local commit before anything was pushed,
and the note there says so. Since then every check was read before
committing.
