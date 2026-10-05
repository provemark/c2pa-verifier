# Step 222 — `has_manifest` from `storeReached` for JPEG, PNG and ISOBMFF: proposed

*2026-10-05. SPEC-001 amendment 5, SPEC-002 amendment 3, SPEC-026
amendment 3, SPEC-013 amendment 18, all proposed. Nothing built.*

## Why

Step 221: an unsigned JPEG, PNG or MP4 with a container fault reports
`has_manifest: true`. The verdict agrees with `c2patool` in effect
(`Invalid` here, an error there), but the flag says the file has Content
Credentials that failed, which it does not have. Maurice chose the flag
alone (step 221's advice, point 1); relaxing the ISOBMFF walk is not part
of it.

## When the store is reached

| container | reached when |
|---|---|
| JPEG | an APP11 segment's header has been read and starts with `JP` |
| PNG | a `caBX` chunk header has been read |
| ISOBMFF | a top-level `uuid` box carries the C2PA UUID (read before the size check, when inside the file) |

## Files whose report changes

Found by verifying every JPEG, PNG and ISOBMFF file in `tests/Fixtures/`
whose report is one `general.error` with `has_manifest: true`, and
placing the fault's offset against the store's. JUMBF and manifest faults
(the `jumbf/` files, for instance) are not container faults and do not
change. Proposed: `has_manifest` true → false, nothing else.

| file | the fault |
|---|---|
| `jpeg/rst-before-sos.jpg` | marker `FF D0` at offset 20, before the APP11 at 22 |
| `jpeg/truncated-between-segments.jpg` | the file ends at offset 20, no APP11 |
| `jpeg/truncated-in-app0.jpg` | the file ends inside APP0 |
| `png/truncated-between-chunks.png` | the file ends at 33, where `caBX` would start |
| `isobmff/largesize-missing.mp4` | a box at 32 declares more than the 28-byte file |

And step 221's unsigned built files with a real fault (JPEG with an APP1
length past the end, PNG cut in half and with a chunk length past the end,
MP4 with garbage appended, with a box past the end, cut in half). Every
file whose store is reached keeps `has_manifest: true`, among them
`isobmff/size-past-end.mp4`, whose C2PA box runs past the end.

## Next, after approval

Tests first, seen red; then the three extractors and the verifier (which
already reads `storeReached`); then the corpus, which may only move the
files above.
