# Step 76 — The BMFF hash: what is known, and why the spec is not written yet

*2026-09-22.* The next step after SPEC-026 was to draft the BMFF hash
spec. It is not drafted, and this note says why: **the algorithm's shape is
established, but the stored hash has not been reproduced**, and a spec
whose central criterion cannot be measured is one this project does not
write.

Nothing in `src/` changed. This is a measurement that did not finish, and
it is written down so that the next attempt starts here instead of at the
beginning.

## What is established, from c2pa-rs itself

`c2pa-rs` (read at `sdk/src/assertions/bmff_hash.rs` and
`sdk/src/asset_handlers/bmff_io.rs`, shallow clone, Apache-2.0/MIT so
reading is plainly allowed) does **not** hash box by box. It does two
things:

1. **`bmff_to_jumbf_exclusions()`** turns the assertion's box-path
   exclusions into a flat list of byte ranges. For each entry it resolves
   the `xpath` to boxes, then filters by the optional `length`, `version`,
   `flags` (with `exact` for bitwise matching) and `data` (each a value to
   compare at an offset **relative to the box start**), and finally narrows
   the range with the optional `subset` list. What comes out is
   `(start, length)` pairs — nothing box-shaped survives.

2. **`hash_stream_by_alg(alg, stream, exclusions, bmff_v2 = true)`** is
   then the *same* machinery the data hash uses: hash the stream, skipping
   the excluded ranges.

That is the finding that matters for the shape of the work: **the BMFF
hash is a byte-range hash after a conversion**, so SPEC-012's streaming
reader can carry it. The new code is the conversion, not the hashing.

The `bmff_v2` flag adds one thing, and c2pa-rs documents it in a comment:

> For this case we not only hash the data but also the location where the
> data was found in the asset.

and does it with `hasher.update(&start.to_be_bytes())` — the excluded
range's start offset as a **big-endian `u64`**, fed into the hash at that
point in the stream.

## What did not work, so nobody repeats it

The stored hash of `tests/Fixtures/fixture-signed.mp4` begins
`87d4d42c438fe632766d`. Its exclusions resolve to three byte ranges —
`ftyp` (0, 32), the C2PA `uuid` box (32, 13578) and `free` (14555, 8) —
leaving `moov` and `mdat` included. Six hypotheses were computed and none
matched:

| attempt | result |
|---|---|
| the kept boxes concatenated | `cd1d34ea98627807eb19` |
| every subset of the five top-level boxes, whole and payload-only (64 combinations) | no match |
| markers at excluded starts, c2pa-rs's placement rule | `d5ff13e4faa557e1f025` |
| markers at **every** excluded start | `5b034bd048b7e495ceac` |
| markers with the offset as four bytes rather than eight | `d5d0cb2267a0215d966a` |
| no markers at all | `cd1d34ea98627807eb19` |

So the missing piece is not the *idea* of the markers. It is either their
placement — c2pa-rs adds a marker only when the offset is not inside an
included range and lies strictly between the first range's start and the
last range's end, and that condition was reimplemented from a reading
rather than run — or the exclusion set itself differs from the three ranges
above, or `mdat` is treated specially in v3.

## What the next attempt should do first

Not read more Rust. **Instrument it**: build c2pa-rs's example or a small
Rust binary that prints the flat exclusion list and the range sequence it
hashes for this exact fixture, and compare that against the three ranges
above. The gap is a fact about one file, and one printed list settles it in
minutes where reading settles it in hours — the same lesson step 61 taught
about the Go verifier, where the digest decided and the reading did not.

A second, cheaper check: sign a file with **no** `free` box and one with
two, and see how the stored hash moves. If it moves with the marker count,
the marker rule is the gap; if not, the exclusion set is.

## Why this is a step rather than a footnote

Three specs in this project have been amended because writing the code
showed an approved criterion to be wrong. This is the case that comes
before that: a criterion that *cannot be written yet*, because the thing it
would assert is not known. Stopping here costs one session. Writing
`AC1: the hash matches` against an algorithm reconstructed from prose would
have cost the next person who trusted it.
