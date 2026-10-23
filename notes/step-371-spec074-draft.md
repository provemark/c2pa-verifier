# Step 371 — SPEC-074 drafted: CBOR values as crJSON values

*2026-10-23.* The first of the three crJSON specs proposed in step 370,
after Maurice agreed with the split and its choices.
`specs/SPEC-074-crjson-values.md` is the draft. No repository code.

## What the draft says

One mapping from a decoded CBOR value to JSON, by crJSON Table 1: byte
strings as `b64'` plus Base64, tag 0 as its text, integer map keys as
decimal strings, floats kept as floats. What Table 1 cannot express
(another tag, tag 0 over non-text, a key collision, NaN, infinity) is
refused by name; the next spec writes such an assertion as `{}`, as
§3.6.1 allows. The report's existing rendering (`plain()`) is untouched.

## Measured for the draft

- `c2pa-rs` 0.91.1 `src/crjson.rs`: no generic Table 1. Bytes become
  `b64'…'` only in fields named `hash`, `pad`, `pad2`, `signature`;
  elsewhere they stay integer arrays. An absent `pad` is added as
  `b64''`. An integer array named `hash` is turned into bytes.
- In `c2patool` 0.28.2's crJSON of 62 fixture files: 1,405 prefixed
  strings, all with a closing quote. The crJSON page writes the prefix
  only; the schema says nothing.
- A census of the CBOR this verifier decodes from 556 manifest stores:
  1,688 byte strings, tag 0 only, no integer map key, 49 floats, none
  non-finite.
- A prototype of the mapping (scratch, outside the repository) against
  `c2patool`'s values: all 298 CBOR assertions of the 59 readable files
  equal, under four normalisations (bytes as bytes, the closing quote,
  the added `pad`, single-precision floats). 147 needed the `pad` one.
  `cawg.identity` is left out: `c2pa-rs` decodes its signature.

## Open questions in the draft

1. The closing quote: the page's `b64'…` or `c2pa-rs`'s `b64'…'`.
   Blocking.
2. A tag other than 0: refuse (proposed) or write its content.
3. Single-precision floats: written as PHP's double (proposed) or as
   the shortest single form, which needs the decoder to keep the width.

## Measured and reasoned

- Measured: the source file named; the counts above; the prototype
  comparison.
- Reasoned: refusing an unknown tag rather than guessing; leaving the
  decoder's value model alone.
