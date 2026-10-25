# Step 440 — The covered rows of Appendix A, each broken once

*2026-10-25.* Step 3 of the audit "Before 2.5: is all of 2.4 there?",
continued from step 439: the 24 covered rows of Appendix A (where each
format keeps its manifest store). No code changes: tests and the
reading.

## How

As in step 439: one exact replacement in `src/` per mutation, the cited
tests run, the whole suite when they stay green, the file restored and
`git status src` checked; for the survivors, the whole fixture corpus
with each directory's settings, with and without the mutation. 35
mutations over the format detector, the JPEG, PNG, ID3, RIFF, GIF,
ISOBMFF and plain-text extractors, the variation-selector map and the
BMFF subset arithmetic.

## What was measured

| result | mutations |
|---|---|
| red in the test the row cites | 27 |
| red only in other tests: citation added | 1: the walk past the first RIFF chunk (`WavManifestStoreExtractorTest` AC4, `WebpManifestStoreExtractorTest` AC5) |
| green everywhere | 7 |

None of the seven moved a corpus file. Six are now tested:

| rule | what was done |
|---|---|
| §A.3.1: another APP11 user is skipped | `JpegManifestStoreExtractorTest` "AC8, AC11: …": `app11-not-jp.jpg`'s segment is too short to pass for a piece header, so it never reached the `JP` check. A 20-byte `XT` segment before the store now does; without the check it is read as a first piece with Z = 0 |
| §A.3.1 (ISO 19566-5 D.2): the same TBox in every piece | the same test: piece 2 of `fixture-signed.jpg` with TBox `jumc` |
| §A.3.1: Z counts up; only a first piece may say 0 | the same test: piece 2 with Z = 0 |
| §A.3.1: no piece carries the box past its LBox | the same test: LBox 70,000 in both pieces. Without the rule the file was still refused at the end, with another message |
| §A.5.6: no byte past a box is excluded | `BmffV2ExclusionsTest` AC4: a subset that runs 76 bytes past its box leaves the next box whole |
| §A.8.2: the length is at least a box header | `PlainTextTest` "AC6: a length field shorter than a box header…": a 4-byte store that reads as LBox 4 is `manifest.text.corruptedWrapper` |

The seventh is equivalent: a subset that starts past the end of its box
gets a negative length when the `continue` that drops it is removed, and
`remaining()` drops a range that ends before it starts. The new AC4 case
pins the outcome. Each of the six was seen red under its mutation after
the change, and green without it. `composer check`: 1,373 passed, 1 skipped.
One earlier run failed only on `HostileInputSecondRoundTest` AC2, the
two-second timing test, at a load average of 9; it passed three times on
its own and in the next full run.

## Not done

§6–§9 and C (about 23 rows).
