# Step 134 — SPEC-038: the BMFF hash's shape

*2026-09-24. SPEC-038 approved the same day. On open question 1 the
maintainer took the proposal: emit `assertion.bmffHash.additionalExclusionsPresent`
as 0.28.0 does. Questions 2 and 3 adopted their proposals.*

## 134a — the fixtures, and the tests seen red

`bin/make-spec038-variants.php <c2patool-0.28.0> <c2patool-0.27.22>` is
step 133's route, made permanent:
1. the `c2pa.hash.bmff.v3` assertion of `fixture-signed.mp4` is edited;
2. the claim's hashed URI is recomputed, and the claim is re-signed under
   a throwaway root;
3. the COSE padding takes up the difference, so no box of the file moves.

**New here: the positive variants.** They carry a digest the script
computes with `bmffDigest()`, a port of `c2pa-rs`'s BMFF hashing
(`bmff_hash.rs`'s exclusion walk, `hash_utils.rs`'s offset markers),
written from that source and never through `src/`. Before it builds
anything, the script checks that the port reproduces the digest the
unchanged fixture was signed with. It does.

What both versions said:
- the unchanged assertion, re-signed: `Trusted`.
- `exclusions: []` is `malformed`, and an absent `exclusions` gets no
  report (*"missing field"*).
- unsorted and overlapping subsets are `malformed`.
- three subsets that cover the whole `/free` box, with the signed digest,
  are `mismatch`.
- **all five subset shapes with the port's digest are `Trusted`**:
  - the three that cover the whole box;
  - `[{0,4}]`, which removes the head;
  - `[{8,0}]`, which removes the body.

  So the port is `c2pa-rs`'s rule, in both when a box keeps its marker
  and where the marker goes.

**The second difference the draft reasoned is now measured:**
`subset-partial-rehashed` is `Trusted` in both versions and `Invalid`
here. When a subset removes the head of a box, this verifier hashes the
first remaining byte's offset instead of the box's.

The script also records, per version, which of the corpus's 24 BMFF files
carry `assertion.bmffHash.additionalExclusionsPresent`: 12 in 0.28.0, and
none in 0.27.22.

`tests/Unit/Hash/BmffShapeTest.php`, run as
`vendor/bin/pest --group=SPEC-038`: **6 failed, 1 passed.**
- AC1 and AC2 fail because `mismatch`, or nothing at all, stands where
  `malformed` belongs.
- AC3 fails because three variants are `Trusted` here.
- AC4 fails because five variants are `Invalid` here where both oracles
  say `Trusted`.
- AC5 fails because there is no informational code.
- AC7 fails because the case does not exist.
- AC6 (`video1.mp4` under the DigiCert settings) is the guard.

`composer check` is otherwise clean: 480 passed, and PHPStan is clean on
the new script after type guards.

Committed locally, not pushed.

## 134b — built

`BmffHashCheck` gains three things:
- `shapeFault()` runs before any hashing. An absent or empty `exclusions`
  is `assertion.bmffHash.malformed`. So is a `subset` list whose entries
  are not ordered or overlap, with an entry of length 0 counting as running
  to the end.
- `plan()` places offset markers as `c2pa-rs` does:
  - every top-level box keeps a marker holding its own start offset, unless
    an exclusion without `subset` takes it out;
  - where the box's head is included, the marker rides on the first range,
    as SPEC-029 had it, so that spec's pinned outputs are unchanged;
  - where the head is not included, the marker stands alone, and only
    strictly between the file's first and last included byte.
- `hasAdditionalExclusions()` adds the informational code beside whatever
  the hash says. It counts every exclusion other than `/ftyp`, `/mfra` and
  the C2PA `uuid` box.

**Found while building, as amendments:**
1. `StatusCode` had no `assertion.bmffHash.malformed` either, so SPEC-038
   adds two codes, not one. The surface goes 122 → 124.
2. `isobmff/size-zero-not-last.mp4` carries the informational code in
   0.28.0 but is refused here before any hash is read. That is a known
   difference, named in the test.

Also rewritten:
- SPEC-029 gets amendment 2 for the marker rule.
- Three tests that list the informational codes learned the new one.
- `docs/conformance.md` had called `PRED-BMFF-002` a rule that *"changes
  no verdict"*. Step 133 showed it does. The row is now closed, and the
  note says it was wrong.

`vendor/bin/pest --group=SPEC-038`: **7 passed.** `composer check`: exit
0, 486 tests.

**Before and after, the whole corpus** under the three standard settings,
with ingredient deltas and now also the active manifest's informational
codes compared (1086 runs):
- 45 changed lines belong to the new fixtures; every other change is the
  new informational code;
- the code appears on 11 files: exactly those 0.28.0 reports it on, less
  `size-zero-not-last`;
- no verdict and no failure code changed outside `bmff-shape/`.

Four amendments await confirmation: SPEC-038 #1 and #2, SPEC-029 #2 and
SPEC-025 #10.
