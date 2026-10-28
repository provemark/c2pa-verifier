# Step 491 — A count the bytes cannot hold is truncation

*2026-10-28.* Finding R2 of the review of steps 483–489: SPEC-088
amendment 2, approved by Maurice. No verdict, status or contract symbol
changes.

## Why

Step 485 split the CBOR decoder's faults into CBOR that is not valid
(`assertion.cbor.invalid`) and a limit of this decoder (`general.error`).
The item limit was checked before truncation could be seen: an array
declaring 2^64 − 1 items in nine bytes, or a million items followed by
one byte, was a limit, while a byte string declaring 2^63 bytes was
truncation. Every item takes at least one byte and every map entry two,
so such a count is not well-formed whatever the limits.

## What changed

- `CborDecoder::holds()` (private): before the item limit, a definite
  count that the remaining bytes cannot hold is "unexpected end of
  input", naming where the bytes run out (SPEC-006 AC10) and what the
  container declared.
- A limit met first where the input could still go on — the depth, a map
  key outside the subset — stays a limit: the first fault met decides,
  and reading past a limit is what it exists to prevent (amendment 2).
- `CborBudget` is unchanged: it counts items as they are decoded, after
  their bytes were read.

## Measured

- The reviewer's inputs, run again: four limits before. Now the count of
  2^63 − 1 and of 2^64 − 1 in nine bytes, the million items with one
  byte, and the two-entry map in three bytes are truncation; 33 nested
  arrays with no end stay a limit.
- `StatusCodesByTheLetterTest.php`, the decoder dataset: three rows red
  before the build (the three counts), the map rows already truncation;
  the row "a declared count above the limit" now carries its 65,537 items
  and stays a limit. `CborDecoderTest`'s AC10 held the offset to where the
  bytes run out; the message follows it.
- Mutations: six, all caught — no check for arrays, none for maps, a map
  entry counted as one byte, `>=`, a negative count passing, the limit
  checked first.
- Corpus, 3,126 runs at `1696779` and now, reports and evidence outcomes:
  identical.
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.
