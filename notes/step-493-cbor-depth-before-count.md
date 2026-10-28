# Step 493 — The depth before the count

*2026-10-28.* Finding S2 of the review of steps 490–491: a correction
under SPEC-088 amendment 2, approved by Maurice. No verdict changes.

## Why

Amendment 2 says that a limit met first where the input could go on
stays a limit, and names the depth: 33 nested arrays, then the bytes
stop. As step 491 built it, the count was weighed against the bytes
before the depth was checked. With exactly 33 bytes of `0x81` the 33rd
array declares one item and no byte is left, so it was truncation
(`assertion.cbor.invalid`); with 34 it was the depth (`general.error`).
The code contradicted the amendment's own example.

## What changed

`CborDecoder::array()` and `map()` check the depth when the container's
head is read, then the count against the bytes, then the item limit.
The amendment carries a note on the correction.

## Measured

- The reviewer's case, run here before the correction: 33 × `0x81` code
  0, 34 and 40 × `0x81` code 1.
- Two dataset rows in `StatusCodesByTheLetterTest.php` (33 arrays; 32
  arrays and a map, the bytes stopping there): both failed before the
  correction, pass after.
- Mutations: the count weighed before the depth again, for arrays and
  for maps: both caught.
- Corpus, 3,132 runs at `47500b9` and now: identical. Against v0.8.3,
  still 36 runs in twelve files differ, no verdict among them.
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.
