# SPEC-038: the BMFF hash's shape — exclusions, subsets, and the offsets they keep

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-24                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Issue #4 names three rules on `c2pa.hash.bmff.*` that this verifier does
not apply (`PRED-BMFF-001`, `-002`, `-004`). Step 133 measured them. It
used a new route: edit the `c2pa.hash.bmff.v3` assertion of
`fixture-signed.mp4`, re-sign the claim under a throwaway root, and pad the
COSE so that no box of the file moves. The unchanged assertion, re-signed,
is `Trusted` in both `c2patool` versions and here.

**Five variants are `Invalid` in both `c2patool` versions and `Trusted`
here:**
- `subset` ranges that are unsorted or overlap: `assertion.bmffHash.malformed`
  there;
- a `subset` that covers the whole box, in three forms (`[{0,4},{4,4}]`,
  `[{0,8}]`, `[{0,0}]`): `assertion.bmffHash.mismatch` there. Issue #4 did
  not name this one.

What C2PA 2.4 says, read at `4eb2c67`:
- `subset` ranges *"shall be ordered by increasing offset value and shall
  not overlap"*;
- *"for any root box not excluded in its entirety, the input data
  contributed to the hash for that box is comprised of the concatenation
  of the binary strings `offset || data`, where offset is defined as the
  absolute file offset of the box as an 8-byte integer"*.

What `c2pa-rs` does (`bmff_hash.rs`, read at `6c92bc3`):
- it refuses an empty `exclusions` list, and unsorted or overlapping
  subsets, with `assertion.bmffHash.malformed`, before hashing;
- a top-level box leaves the offset calculation only when an exclusion
  takes it out **without** a `subset`. With a `subset`, the box is *"not
  excluded in its entirety"*, even when the subsets cover every byte.
  Its offset stays in the hash, as the box's start.

This verifier drops the marker when nothing of a box remains. When
something remains, it hashes the **first remaining range's** offset
(`BmffHashCheck::plan()`, `digest()`). The first difference is measured.
The second matters only when a `subset` removes the head of a top-level
box. It is reasoned, and the tests-first step measures it with a variant
whose digest the script computes on its own.

The other two rules:
- **An empty or absent `exclusions` list** is `malformed` in both
  versions. For an absent one `c2patool` gives no report at all, *"missing
  field `exclusions`"*. This verifier says `Invalid` with
  `assertion.bmffHash.mismatch`: the verdict is right, the reason is not.
- **`assertion.bmffHash.additionalExclusionsPresent`** (informational).
  0.28.0 reports it on all 12 signed BMFF files of the corpus, 0.27.22 on
  none, and this verifier on none. `c2pa-rs`'s writer excludes `/free`
  and `/skip` itself, and 0.28.0 counts every exclusion other than
  `/uuid` (with a single data match on the C2PA UUID at offset 8), `/ftyp`
  and `/mfra` as additional (`claim.rs`, `verify_internal`).

No byte of the asset can be changed through any of this. The exclusions
and subsets sit inside the assertion the claim signature covers, so only
the signer writes them. What is at stake is a verdict that differs from
`c2patool`'s on a file `c2patool` rejects.

## Scope

**In scope**, in `src/Hash/BmffHashCheck.php`, for `c2pa.hash.bmff.v2` and
`.v3` (the same rules in both, as in `c2pa-rs`):

1. `exclusions` absent or empty → `assertion.bmffHash.malformed`, before
   any hashing.
2. A `subset` list whose offsets are not strictly increasing, or whose
   ranges overlap (`offset + length` beyond the next `offset`; a `length`
   of 0 runs to the end of the box, so only the last entry may carry it
   without overlapping) → `assertion.bmffHash.malformed`, before any
   hashing.
3. **The offset marker, as `c2pa-rs` places it.** Every top-level box
   gets a marker holding its own start offset. A top-level box loses its
   marker only when an exclusion without `subset` takes it out entirely.
   The marker precedes the box's first included byte. A box with nothing
   left keeps its marker, and the marker is then all that the box adds.
4. `assertion.bmffHash.additionalExclusionsPresent`, informational, on
   the assertion's url, when an exclusion is anything other than `/ftyp`,
   `/mfra`, or `/uuid` with exactly one data map equal to the C2PA UUID at
   offset 8, as 0.28.0 emits it. See open question 1.
