# A Merkle tree over the mdat of a non-fragmented MP4 (SPEC-080)

Built by `bin/make-mdat-merkle-variants.php <scratch> <c2patool-0.28.3>
<c2patool-0.28.1>` on 2026-10-25 (step 446) and again on 2026-10-26 with four more files (step 448), with ffmpeg 9.0.2. `c2patool`
0.28.3 signed with `core.merkle_tree_chunk_size_in_kb` and a throw-away
P-256 hierarchy; the keys lived in a scratch directory and were overwritten
and deleted. `throw-away-root.pem` is the public root, and
`throw-away-root.settings.json` holds it as the legacy `trust_anchors`
string with `store.cfg`.

A re-signed file had its BMFF hash assertion edited, every enclosing length
adjusted, the file `hash` computed again (it binds the offset of every box it
hashes, and a manifest of another length moves them), the claim's hashed URI
re-hashed and the claim signed again with the same leaf. `c2patool` reporting
no file level mismatch for those files is the check on the recomputed
`hash`. Their `stco` chunk offsets were not moved with the media: a player
would notice, neither verifier reads them.

| file | what it is | 0.28.3 | 0.28.1 | `c2patool`'s reason |
|---|---|---|---|---|
| `six-blocks.mp4` | three times `fixture-unsigned.mp4` (ffmpeg `-stream_loop 2 -c copy`), signed with `merkle_tree_chunk_size_in_kb` 1: an mdat of 5,618 bytes, six blocks (five of 1,024, one of 482), the root stored, six proof boxes (AC1) | Trusted | Trusted |  |
| `one-block.mp4` | the same source with 8 KB blocks: count 1, the leaf stored, no proof boxes (AC2) | Trusted | Trusted |  |
| `two-mdat.mp4` | the source with its mdat cut in two boxes before signing; `c2patool` writes two merkle maps, `localId` 0 and 1 (AC8). Its verdict varies from run to run, see the note | varies | varies | `Trusted`, or "chunk hash did not match" |
| `block-0-changed.mp4` | `six-blocks`, one byte of block 0 changed (AC3) | Invalid | Invalid | chunk hash did not match |
| `block-5-changed.mp4` | one byte of the last, short block changed (AC3) | Invalid | Invalid | chunk hash did not match |
| `gap-changed.mp4` | byte 12 of the mdat changed: after the header, before the first block, under the file `hash` (AC3) | Invalid | Invalid | BMFF file level hash mismatch |
| `moov-changed.mp4` | one byte of `mvhd`'s modification time changed (AC3) | Invalid | Invalid | BMFF file level hash mismatch |
| `proof-sibling-changed.mp4` | a sibling hash in the proof box of block 0 changed (AC4) | Invalid | Invalid | chunk hash did not match |
| `proof-location-moved.mp4` | the proof box of block 0 says location 2 (AC4, AC6: a location twice) | Invalid | Invalid | MerkleMap and MerkleBoxInfo mismatch |
| `proof-location-past-count.mp4` | the proof box of block 5 says location 6, past the count (AC6) | Invalid | Invalid | MerkleMap and MerkleBoxInfo mismatch |
| `proof-removed.mp4` | the proof box of block 2 removed; the boxes after it move (AC4) | Invalid | Invalid | BMFF file level hash mismatch |
| `proof-freed.mp4` | the proof box of block 2 turned into a `free` box of the same size: nothing moves, five proofs for six blocks (AC4) | Invalid | Invalid | MerkleMap count incorrect |
| `proof-duplicated.mp4` | the proof box of block 2 twice; the boxes after it move (AC4) | Invalid | Invalid | BMFF file level hash mismatch |
| `fixed-and-variable.mp4` | re-signed: `variableBlockSizes` added beside `fixedBlockSize` (AC6) | Invalid | Invalid | C2PA Validation Error: MerkleMap block type must be either fixed or variable or both none |
| `block-size-text.mp4` | re-signed: `fixedBlockSize` the text "1024" (AC6) | error: cannot decode | error: cannot decode | invalid type: string "1024", expected u64 |
| `block-size-one.mp4` | re-signed: `fixedBlockSize` 1 (AC6) | Invalid | Invalid | fixed block size must be positive integer |
| `block-size-huge.mp4` | re-signed: `fixedBlockSize` 2^63 − 1 (AC10, step 508) | Invalid | Invalid | number of hash ranges does not match BmffMerkleMap count |
| `count-five.mp4` | re-signed: `count` 5 for six blocks (AC6) | Invalid | Invalid | number of hash ranges does not match BmffMerkleMap count |
| `init-hash.mp4` | re-signed: an `initHash` of 32 zero bytes added (AC6) | Invalid | Invalid | BMFF inithash must not be present for non-fragmented media |
| `hashes-four.mp4` | re-signed: `hashes` four times the root, a row the six-leaf tree does not have (AC6) | Trusted | Trusted |  |
| `mdat-whole.mp4` | re-signed: the `/mdat` exclusion without its `subset`, the whole box (AC7) | Trusted | Trusted |  |
| `mdat-offset-24.mp4` | re-signed: the `/mdat` subset from offset 24 (AC7) | Trusted | Trusted |  |
| `mdat-length-1.mp4` | re-signed: the `/mdat` subset from offset 16, length 1 (AC7) | Trusted | Trusted |  |
| `variable-sizes.mp4` | re-signed: `fixedBlockSize` replaced by `variableBlockSizes` [1024 ×5, 482] (AC8) | Trusted | Trusted |  |
| `no-block-field.mp4` | re-signed: `fixedBlockSize` removed, neither block field (AC8) | Invalid | Invalid | Merkle location not found |
| `local-id-one.mp4` | re-signed (step 448): the merkle map's `localId` 1 (AC6) | Trusted | Trusted |  |
| `row-of-six.mp4` | re-signed (step 448): `hashes` the six leaves, the proof boxes turned into `free` boxes of the same size (AC2) | Trusted | Trusted |  |
| `row-of-three.mp4` | re-signed (step 448): `hashes` the row of three between the root and the leaves, the proof boxes turned into `free` (AC6) | Invalid | Invalid | chunk hash did not match |
| `two-mdat-one-map.mp4` | re-signed (step 448): `two-mdat` with its second merkle map removed (AC8) | Invalid | Invalid | number of MerkleMaps does not match number of mdat boxes |

The answers are under `../c2patool/mdat-merkle/<file>--<version>.json`
(for `two-mdat`, the answer of one run). Certificates are valid for ten
years from the build.
