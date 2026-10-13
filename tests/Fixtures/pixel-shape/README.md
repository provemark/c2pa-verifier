# Files of the Pixel Camera video's shape (step 360, SPEC-072)

The real Pixel Camera video (the attachment to `c2pa-rs` issue #2373, sha256
`2f1c2bd8a056f86d6bd6d3b7e34c600104cdfe73999c5c94525c31b04d9774d6`) has no
licence and is not in this repository. Its test runs locally when
`C2PA_PIXEL_VIDEO` names it.

These files stand in for it in CI. Built by
`bin/make-pixel-shape-variants.php <c2patool-0.28.1> <c2patool-0.28.2>`
from `../fixture-signed.mp4`. Inside `moov` it adds a QuickTime-style
`meta` (an `hdlr` of type `mdta` first, no version and flags), a `udta`
with a location (`©xyz`), a `free`, and two copies of the track, the third
with handler `meta`. The `c2pa.hash.bmff.v3` gets Pixel's exclusion list,
and the claim is re-signed under `probe-root.pem` (throw-away keys,
deleted after the run). The digest is computed by this verifier and
confirmed by `c2patool`. Sample offsets move, so the files do not play;
no hash check looks at that.

| file | the indexed track excluded | c2patool 0.28.1 | 0.28.2 | this verifier |
|---|---|---|---|---|
| `pixel-shape` | `/moov[1]/trak[3]` (handler `meta`) | `Trusted`, `bmffHash.match` | the same | `Trusted`, `bmffHash.match` + the informational |
| `pixel-shape-video-track` | `/moov[1]/trak[1]` (handler `vide`) | `Trusted` | `Trusted` | `Invalid`: the video track may not be excluded |
| `pixel-shape-no-track` | `/moov[1]/trak[9]` (no such track) | `Trusted` | `Trusted` | `Invalid` |
