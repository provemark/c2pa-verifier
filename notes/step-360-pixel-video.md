# Step 360 — SPEC-072: Pixel Camera video verifies

*2026-10-13.*

Maurice approved SPEC-072 with option (a): the Pixel file stays out of the
repository, CI uses a synthetic file of its shape, and the change goes in
0.7.0.

## What changed

- `IsobmffManifestStoreExtractor`: a `meta` box whose bytes 12–15 are
  `hdlr` is read the QuickTime way, with its children right after the
  header (`metaSkip()`). Every box carries `ipath`, its path with 1-based
  indices among same-type siblings. `StreamReader::seek()` is new, for
  the look ahead.
- `BmffHashCheck`: xpaths with indexed steps are matched (`pathMatches()`).
  A malformed index is `assertion.bmffHash.malformed` (`shapeFault()`).
  Pixel's `/moov/free`, `/moov/udta` and `/moov/meta` join the allow-list.
  `/moov[1]/trak[n]` is allowed by path, and after the box walk only when
  that track's `mdia/hdlr` says `meta` (`trackRefusal()`). The
  informational says that `udta`, `meta` and the metadata track are not
  signed.
- `bin/make-pixel-shape-variants.php` builds `tests/Fixtures/pixel-shape/`.

## Measured

- Tests first: 11 failed before, green after.
- The real Pixel file (`C2PA_PIXEL_VIDEO`): `assertion.bmffHash.match`,
  `Valid`, as `c2patool` 0.28.2. It was a wrong `Invalid` before.
- `pixel-shape.mp4`: the digest computed here is confirmed by `c2patool`
  0.28.1 and 0.28.2 (`Trusted`, `bmffHash.match`).
- **Found:** `c2patool` 0.28.x also accepts `pixel-shape-video-track.mp4`,
  which excludes the video track by index (`Trusted`). This verifier
  refuses it. It is the same class as steps 349 and 356.
- Mutations caught: `meta` always read as ISO, the index ignored, any
  handler accepted, a malformed index not refused, no unsigned sentence.
- Corpus before and after (every fixture outside the new directories, two
  modes): identical, including the four HEIC/AVIF files with an ISO
  `meta`.
- `composer check`: 1,109 passed, 1 skipped (AC1 without the variable).
