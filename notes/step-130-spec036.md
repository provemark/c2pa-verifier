# Step 130 — SPEC-036: a redacted hard binding

*2026-09-24. SPEC-036 approved the same day. On open question 2 the
maintainer chose to follow `c2pa-rs`: any entry naming a hash label, in any
claim. Questions 1 and 3 adopted their proposals.*

## 130a — the variants, and the tests seen red

`bin/make-spec036-variants.php <c2patool-0.28.0> <c2patool-0.27.22>`
builds **five PNG variants** of the signed fixture on the route of step
56's `redacted` variant:
- the claim map grows one pair, a `redacted_assertions` with one entry;
- the hash box sits before the claim and does not move; its exclusion
  length and hash are rebound;
- the claim is re-signed under a throwaway root, and the new signature is
  checked under its own leaf.

The helpers are copied from `bin/make-ingredient-manifest-variants.php`,
so running this script regenerates nothing of step 56. The keys are
shredded at the end.

| variant | entry | 0.27.22 | 0.28.0 |
|---|---|---|---|
| `hash-data-relative` | `self#jumbf=c2pa.assertions/c2pa.hash.data` | `assertion.dataHash.redacted` | `assertion.hardBinding.redacted` |
| `hash-data-absolute` | `self#jumbf=/c2pa/<label>/c2pa.assertions/c2pa.hash.data` | `selfRedacted`, `dataHash.redacted` | `notRedacted`, `selfRedacted`, `hardBinding.redacted` |
| `hash-boxes-relative`, `hash-bmff-relative`, `hash-collection-relative` | the three other labels (`c2pa.hash.bmff.v2`, `c2pa.hash.collection.data`) | `assertion.dataHash.redacted` | `assertion.hardBinding.redacted` |

Every variant is `Invalid` in both versions. Each code sits on the entry
as written, and nothing else fails: the signature and the data hash are
intact. **0.27.22 still uses the code 2.4 marks deprecated.** That answers
open question 1 by measurement, as SPEC-036 amendment 1. The criteria
name 0.28.0.

This verifier gives the same failures, except `general.error` where 0.28.0
says `assertion.hardBinding.redacted` (SPEC-035's refusal).

`tests/Unit/Hash/HardBindingRedactedTest.php`, run as
`vendor/bin/pest --group=SPEC-036`: **4 failed, 2 passed.**
- AC1–AC3 fail because `general.error` stands where 0.28.0's code
  belongs.
- AC6 fails because the case does not exist.
- AC4 (SPEC-035's files carry no such code) and AC5 (the unchanged fixture
  keeps its verdict) are guards, green before and after.

`composer check` is otherwise clean: 467 passed.

Committed locally, not pushed.

## 130b — built

`StatusCode` gains `AssertionHardBindingRedacted`. In
`HashedUriCheck::redactions()`, an entry that contains one of the four
hard-binding labels now reports it on the entry as written, where
`general.error` stood. The rest of SPEC-035's rules are unchanged, and
apply alongside it.

`vendor/bin/pest --group=SPEC-036`: **6 passed**; the four red tests of
130a are green. Two counts moved: `CertificateProfileCheckTest` (54 → 55
codes) and `ApiSurfaceTest` (120 → 121).

**Before and after, the whole corpus** under the three standard settings,
with ingredient deltas (1017 runs). Only the five new variants moved, from
`general.error` to `assertion.hardBinding.redacted`, still `Invalid`.

`composer check`: exit 0, 471 tests.

Three amendments await confirmation:
- SPEC-036 #1: 0.27.22's deprecated code, measured;
- SPEC-035 #4: amendment 2's refusal of a hard binding is superseded;
- SPEC-025 #8: the code, surface 121.
