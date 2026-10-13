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
changed after signing, and all three verifiers say `Trusted`.
