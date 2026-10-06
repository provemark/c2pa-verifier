# Step 256 — GIF measured

*2026-10-06. The next format after WAV, MP3, FLAC and AVI (the order of
step 203). Measurement only; no spec, no code in `src/`.*

## What C2PA 2.4 says (§A.3.8, read)

One Application Extension block (`21 FF 0B`), identifier `C2PA_GIF`, the
authentication code used as the block's version (`01 00 00`, version 1.0),
the store split into data sub-blocks of at most 255 bytes and ended by
`00`; "Quantity: One"; "after the header and prior to the first image
descriptor".

## What c2patool writes (measured)

`fixture-unsigned.gif` is the sister library's `fixture.gif`: 64×64,
GIF89a, a global colour table, a NETSCAPE2.0 and a graphic control
extension, one image. Signed with `c2patool` 0.27.22 (the fixture) and
0.28.1 (for comparison) and the WAV manifest definition retitled:

- Both insert the block **first, right after the global colour table**
  (offset 781), before NETSCAPE2.0; nothing else in the file changes: the
  signed file is the unsigned one with the block inserted.
- 0.27.22: 130,681 bytes, 511 sub-blocks of 255 and one of 105, the store
  130,155 bytes (it carries a thumbnail; 0.28.1's is about 10 KB smaller,
  padding, as for WAV).
- The data hash has **one exclusion, the whole block**, from `0x21` to the
  terminator: `[781, 130681]` (0.28.1: `[781, 120705]`). Unlike MP3's GEOB,
  the block's own header and the sub-block size bytes are excluded, not
  hashed.
- Each version reads both files `Valid`, `signingCredential.untrusted`;
  `Trusted` with `trust/full.settings.json`.

## The variants (measured)

Eighteen, by `bin/make-gif-variants.php`; every answer is in
`tests/Fixtures/gif/README.md`. Both versions give the same verdict on
every one; 0.28.1 reports the hash mismatch twice on two of them.

- **Read and judged by the hash** (`Invalid`, `assertion.dataHash.mismatch`):
  a second identical block (`two-c2pa`), the block moved after NETSCAPE2.0
  but still before the image, the store re-split into 100-byte sub-blocks,
  a file cut right after the block, no trailer, 16 bytes after the trailer,
  a `GIF87a` header, one image byte flipped. One store byte flipped:
  `assertion.hashedURI.mismatch`.
- **No claim found**: the block after the image; authentication code
  `02 00 00` or `01 01 00`; identifier `C2PA_GIX`; a block with no
  sub-blocks.
- **An error**: block size `0x0C` ("Invalid block size for app block
  extension 12!=11"), a payload that is not JUMBF, a sub-block size set to
  0 mid-store, a file cut inside the block.
- A first try had a variant that copied the signed file's block into the
  unsigned file; it was byte for byte the signed fixture (the block is only
  inserted), `Valid` for that reason, and was dropped.

This verifier today: `unknown`, `Invalid`, `general.error` on every one,
the signed fixture included.

## What it means for a spec (reasoned)

- **No new hash check.** `c2pa.hash.data` with one exclusion; the reader
  returns `ManifestStoreBytes` whose range is the whole block, and SPEC-012
  requires the exclusion to equal it.
- **The walk stops at the first image descriptor.** `c2patool` does not
  look after it; neither need this reader, which then never walks image
  data: blocks before the first image are few (extensions, each a short
  header and sub-blocks), so a bound on their number and on the store's
  size (16 MiB, as every container) keeps it bounded.
- **The block is recognised by identifier and version together.**
  `c2patool` ignores a `C2PA_GIF` block of another version (no claim);
  doing the same is "no manifest", not a wrong verdict.
- **Two `C2PA_GIF` blocks**: `c2patool` reads the first and the hash
  catches the second. Refusing two (`general.error`), as SPEC-003 and
  SPEC-056 refuse a second store, is stricter and would be named.
- **A wrong block size, a payload that is not JUMBF, a store cut short**:
  errors in `c2patool`; `general.error` here, with `has_manifest` true once
  the block's header was read (SPEC-013 amendments 16–18).
- **Detection**: `GIF87a` and `GIF89a` both; `c2patool` reads the block
  under either.

Next, on Maurice's word: SPEC-059 (GIF) as a draft.
