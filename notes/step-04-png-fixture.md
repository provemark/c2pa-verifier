# Step 04 — The signed PNG fixture, and what a PNG looks like inside

*2026-09-19.* The PNG counterpart of step 02: a PNG with a manifest store
in it, made in a way anyone can repeat; a measurement of what is in it;
ten malformed variants, each given to c2patool; and a reading of the
c2pa-rs source to explain the surprises. No verifier code, no spec yet —
SPEC-002 is written from this.

## The fixture

`tests/Fixtures/fixture-signed.png` (47,736 bytes, SHA-256
`649fa44a68e1ea6df2a7837853685b8e6bc42b828f8eec6e2b8b88dda43f5d5a`) was
made from `tests/Fixtures/fixture-unsigned.png` (1,699 bytes, SHA-256
`fa4fd73be01b6407b11728c216ee4292430f688eabbc710e8652441d7325371e`; the
sister library's `fixture.png`) with:

```
c2patool fixture-unsigned.png -m fixture-signed-png.manifest.json -o fixture-signed.png
```

`c2patool 0.27.22`, the c2pa-rs ES256 **test** certificate chain, the same
manifest definition as the JPEG fixture with only the title changed
(`tests/Fixtures/fixture-signed-png.manifest.json`, key and certificate
paths replaced by placeholders; the private key is not in this repository
and never will be — `git ls-files | grep -i key` is empty).

Measured with c2patool 0.27.22:

| command | `validation_state` | notable codes |
|---|---|---|
| `c2patool fixture-signed.png` | `Valid` | `claimSignature.validated`, `assertion.dataHash.match`, `signingCredential.untrusted` (test cert, no trust file) |
| `c2patool fixture-signed.png --settings c2pa-trust.settings.json` | `Trusted` | — |
| `c2patool fixture-unsigned.png` | — | `Error: No claim found` |

## What a PNG is, for the reader who has never looked inside one

A PNG starts with eight fixed bytes, `89 50 4E 47 0D 0A 1A 0A` — the
letters `PNG` between a high byte and a line-ending trap that catches files
mangled by text-mode transfers. Then comes a sequence of *chunks*, all with
the same frame:

```
4 bytes   length of the data, big-endian
4 bytes   type, four ASCII letters
n bytes   data
4 bytes   CRC-32 over type + data (not over the length)
```

Where a JPEG marker sometimes has a length field and sometimes not, every
PNG chunk has the same frame, so a reader hops from chunk to chunk with
no table. `IHDR` must come first and `IEND` last; the pixels are in one or
more `IDAT` chunks. The case of each letter in the type is a flag: lower
case first letter = *ancillary* (a decoder that does not know the chunk
may ignore it); lower case second = *private*; third must be upper case
(reserved); lower case fourth = *safe to copy* when the image is edited.

C2PA uses the type **`caBX`**: ancillary, private, reserved bit clear,
**not** safe to copy — an editor that touches the pixels must drop it,
which is right, because the signature would no longer bind. The PNG
specification (ISO/IEC 15948, published as a W3C Recommendation and freely
readable, unlike the JPEG-side ISO texts) is the source for the frame,
the CRC and the ordering rules; C2PA 2.4 §A.3 "Embedding manifests into
PNG" says the store goes in one `caBX` chunk before `IDAT`.

## The chunk layout, measured

Every chunk, walked with a throw-away probe (length, type, data, CRC
recomputed with PHP's `crc32()` and compared):

```
offset  type  length  CRC
8       IHDR  13      fe4f2a3c  ok
33      caBX  46025   83278c6b  ok
46070   pHYs  9       952b0e1b  ok
46091   IDAT  1621    6921d09e  ok
47724   IEND  0       (ok)
```

The unsigned original is `IHDR, pHYs, IDAT, IEND`; c2patool inserted
`caBX` directly after `IHDR`. **One chunk holds the whole store**: PNG
chunk lengths are 31-bit, so unlike JPEG there is nothing to split and no
piece header. The chunk data *is* the JUMBF box: its first bytes are
`0000b3c9 6a756d62 0000001e 6a756d64 63327061 …` — LBox 46,025 (equal to
the chunk length), `jumb`, then a `jumd` description box whose UUID begins
with `c2pa`. Reading that is M2.

The store: 46,025 bytes, **SHA-256
`1a018eb892c4b30c112976cd7411df24baa9ce788cfe2904f58a69dec6e057df`**. This
hash is SPEC-002's oracle, on the same footing as step 02's: the probe's
extraction plus the structural check that LBox equals the chunk length and
the box is a `jumb` with a `c2pa` description.

One PHP detail found by the probe: `fread($f, 0)` throws a `ValueError`
in PHP 8, and `IEND` has length 0. The JPEG extractor already guards a
zero-length read; the PNG one must too.

## Ten variants, measured

Built by `bin/make-png-variants.php` — whole chunks moved or one field
changed — and each given to `c2patool 0.27.22`. The table with every
result and every hash is `tests/Fixtures/png/README.md`. The findings that
shape SPEC-002:

1. **The CRC is not checked.** `crc-wrong.png` (one bit flipped in the
   `caBX` CRC, data untouched) → `Valid`.
2. **LBox is not compared to the chunk length.** `lbox-differs.png` (LBox
   inside the box 46,026, chunk 46,025, CRC recomputed) → `Valid`.
3. **Position does not matter for extraction.** `caBX` after `IDAT`, and
   even before `IHDR` (which the PNG spec forbids): both extract,
   `claimSignature.validated`, then `assertion.dataHash.mismatch` →
   `Invalid`. The hash binding (M4) notices that the chunk moved; the
   extraction does not care. The same shape as JPEG's gap variant (AC4).
4. **Two `caBX` chunks are an error**: `Error: more than one manifest store
   detected`. Not "the first one" — an error.
5. **Truncation and a wrong length field are the same error**: `PNG out of
   range`. With the length +1 the chunk walk is off by one after `caBX`,
   reads garbage as the next length, and runs off the file.
6. A 4-byte `caBX` → `Error: unexpected end of file` (from the JUMBF
   parser, not the chunk walk); an empty one → `Error: No claim found`.
7. Not a PNG → `Error: Unsupported file type`.

## Why, from the source (read 2026-09-19, c2pa-rs `main`, `sdk/src/asset_handlers/png_io.rs`)

`get_png_chunk_positions` checks the signature, then per chunk reads the
length and the type, *seeks* past the data, and reads the four CRC bytes
into a buffer that is never compared — the comment says `// read crc`, and
that is all that happens to it. It stops at `IEND` or when the position
passes the end of the file. The chunk type must be valid UTF-8; the
four-ASCII-letters rule is not checked. `get_cai_data` then counts `caBX`
chunks: more than one → `TooManyManifestStores`; none → `JumbfNotFound`;
otherwise it seeks to `start + 8` and reads exactly `length` bytes. That
is the whole extraction: no CRC, no position rule, no look inside the
box. Findings 1–4 follow directly. The tolerance of finding 2 — LBox one
larger than the data — lives in the JUMBF parser downstream, which is M2's
oracle question, not this step's.

## What this settles for SPEC-002 (proposed, for the draft)

- The signature, the chunk frame, one `caBX` before `IDAT` — measured.
- The oracle hash for AC1: `1a018eb8…57df`, 46,025 bytes, first eight
  bytes `0000b3c9 6a756d62`.
- No `caBX` → `null` (the unsigned fixture; the empty chunk is a separate
  question).
- Two `caBX` → error, as c2patool.
- Truncated, length off, not a PNG → error, as c2patool.
- Two places where the verifier will be **stricter than the oracle**, in
  the safe direction, like JPEG's AC7: a CRC that does not match, and an
  LBox that differs from the chunk length. Both are the container
  disagreeing with itself; fail closed says error. To be decided in the
  spec, with the divergence written next to the criterion.
- A `caBX` that is not before `IDAT`: c2patool extracts it and lets M4
  reject the file. Proposal: extract, as c2patool — the extraction layer
  has no way to say `Invalid`, and an error here would hide the more
  precise `assertion.dataHash.mismatch` that M4 will give. The one before
  `IHDR` is the same case: a PNG-spec violation, but not this layer's.
- The empty `caBX` and the 4-byte one: an error naming the chunk length
  (a box needs at least 8 bytes), rather than c2patool's `No claim found`
  for the empty one — a present-but-empty store is not "no store".

## Reasoned, not measured

- That every real C2PA PNG has exactly one `caBX` directly after `IHDR`:
  true for c2patool's writer (the source moves an existing `caBX` there),
  not measured for other writers.
- ISO/IEC 15948's rule that `IHDR` is first: read from the specification,
  not exercised by c2patool.
