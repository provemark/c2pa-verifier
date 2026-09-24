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
