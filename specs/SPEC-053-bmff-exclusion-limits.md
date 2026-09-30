# SPEC-053: A BMFF hash's exclusions are bounded

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-30                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A `c2pa.hash.bmff.v2` or `v3` assertion lists `exclusions`: boxes, named
by `xpath`, that the hash leaves out, whole or in `subset` pieces
(SPEC-027, SPEC-029). Before hashing, `BmffHashCheck::plan()` turns them
into the ranges the digest covers:

- every exclusion that matches a box adds one range per subset (or one
  for the whole box) to a single list;
- then `remaining()` walks that whole list once for every top-level box.

So the cost grows with boxes × boxes × exclusions. The specification sets
no maximum, and neither does this verifier. `DataHashCheck` refuses more
than 1024 exclusions (SPEC-011); `BmffHashCheck` has no such limit. The
extractor already refuses more than 4096 boxes
(`IsobmffManifestStoreExtractor::DEFAULT_MAX_BOXES`).

**Measured** (2026-09-30, `plan()` called directly, top-level `free`
boxes of 8 bytes, each exclusion `{xpath: "/free", subset: [{offset: 0,
length: 1}]}`, PHP 8.5.8):

| boxes | exclusions | time |
|---|---|---|
| 256 | 16 | 0.1 s |
| 512 | 32 | 0.8 s |
| 1024 | 32 | 3.0 s |
| 1024 | 64 | 6.2 s |
| 4096 | 1 | 1.5 s |
| 4096 | 2 | 3.3 s |
| 4096 | 8 | 11.9 s |

A review on the same day measured 4096 boxes × 500 exclusions: PHP ran out
of its 512 MB memory limit in `remaining()`. 4096 such boxes are about
32 KB of file, and 500 exclusions about 5,500 CBOR items, well inside the
CBOR budget.

**Reasoned, not measured end to end:** the BMFF hash runs even when the
assertion's bytes no longer match the claim's hashed URI, so anyone can
put such an assertion into an existing file, without a key. The verdict
is never a wrong `Valid`; the risk is a site that verifies uploads
hanging or failing.

**Measured, what real files use** (every BMFF hash assertion in
`tests/Fixtures/`, 31 of them): at most **8 exclusions**, at most
**2 subsets** in one exclusion, at most 51 boxes in a file.

What `c2pa-rs` limits here was not read.

## Scope

**In scope**

1. **Three limits, checked before a byte of the asset is hashed:**
   - at most **64 exclusions** in one assertion;
   - at most **64 subsets** in one exclusion;
   - at most **4096 excluded ranges** in one plan (the ranges
     `plan()` collects), the product that makes the cost.
2. **Beyond a limit** the assertion is `assertion.bmffHash.malformed`,
   with an explanation that names the limit and the count found. No
   range is hashed.
3. **Every place that plans exclusions** is covered: the whole-file hash,
   the init segment of a fragmented file, and each fragment
   (`checkFragment()`).
4. **Configurable** through `BmffHashCheck`'s constructor, as
   `DataHashCheck`'s `maxExclusions` is, with the three values above as
   defaults.

**Out of scope** (each needs its own spec before it may be built)

- Making `remaining()` linear (each box looks only at its own ranges).
  Maurice's decision of 2026-09-30: measure the cost at the limits first,
  and make it a separate step only if it is too high.