5. Fixtures: `bin/make-spec038-variants.php`, step 133's route. It edits
   the bmff.v3 assertion of `fixture-signed.mp4`, re-signs under a
   throwaway root, and pads the COSE so no box moves. **Positive variants
   carry a digest the script computes itself**, from the file's bytes and
   the rule in scope item 3, never through `src/`. Both `c2patool`
   versions judge every variant.

**Out of scope** (each needs its own spec before it may be built)

- The `length`, `version` and `flags` filters and multiple `merkle` maps,
  still refused by name (SPEC-029).
- `PRED-BMFF-003` and the other BMFF predicates: this spec does not touch
  them.
- Merkle-tree (fragmented) exclusions beyond what the rules above say:
  they share `plan()`, so rules 1 to 3 reach them, but no fragmented
  variant is built here.

## Behavior

- **AC1 — an empty or absent `exclusions`** *(error path)*
  - Given variants whose bmff.v3 assertion has `exclusions: []`, or no
    `exclusions` key
  - When verified with the throwaway root
  - Then `assertion.bmffHash.malformed` on the assertion's url, and
    `Invalid`. For `[]` that is what both oracles give. For the absent key
    it is the fail-closed stand-in for `c2patool`'s missing report (open
    question 2).

- **AC2 — unsorted or overlapping subsets** *(error path)*
  - Given variants whose `/free` exclusion carries `subset`
    `[{4,4},{0,4}]` (unsorted) or `[{0,6},{4,4}]` (overlapping)
  - When verified
  - Then `assertion.bmffHash.malformed` on the assertion's url, and
    `Invalid`, as both oracles.

- **AC3 — a subset that covers the whole box keeps the box's offset** *(error path)*
  - Given variants whose `/free` exclusion carries `[{0,4},{4,4}]`,
    `[{0,8}]` or `[{0,0}]`, while the signed digest is the original one
    (computed with `/free` excluded entirely)
  - When verified
  - Then `assertion.bmffHash.mismatch` and `Invalid`, as both oracles.

- **AC4 — the same subsets, with the digest recomputed, pass**
  - Given the AC3 shapes, plus `[{0,4}]` (the head removed) and `[{8,0}]`
    (the body removed), each with a digest the script computes on its own
    under scope item 3
  - When verified
  - Then `Trusted`, as both oracles. This criterion is the evidence that
    the marker's presence and position are `c2pa-rs`'s.

- **AC5 — the informational code**
  - Given `fixture-signed.mp4` (exclusions `/uuid`, `/ftyp`, `/mfra`,
    `/free`, `/skip`)
  - When verified
  - Then `assertion.bmffHash.additionalExclusionsPresent` is informational,
    on `…/c2pa.assertions/c2pa.hash.bmff.v3`, and the verdict is
    unchanged, as 0.28.0. Across the corpus it appears on exactly the
    files where 0.28.0 reports it.

- **AC6 — nothing else moves**
  - Given the whole corpus under the three standard settings, before and
    after, with ingredient deltas compared
  - Then no verdict and no failure code changes outside the new fixtures,
    and the drift alarms pass. `c2pa-rs/video1.mp4` (v2, nested
    exclusions and a `subset`) keeps its verdict under the DigiCert
    settings. The only new status elsewhere is AC5's informational code.

- **AC7 — the vocabulary grows by one code, verbatim**
  - Then `StatusCode::AssertionBmffHashAdditionalExclusionsPresent` exists
    with the value `assertion.bmffHash.additionalExclusionsPresent`, is
    informational, and is in the recorded surface (122 → 123).

## References

- Specification: C2PA 2.4, the BMFF hash assertion (the exclusions map,
  `subset`, and the `offset || data` rule for root boxes not excluded in
  their entirety) and the §15 status-code table. Read in the 2.4 HTML of
  `c2pa-org/specifications` at `4eb2c67`.
- Oracles: `c2patool` 0.28.0 and 0.27.22 on step 133's variants and on
  the corpus's 24 BMFF files, recorded again with the fixtures in the
  tests-first step.
- Reasoned: `c2pa` `bmff_hash.rs` (the structure checks; the top-level
  offsets and when a box leaves them) and `claim.rs` `verify_internal`
  (the additional-exclusions rule), read at `6c92bc3`. The offset part is
  in a block that is commented out on that branch, so the measurement
  (AC3, and AC4 in the tests-first step) is the evidence, not the code.

## API sketch

Illustrative.

