# How much of the file may the data hash leave out? (step 349)

Built by `bin/make-exclusion-coverage-variants.php <scratch> <c2patool-0.28.1>
<c2patool-0.28.2>` on 2026-10-13 (step 349). Each PNG is
`../fixture-signed.png` with the exclusion list of its `c2pa.hash.data`
replaced, the hash and the claim's hashed URI recomputed, and the claim
re-signed by a throw-away P-256 leaf under `throw-away-root.pem`. The keys
lived in a scratch directory while the script ran and were deleted. No
private key is here. Neither `c2patool` version writes such exclusions.

The trigger: D. Buchanan, "How to Hack Time, With C2PA" (2 October 2026),
which signs one exclusion over the whole file.

| file | exclusions (PNG offsets: start, length) | bytes hashed | 0.28.1 | 0.28.2 | this verifier (0.6.1) |
|---|---|---|---|---|---|
| `control-resigned` | `[33, 46037]` (the store) | 1,699 of 47,736 | `Trusted` | `Trusted` | `Trusted` |
| `whole-file` | `[0, 47735]` | 0 of 47,735 | `Invalid`, `assertion.dataHash.mismatch` ("exclusion does not match the manifest location") | the same | `Invalid`, `assertion.dataHash.mismatch` (SPEC-012 amendment 7) |
| `all-but-store` | `[0, 33]`, `[33, 46074]`, `[46107, 1666]` | 0 of 47,773 | `Trusted` + informational `additionalExclusionsPresent` | the same | the same |
| `all-but-store-pixel-edited` | the same file, one IDAT byte changed after signing | 0 | `Trusted` + informational | the same | the same |
| `all-but-one-byte` (step 351) | `[0, 33]`, `[33, 46074]`, `[46107, 1665]`: the last byte of IEND's CRC hashed | 1 of 47,773 | `Trusted` + informational | the same | the same (SPEC-068 AC3: the floor) |
| `idat-excluded` | `[33, 46057]`, `[46111, 1633]` (IDAT up to IEND) | 66 of 47,756 | `Trusted` + informational | the same | the same |
| `idat-excluded-pixel-edited` | the same file, one IDAT byte changed after signing | 66 | `Trusted` + informational | the same | the same |

All under `throw-away-root.settings.json`. The answers are under
`../c2patool/exclusion-coverage/<file>--<version>.json`. Every run makes a
new hierarchy, so the files' hashes differ per run; the shape does not.

The two `-pixel-edited` files are the wrong `Trusted`: the image data
changed after signing, and all three verifiers say `Trusted` (0.6.1).

## Step 352: JPEG (SPEC-069 AC4)

`fixture-signed.jpg` with an Exif APP1 (20 bytes of payload: an empty IFD0)
and an ICC APP2 (a blank 142-byte payload) put between its JFIF APP0 and
its store. The store is rewritten into APP11 segments laid out as C2PA 2.4
§A.3.4 lays them out, then re-signed.

| file | exclusions (start, length) | 0.28.1 | 0.28.2 |
|---|---|---|---|
| `jpeg-exif-allowed` | `[24, 20]` (exactly the Exif payload), the store | `Trusted` | `Trusted` |
| `jpeg-app1-header-excluded` | `[20, 24]` (the whole Exif segment), the store | `Trusted` | `Trusted` |
| `jpeg-icc-excluded` | `[48, 142]` (the ICC payload), the store | `Trusted` | `Trusted` |
| `jpeg-scan-excluded` | the store, `[95371, 64]` (scan data) | `Trusted` | `Trusted` |

## This verifier after SPEC-068 and SPEC-069 (steps 351, 352)

| file | answer |
|---|---|
| `control-resigned`, `jpeg-exif-allowed` | `Trusted` (the second with the informational, naming "APP1 Exif payload") |
| `whole-file` | `Invalid`: SPEC-012 amendment 7 |
| `all-but-store`, `all-but-store-pixel-edited` | `Invalid`: SPEC-068, no byte hashed |
| `all-but-one-byte`, `idat-excluded`, `idat-excluded-pixel-edited` | `Invalid`: SPEC-069, PNG allows no exclusion beyond the store |
| `jpeg-app1-header-excluded`, `jpeg-icc-excluded`, `jpeg-scan-excluded` | `Invalid`: SPEC-069, naming the segment or the scan the range falls in |
