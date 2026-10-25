# Step 438 — The covered rows of §18.15 and §18.16, each broken once

*2026-10-25.* Step 3 of the audit "Before 2.5: is all of 2.4 there?",
continued from step 437, the first of two steps for §18: the 16 covered
rows of §18.15 (actions) and §18.16 (ingredients). No code changes: tests
and the reading.

## How

As in step 437: one exact replacement in `src/` per mutation, the cited
tests run, the whole suite when they stay green, the file restored and
`git status src` checked; for the survivors, the whole fixture corpus
with each directory's settings, with and without the mutation. 25
mutations covered all 16 rows.

## What was measured

| result | mutations |
|---|---|
| red in the test the row cites | 20 |
| red only in another test: citation fixed | 1: a `c2pa.opened` with more than one reference (`OpenedReferencesTest` AC10, step 429) |
| red by a later rule only | 1: an ingredient assertion without `relationship` was still refused, by the type rule after it, with warnings and another message. `IngredientAssertionTest` AC2 now asks for each rule's own message |
| green everywhere | 3 |

None of the three moved a corpus file. All are now tested at their seam:

| rule | what was done |
|---|---|
| §18.15.4.8: `relatedAssertions` point into the same manifest | `ActionsContentTest` "AC6, AC7: … at the seam": a URI into another manifest whose last segment is a label this manifest also has is refused; the same label here passes |
| §18.15.5: the deprecated `c2pa.watermarked` needs a soft binding too | the same test, both action names |
| §18.16.12.4: a record never acknowledges a failure of the active manifest | `AcknowledgementScopeTest` "AC4: a record never acknowledges a failure of the active manifest…": `acknowledge()` given a scoped failure about the active manifest and a record whose holder has it below, as only a cycle could; the failure stays. The same record for another manifest is acknowledged |

The third is defence in depth: the store already leaves out records that
close a cycle (SPEC-070 amendment 4), so no file reaches the guard. It is
the rule the specification states, so it is now held on its own.

Each was seen red under its mutation after the change, and green without
it. `composer check`: 1,367 passed, 1 skipped.

## Not done

The other 40 covered rows of §18; Appendix A; §6–§9 and C.