- A limit on `xpath` length or on filters other than `subset`.
- The order of Merkle `location` values (`c2pa-rs` #2702).

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and
will be covered by a Pest test tagged `->group('SPEC-053')`. The variants
are built in memory from `fixture-signed.mp4`: its BMFF hash assertion
replaced by one with the exclusions under test, and top-level `free` boxes
appended where a criterion needs many boxes. The claim is not signed
again; the hashed URI then fails, which is the keyless case the problem
describes.

- **AC1 — too many exclusions** *(malformed input)*
  - Given the assertion with 65 exclusions
  - When the file is verified
  - Then the report holds `assertion.bmffHash.malformed` naming 65 and
    the limit 64; the state is `Invalid`; no exception escapes

- **AC2 — too many subsets in one exclusion** *(malformed input)*
  - Given one exclusion with 65 subsets
  - When the file is verified
  - Then `assertion.bmffHash.malformed` naming 65 and the limit 64

- **AC3 — too many excluded ranges** *(malformed input)*
  - Given 4096 top-level `free` boxes appended and 8 exclusions that each
    match every `free` box, with 1 subset each: 32,768 ranges, within the
    first two limits (measured today: `plan()` alone takes 11.9 s)
  - When the file is verified
  - Then `assertion.bmffHash.malformed` naming the limit 4096, and the
    verification returns within 2 seconds

- **AC4 — at the limits it stays cheap**
  - Given 4096 top-level `free` boxes and exclusions that give exactly
    4096 ranges, and separately 64 exclusions with 64 subsets each that
    match one box
  - When each file is verified
  - Then neither is `malformed`, and each returns within 5 seconds under
    PHP's default memory limit (128 MB). The measured times are written
    into the note. Measured today, `plan()` alone takes 1.5 s for the
    first file, so this is close to the line: over 2 seconds for the
    whole verification is the evidence for the linear `remaining()` as
    its own step

- **AC5 — the fragments are bounded too** *(malformed input)*
  - Given the fragmented set (`bmff-fragmented/`), with its init
    segment's assertion given 65 exclusions
  - When it is verified with `FragmentedVerifier` and all five fragments
  - Then `assertion.bmffHash.malformed` naming the limit, and no fragment
    is hashed

- **AC6 — genuine files do not move**
  - Given every fixture under `tests/Fixtures/` with two settings (none,
    and `trust/full.settings.json`) and the fragmented set
  - When each is verified before and after the change, with today's
    timestamps masked (step 185's method)
  - Then every report is identical

## References

- C2PA Technical Specification 2.4, the `c2pa.hash.bmff` assertion's
  `exclusions`, `xpath` and `subset` (as SPEC-027 and SPEC-029 read
  them). The specification sets no limit; the limit is this project's
  rule that every parser has hard limits.
- Measured: the table above; the corpus maxima; the review of 2026-09-30.
- Governing rules: SPEC-011 (the data hash's `maxExclusions`), SPEC-027,
  SPEC-028, SPEC-029, fail closed, bounded input.

## API sketch

Illustrative only. The public contract does not change; `BmffHashCheck`
is `@internal`.

```php
// namespace Provemark\C2paVerifier\Hash;

final readonly class BmffHashCheck
{
    public const DEFAULT_MAX_EXCLUSIONS = 64;
    public const DEFAULT_MAX_SUBSETS = 64;
    public const DEFAULT_MAX_RANGES = 4096;

    public function __construct(
        private int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        private IsobmffManifestStoreExtractor $boxes = new IsobmffManifestStoreExtractor,
        private iterable $fragments = [],
        private int $maxExclusions = self::DEFAULT_MAX_EXCLUSIONS,
        private int $maxSubsets = self::DEFAULT_MAX_SUBSETS,
        private int $maxRanges = self::DEFAULT_MAX_RANGES,
    ) {}
}
```

## Open questions

- None blocking. Whether the range limit lives in `plan()` (a static
  method) as a parameter or as a check on its result is an
  implementation choice; the criterion is that no range beyond the limit
  is hashed and `remaining()` never sees more than the limit.

## Amendments

1. **2026-09-30, step 190, while writing the tests.** AC3 and AC4 ask for
   4096 appended `free` boxes. The extractor refuses a file with more
   than 4096 boxes in all (`IsobmffManifestStoreExtractor::DEFAULT_MAX_BOXES`),
   and `fixture-signed.mp4` already has 29, nested ones included. So:
   - **AC3** appends 4000 `free` boxes. The 8 exclusions then match 4001
     boxes: 32,008 ranges. Measured through `Verifier::verify()` before
     the change: 11.4 s and no `malformed`. This is the end-to-end
     measurement the Problem section had only reasoned.
   - **AC4 "many boxes"** appends 2047 `free` boxes (2048 with the file's
     own) and uses one exclusion with 2 subsets: exactly 4096 ranges.
     **AC4 "many subsets"** uses 64 exclusions of 64 subsets, all on
     `/ftyp`: exactly 4096 ranges.
   - **One support test is added:** the variant builder, given the file's
     own exclusions, gives back a file the verifier reads with
     `assertion.bmffHash.match`. Every criterion rests on that builder.

   Measured at the limits before the change: 0.77 s ("many boxes") and
   0.02 s ("many subsets") for the whole verification. Both are under
   2 seconds, so the linear `remaining()` is not needed. That step is
   dropped unless a later measurement says otherwise.

   **Weight B:** the criteria's inputs change; what they assert does not.

   Confirmed by Maurice van Loon, 2026-09-30.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | `tests/Unit/Hash/BmffExclusionLimitsTest.php` :: "AC1: more than 64 exclusions is malformed" / SPEC-053 | `Hash\BmffHashCheck::bound()`, `DEFAULT_MAX_EXCLUSIONS`; `Hash\BmffLimitException` |
| AC2                  | `tests/Unit/Hash/BmffExclusionLimitsTest.php` :: "AC2: more than 64 subsets in one exclusion is malformed" / SPEC-053 | `Hash\BmffHashCheck::bound()`, `DEFAULT_MAX_SUBSETS` |
| AC3                  | `tests/Unit/Hash/BmffExclusionLimitsTest.php` :: "AC3: more than 4096 excluded ranges is malformed, and quickly" / SPEC-053 (amendment 1: 4000 boxes; 11.4 s before, 0.02 s after) | `Hash\BmffHashCheck::plan()` (`$maxRanges`), `DEFAULT_MAX_RANGES`; `check()` reports `BmffLimitException` as `malformed` |
| AC4                  | `tests/Unit/Hash/BmffExclusionLimitsTest.php` :: "AC4: at the limits it is not malformed and stays within 5 seconds" (2 cases) / SPEC-053 (a guard: 0.77 s and 0.02 s before, 0.74 s and 0.02 s after) | — |
| AC5                  | `tests/Unit/Hash/BmffExclusionLimitsTest.php` :: "AC5: the fragmented init segment is bounded too" / SPEC-053 | `Hash\BmffHashCheck::bound()` (the fragments share the init segment's exclusions) |
| AC6                  | measured, `notes/step-191-spec053.md`: 844 runs over 422 fixtures plus the fragmented set, before and after, identical | — |
