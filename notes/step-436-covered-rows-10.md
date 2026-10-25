# Step 436 — The covered rows of §10, each broken once

*2026-10-25.* Step 3 of the audit "Before 2.5: is all of 2.4 there?",
continued from step 435: the 19 covered rows of §10 (the claim, its
required fields, the claim signature's time-stamp, the active manifest).
No code changes: tests and the reading.

## How

As in step 435: one exact replacement in `src/` per mutation, the cited
tests run, the whole suite when they stay green, the file restored and
`git status src` checked; for the survivors, the whole fixture corpus
with each directory's settings, with and without the mutation. 21
mutations covered 15 rows. Two rows were broken in step 435 (an `alg`
missing everywhere, the detached payload); one names no code
(`specVersion` is never read); one is the rendering of
`claim_generator_info`, which the report snapshots hold.

## What was measured

| result | mutations |
|---|---|
| red in the test the row cites | 14 (one in a row that said "read": the length of the timestamp's message imprint, held by `TimeStampTokenTest` SPEC-016 AC7) |
| red only in other tests: citation fixed | 2: an empty `claim_generator_info` in a v2 claim (`ManifestProbesTest` AC19); the first manifest taken as the active one, which the cited test cannot see because its store has one manifest |
| green everywhere | 5 |

What became of the five:

| rule | what was done |
|---|---|
| §10.2.2: `created_assertions` holds at least one reference | `ManifestStoreTest` "AC9: an empty assertion list, a null claim_generator_info and a name that is not text are malformed…", on the claim map and on `absence/created-empty.png`. That file, built in step 48 and judged by `c2patool` then, was read by no test; under the mutation it was the one corpus file that moved (`claim.malformed` became `claim.hardBindings.missing`) |
| §10.2.2: `gathered_assertions`, when present, holds one | the same test |
| §10.2.1: a required field that is null is not there | the same test: a null `claim_generator_info` in a v2 claim, which nothing else would have refused |
| §10.2.3.2: the generator's `name` is text | the same test: a name that is a number |
| §10.3.2.5: a tstContainer is a map | `TimeStampTokenTest` SPEC-016 AC8: a list holding one is refused as "sigTst2 is not a map" |
| §10.2.2: `redacted_assertions` holds URIs | recorded, no test: reading relative entries too moves no file, and the verification path applies only absolute entries (`ManifestStore::scopedRedactions()`), so a relative one can add a refusal and never excuse one |

Each of the four tested was seen red under its mutation after the change,
and green without it. `composer check`: 1,364 passed, 1 skipped.

## What was reasoned

The redaction survivor, from the code path above.

## Not done

The covered rows outside §10 and §13–§15 (about 120).
