# Step 450 — The reading of C2PA 2.4 after SPEC-080

*2026-10-26.* Step 448 built SPEC-080 without touching
`docs/reading-c2pa-2.4.md`, so the six rows of group E (step 426) still
read *partial* or *candidate*. This step rewrites them against the code.
Documentation only.

## What changed

| row | was | now |
|---|---|---|
| §15.12.2.1, the leaves by block size | partial | covered for `fixedBlockSize`; `variableBlockSizes` by design (SPEC-080 AC8) |
| §15.12.2.1, `count` and the stored row | partial | covered |
| §18.6.2, `hash` and `merkle` together | candidate | covered |
| §18.6.3, `initHash` absent when not fragmented | partial | covered |
| §18.6.3, `fixedBlockSize` and `variableBlockSizes` | candidate | covered for `fixedBlockSize`; the rest by design |
| §A.5.6, `/mdat` excluded from offset 16 | candidate | covered; stricter than `c2patool` where it protects bytes |

The §18.6.2 row on `box_purpose` now names the proof boxes as the second
place the purpose `merkle` is read.

## What was measured

- Every test cited exists (`MdatMerkleTest`, `FragmentedVerifierTest`,
  `BmffProbesTest`).
- The codes for the three malformed cases were read from the stored
  `c2patool` 0.28.3 answers: `fixed-and-variable`, `count-five` and
  `init-hash` are each `assertion.bmffHash.mismatch` there, as here, where
  the letter of §15.12.2.1 says malformed. The same verdict, another code:
  group F's kind.
- The verdict column counted by script before and after: +6 covered,
  −3 partial, −3 candidate. The tally now reads 337 covered, 45 partial,
  78 by design, 98 n/a and 4 candidates, of 563.

## What remains open

The 45 partial rows and 4 candidates, all in groups B, C, D and F, none
of them a possible wrong `Valid` (step 426).
