# Step 151 — A fragment's location must lie inside the tree (SPEC-028 amendment 1)

*2026-09-25. Found by the security review of the same day. A wrong
`Trusted`, present in 0.1.0, 0.2.0 and 0.2.1.*

## The flaw

Each fragment of a fragmented BMFF stream carries a merkle box with a
`location`: its leaf's place in a tree of `count` leaves.
`BmffHashCheck::path()` turns that number into left/right steps and does
not check its range. A location past the end took the last leaf's path,
and a negative one took the first leaf's.

The merkle box is excluded from the leaf hash. A copy of a fragment with
only its location changed therefore hashed to the same leaf and climbed
to the same root:

- the duplicate check saw two different numbers;
- the count check saw `count` places filled.

A stream with one fragment withheld and another offered twice was
`Trusted`.

## 151a — probes, oracles, the test seen red

`bin/make-fragmented-variants.php` now also writes two fragments whose
merkle box names a location outside the five-leaf tree, with nothing
else changed: `broken/seg_5-location-5.m4s` and
`broken/seg_1-location-minus-1.m4s`. The two existing variants came out
byte-identical.

| set | this verifier before | c2patool 0.27.22 and 0.28.0 (`--settings full.settings.json`, `fragment --fragments_glob`) |
|---|---|---|
| the five fragments | `Trusted` | `Trusted` |
| `seg_1` withheld, a copy of `seg_5` at location 5 | **`Trusted`** | refused: `assertion.bmffHash.mismatch` |
| `seg_5` withheld, a copy of `seg_1` at location -1 | **`Trusted`** | refused: `assertion.bmffHash.mismatch` |

Without trust settings, `c2patool` stops at `signingCredential.untrusted`
before it judges the fragments, so the answers were recorded with the
test anchors. They are in `tests/Fixtures/c2patool/bmff-fragmented/`.

`tests/Unit/Verifier/FragmentedVerifierTest.php`, AC8: red, `Trusted`
where `Invalid` was expected on the first set. The second set was run
apart, because the test stops at the first failure: `Trusted` as well,
*"all 5 fragment(s) reach the merkle root"*.

## 151b — built

`BmffHashCheck::checkFragment()` refuses a location that is not at least
0 and less than `count` with `assertion.bmffHash.mismatch`, naming the
fragment, the location and the tree's size. A missing `count` counts as
0, so every fragment is refused. This corrects the verifier toward
`c2patool`; it adds no difference.

Measured:

- `vendor/bin/pest --group=SPEC-028`: 8 passed.
- `composer check`: exit 0, 514 passed.
- **Whole files, 18,950 runs, before and after: none moved.**
- **Every sequence of five fragments drawn from the five real ones and
  the two relocated copies, 16,807 sets, before and after.** Before, 2,520
  were `Trusted`: the 120 orderings of the real five and 2,400 sets with a
  relocated copy. After, 120 are `Trusted`, exactly the orderings of the
  real five. No set without a relocated copy changed.

## Disclosure

The fix is local until the other findings of the review are fixed too.
They go out together as a security release. Pushing a probe or a test
before then would publish the flaw.
