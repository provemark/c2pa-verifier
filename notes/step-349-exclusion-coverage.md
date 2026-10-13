# Step 349 — The data hash may leave out the whole image, measured

*2026-10-13.*

D. Buchanan's "How to Hack Time, With C2PA" (2 October 2026) signs a
`c2pa.hash.data` with one exclusion over the whole file. The hash then
covers nothing, so the image can change under a valid signature and a
valid RFC 3161 timestamp. His example is a lottery ticket edited after the
draw. The timestamp authority is not at fault. The gap is in what gets
hashed: C2PA 2.4 §15.12.1 lets a signer leave out byte ranges, and says
little about how many or where.

This step measures what that means here. It changes no code.

## Read before measuring

SPEC-012 amendment 7 already refuses the article's exact shape: the range
holding the manifest store may hold only the store (C2PA 2.4 §15.12.1.1).
Every other range is honoured as signed and reported with
`assertion.dataHash.additionalExclusionsPresent`, which is informational
(VAL-ASSE-0045). So the reasoning was that the same effect survives as
three ranges: before the store, the store, after the store.

## Built

`bin/make-exclusion-coverage-variants.php` edits the PNG fixture's store
in the way `bin/make-actions-label-variants.php` (step 281) does, and
re-signs the claim with a throw-away P-256 hierarchy. The keys are deleted
at the end of the run. It writes six files to
`tests/Fixtures/exclusion-coverage/` (see the README there) and pins both
`c2patool` answers.

## Measured

`php bin/make-exclusion-coverage-variants.php <scratch> <c2patool 0.28.1> <c2patool 0.28.2>`,
under the throw-away root as the only anchor:

| file | bytes hashed | c2patool 0.28.1 / 0.28.2 | this verifier (0.6.1) |
|---|---|---|---|
| `control-resigned` | 1,699 of 47,736 | `Trusted` | `Trusted` |
| `whole-file` | 0 | `Invalid`, `assertion.dataHash.mismatch` | `Invalid`, `assertion.dataHash.mismatch` |
| `all-but-store` | 0 | `Trusted` + informational | `Trusted` + informational |
| `all-but-store-pixel-edited` | 0 | `Trusted` + informational | `Trusted` + informational |
| `idat-excluded` | 66 | `Trusted` + informational | `Trusted` + informational |
| `idat-excluded-pixel-edited` | 66 | `Trusted` + informational | `Trusted` + informational |

Three things follow.

1. **The article's literal shape is refused, here and in `c2patool`
   0.28.x, for PNG.** `c2patool` says "data hash exclusion does not match
   the manifest location in the asset". This verifier gives SPEC-012
   amendment 7's explanation (33 bytes before the store, 1,666 after it).
2. **The three-range shape is a wrong `Trusted` in all three.** The image
   data of `all-but-store-pixel-edited` changed after signing, and nothing
   but an informational code says so.
3. **Leaving out only the image data does the same.** `idat-excluded`
   hashes 66 bytes (the PNG signature, IHDR, pHYs and IEND) and none of the
   pixels.

## Not measured

- JPEG, WebP and the other formats. The article does not name its
  format. `c2patool`'s refusal of `whole-file` was measured for PNG only.
- BMFF, where an exclusion is a box path (`mdat` left out). Not built.
- The Go verifier and `c2pa-web`. `c2pa-web` 0.15.3 equals `c2patool`
  0.28.1 code for code over the drift-alarm corpus (step 348), but that
  says nothing about these files.

## Next

A spec, `draft`, for Maurice to choose between: (a) refusing a data hash
that covers zero bytes, which matches the article's "trivial to detect"
advice; or (b) refusing, per format, an exclusion over the image data
itself (PNG `IDAT`, JPEG scan data, WebP `VP8*`, BMFF `mdat`). Either is a
deliberate divergence from `c2patool`, like amendment 7.
