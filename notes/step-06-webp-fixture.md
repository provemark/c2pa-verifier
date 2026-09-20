# Step 06 — The signed WebP fixture, and what a RIFF file looks like inside

*2026-09-20.* The WebP counterpart of steps 02 and 04: a signed WebP made
in a way anyone can repeat; a measurement of what is in it; fifteen
malformed variants, each given to c2patool; and a reading of the c2pa-rs
source to explain the surprises — of which WebP has more than PNG. No
verifier code, no spec yet — SPEC-003 is written from this.

## The fixture

`tests/Fixtures/fixture-signed.webp` (100,956 bytes, SHA-256
`f0f546a2a110c30f79fe7dd2971b1aa103a1c8e68926677b57739af50b343325`) was
made from `tests/Fixtures/fixture-unsigned.webp` (312 bytes, SHA-256
`30cd5e355227edbae4245c3056345df1a70ba1a6c30c7e8d17d2ae8941c5e8bb`; the
sister library's `fixture.webp`, a lossless `VP8L` image) with:

```
c2patool fixture-unsigned.webp -m fixture-signed-webp.manifest.json -o fixture-signed.webp
```

`c2patool 0.27.22`, the c2pa-rs ES256 **test** certificate chain, the same
manifest definition as the other two fixtures with only the title changed
(`tests/Fixtures/fixture-signed-webp.manifest.json`, key and certificate
paths as placeholders; the private key is not in this repository).

| command | `validation_state` | notable codes |
|---|---|---|
| `c2patool fixture-signed.webp` | `Valid` | `claimSignature.validated`, `assertion.dataHash.match`, `signingCredential.untrusted` |
| `c2patool fixture-signed.webp --settings c2pa-trust.settings.json` | `Trusted` | — |
| `c2patool fixture-unsigned.webp` | — | `Error: No claim found` |

## What a WebP is, for the reader who has never looked inside one

A WebP is a RIFF file — the 1991 Microsoft/IBM container that also holds
WAV and AVI. Twelve bytes of header:

```
RIFF        4 bytes
size        4 bytes, little-endian: the file length minus 8
WEBP        4 bytes, the form type
```

then chunks, each with the same frame:

```
4 bytes   type, four ASCII characters
4 bytes   length of the data, little-endian
n bytes   data
1 byte    pad, 00, only when n is odd — not counted in the length
```

Three things a PNG reader would get wrong here: the numbers are
little-endian (`unpack('V')`, not `'N'`); there is no CRC; and the pad
byte — forget it after one odd-length chunk and every later chunk header
is read one byte off. C2PA 2.4 §A.3 "Embedding manifests into WebP" puts
the store in a chunk of type `C2PA`.

## The chunk layout, measured

Walked with a throw-away probe:

```
RIFF size=100948 WEBP          (file 100,956 − 8 = 100,948: correct)
12     VP8L len=292    pad=0
312    C2PA len=100635 pad=1
walk ended at 100956 = file length
```

The unsigned original is `VP8L` alone (RIFF size 304). c2patool appended
`C2PA` **after** the image data — the opposite of PNG, where it went before
`IDAT`. One chunk holds the whole store; no pieces.

The store is 100,635 bytes — **odd**, so the fixture itself answers the pad
question: the pad byte is there (312 + 8 + 100,635 + 1 = 100,956), it is
not counted in the chunk length, not in LBox (100,635, equal to the chunk
length), and it is counted in the RIFF size. The data begins
`0001891b 6a756d62 0000001e 6a756d64 63327061 …` — LBox, `jumb`, `jumd`,
a UUID starting `c2pa`. The store's **SHA-256
`5062cb0a602aa10a8d826370d27107284fcc9957dc93c9f1b025f7a380c13999`** is
SPEC-003's oracle, on the same footing as steps 02 and 04.

(The store is twice the PNG one for the same 64×64 picture; that is the
auto-thumbnail, M2's business, not this step's.)

## Fifteen variants, measured

Built by `bin/make-webp-variants.php`; every result and hash in
`tests/Fixtures/webp/README.md`. What shapes SPEC-003:

1. **The first `C2PA` chunk wins, silently.** `two-c2pa.webp` is not an
   error: c2patool extracts the first, validates the signature, and M4
   reports `assertion.dataHash.mismatch`. PNG gave `more than one manifest
   store detected`; RIFF does not.
2. **The form type is not checked.** `riff-not-webp.webp` (`WAVE`) is read
   like a WebP: extracts, then `dataHash.mismatch`.
3. **The RIFF size in the header is honoured.** With the size set as if
   `C2PA` were absent (`riff-size-excludes-c2pa.webp`) c2patool never sees
   the chunk: `No claim found`. With the size +1 it extracts and M4
   rejects (the header bytes are hashed).
4. **LBox is not compared to the chunk length** (`lbox-differs.webp` →
   `Valid`), and a chunk length +1 — which turns the pad byte into data —
   is `Valid` too: the JUMBF parser downstream tolerates a trailing byte.
5. **Padding is handled, and hashed.** An odd unknown chunk before `C2PA`
   is walked correctly (extracts); a missing or non-zero pad byte extracts
   and fails at M4, because the pad byte lies inside the hashed range.
6. Position does not matter: `C2PA` before `VP8L` extracts, M4 rejects.
7. Truncated inside the chunk → `RIFF chunk declared size exceeds file
   size`; truncated between chunks (RIFF size still full) → `Invalid RIFF
   format`; 4 bytes → `unexpected end of file`; empty → `No claim found`;
   not RIFF → `Unsupported file type`.

## Why, from the source (read 2026-09-20, c2pa-rs `main`, `sdk/src/asset_handlers/riff_io.rs`)

`read_c2pa` uses the `riff` crate: `Chunk::read(stream, 0)` reads the
top-level chunk with the size from the header, refuses anything but
`RIFF` (the form type is never looked at), and `iter()` walks the children
*within that declared size* — finding 3. For each child: if its id is
`C2PA`, check that its declared end does not exceed the file length
(finding 7's first message), read its contents and **return** — finding 1;
a second `C2PA` is never reached. Nothing compares LBox with the length —
finding 4. The pad byte is the `riff` crate's job — finding 5.

## What this settles for SPEC-003 (proposed, for the draft)

- The header (`RIFF`, size, `WEBP`), the chunk frame with little-endian
  lengths and the pad byte, one `C2PA` chunk — measured.
- The oracle hash for AC1: `5062cb0a…3999`, 100,635 bytes, first eight
  bytes `0001891b 6a756d62`. The pad byte is **not** part of the store.
- No `C2PA` → `null`; not RIFF → error; truncated → error; too short or
  empty → error (as SPEC-002 AC11).
- Two `C2PA` chunks: c2patool takes the first. **Proposal: error, as
  SPEC-002 AC5**, stricter than the oracle in the safe direction. Two
  stores in one file is the container disagreeing with itself, and
  "the first one" is a choice a verifier should not make silently.
- Form type not `WEBP`: c2patool does not care. **Proposal: error.** This
  extractor is for WebP; a `WAVE` file is a different container with its
  own spec later, and reading it here would let a WAV pass through a
  WebP path. Stricter than the oracle, safe direction.
- RIFF size in the header: **proposal: the walk is bounded by it, as
  c2patool's is** — a `C2PA` beyond the declared size is not found
  (`null`, matching `No claim found`). Whether a size that disagrees with
  the file length should be an error is an open question for the spec:
  c2patool extracts with size +1 and lets M4 reject; fail closed says
  error. To be decided.
- LBox ≠ chunk length → error (as SPEC-002 AC7); chunk length +1 is the
  same check from the other side.
- Position (`C2PA` before `VP8L`) → extract, as c2patool; M4 judges.
- Pad byte missing or non-zero: **proposal: error** — a missing pad makes
  the file end early (an error in any reader that reads the pad), and
  RIFF requires the pad to be zero. Stricter than the oracle; to be
  decided.

## Reasoned, not measured

- That the pad byte must be `00`: the RIFF specification; c2patool does
  not check it (measured), and no writer is known to produce another value.
- That every real C2PA WebP has exactly one `C2PA` after the image chunks:
  true for c2patool's writer; other writers not measured.