```php
// Report\StatusCode (contract): one case more, informational
case AssertionBmffHashAdditionalExclusionsPresent = 'assertion.bmffHash.additionalExclusionsPresent';

// Hash\BmffHashCheck (@internal)
public function assertionOf(mixed $data): array   // + exclusions present and non-empty, subsets ordered
public static function plan(array $tree, array $exclusions, callable $readAt): array
    // markers: one per top-level box not taken out entirely without subset, at the box's start
```

## Open questions

*Answered on approval, 2026-09-24:* question 1 by the maintainer (the
proposal: emit the informational code, as 0.28.0). Questions 2 and 3 were
settled by adopting their proposals.

1. **The informational code on nearly every BMFF file.** Only 0.28.0
   reports it, and it would appear on every signed MP4, MOV, AVIF and HEIC
   that `c2pa-rs` writes. The verdict does not move, but every such report
   changes. Proposal: emit it, as 0.28.0 does and as the 2.4 table defines
   it. The drift alarms compare failures only, and a test that pins
   0.27.22's informational list for a BMFF file is amended with this
   spec. *(blocker: your call)*
2. **An absent `exclusions` key.** `c2patool` refuses to produce a report.
   Proposal: `assertion.bmffHash.malformed`, a report that says why, as
   for the empty list. *(not a blocker)*
3. **A `length` of 0 that is not the last subset.** 2.4 says the last
   entry *"may"* have length 0, which reads as *only* the last. `c2pa-rs`
   does not check it separately; such an entry covers the rest of the box,
   and so overlaps any entry after it. Proposal: treat it that way, as
   overlapping, so no rule of its own. *(not a blocker)*

## Amendments

1. **2026-09-24, step 134b, found while building.** `StatusCode` had no
   `assertion.bmffHash.malformed` either. The draft took it as existing, as
   `assertion.dataHash.malformed` does. AC1 and AC2 need it, so it is
   added: `AssertionBmffHashMalformed = 'assertion.bmffHash.malformed'`, a
   failure, verbatim from the 2.4 table. AC7 grows to **two** codes, and
   the surface goes 122 → **124**.

   Weight B: one more code in the contract than approved.

   Confirmed by Maurice van Loon, 2026-09-24 (step 134).

2. **2026-09-24, step 134b, measured.** AC5's *"exactly the files where
   0.28.0 reports it"* holds on 11 of the 12. The twelfth,
   `isobmff/size-zero-not-last.mp4`, is refused by this verifier before
   any hash is read (SPEC-026: a box of size 0 that is not last).
   `c2patool` reads it anyway, as `tests/Fixtures/isobmff/README.md`
   already records. The test names that file and asserts the refusal.

   Weight C: a named exception to a criterion's wording, no behaviour
   changed.

   Confirmed by Maurice van Loon, 2026-09-24 (step 134).

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Hash/BmffShapeTest.php :: AC1: an empty or absent exclusions / SPEC-038 | src/Hash/BmffHashCheck.php :: shapeFault(), check() |
| AC2 | tests/Unit/Hash/BmffShapeTest.php :: AC2: unsorted or overlapping subsets / SPEC-038 | src/Hash/BmffHashCheck.php :: shapeFault() |
| AC3 | tests/Unit/Hash/BmffShapeTest.php :: AC3: a subset that covers the whole box keeps the box's offset / SPEC-038 | src/Hash/BmffHashCheck.php :: plan() (markers) |
| AC4 | tests/Unit/Hash/BmffShapeTest.php :: AC4: the same subsets, with the digest recomputed, pass / SPEC-038 | src/Hash/BmffHashCheck.php :: plan(), digest(); bin/make-spec038-variants.php :: bmffDigest() (the independent port) |
| AC5 | tests/Unit/Hash/BmffShapeTest.php :: AC5: the informational code, as 0.28.0 reports it / SPEC-038 | src/Hash/BmffHashCheck.php :: hasAdditionalExclusions(), check() |
| AC6 | tests/Unit/Hash/BmffShapeTest.php :: AC6: nothing else moves / SPEC-038; tests/Unit/Hash/BmffV2ExclusionsTest.php (SPEC-029, unchanged); the drift alarms (SPEC-013 AC10–AC13); the before/after run of step 134b | — |
| AC7 | tests/Unit/Hash/BmffShapeTest.php :: AC7: the vocabulary grows by two codes, verbatim (amendment 1) / SPEC-038 | src/Report/StatusCode.php :: AssertionBmffHashMalformed, AssertionBmffHashAdditionalExclusionsPresent; tests/Fixtures/api/public-surface.txt |
