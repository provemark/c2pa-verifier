# WebP variants for SPEC-003

Every file here is derived from `../fixture-signed.webp` by
`bin/make-webp-variants.php` — whole chunks moved, or one field changed,
nothing else; the RIFF size in the header is recomputed unless the variant
is about that field. Regenerate with `php bin/make-webp-variants.php`; the
script prints each file's SHA-256, and these are the values committed on
2026-09-20. Measured with c2patool 0.27.22 the same day
(`notes/step-06-webp-fixture.md`). The SPEC-003 column is filled when the
spec is approved.

| file | what is wrong | c2patool 0.27.22 | SPEC-003 |
|---|---|---|---|
| `not-a-riff.bin` | plain text, no `RIFF` | `Error: Unsupported file type` | — |
| `riff-not-webp.webp` | form type `WAVE` instead of `WEBP` | extracts; `claimSignature.validated`, then `assertion.dataHash.mismatch` → `Invalid` — the form type is not checked | — |
| `truncated-in-c2pa.webp` | file ends 1,000 bytes into the `C2PA` data | `Error: asset could not be parsed: RIFF chunk declared size exceeds file size` | — |
| `truncated-between-chunks.webp` | file ends where the `C2PA` chunk header should start; RIFF size still claims the full file | `Error: asset could not be parsed: Invalid RIFF format` | — |
| `two-c2pa.webp` | the same `C2PA` chunk twice | extracts the **first**; `claimSignature.validated`, then `assertion.dataHash.mismatch` → `Invalid` — not an error, unlike PNG | — |
| `c2pa-before-vp8l.webp` | `C2PA` before the image data | extracts; `claimSignature.validated`, then `assertion.dataHash.mismatch` → `Invalid` | — |
| `length-differs.webp` | chunk length +1, data untouched (the pad byte becomes data) | extracts; **`Valid`** — the extra trailing byte is tolerated downstream | — |
| `lbox-differs.webp` | LBox inside the box +1 (100,636), chunk length 100,635 | extracts; **`Valid`** — LBox is not compared to the chunk length | — |
| `riff-size-plus-one.webp` | RIFF size in the header +1 | extracts; `claimSignature.validated`, then `assertion.dataHash.mismatch` → `Invalid` (the header is hashed) | — |
| `riff-size-excludes-c2pa.webp` | RIFF size in the header as if `C2PA` were absent (304) | `Error: No claim found` — the walk stops at the declared size | — |
| `c2pa-too-short.webp` | a `C2PA` of 4 bytes, shorter than a box header | `Error: unexpected end of file` | — |
| `c2pa-empty.webp` | a `C2PA` of length 0 | `Error: No claim found` | — |
| `pad-missing.webp` | the odd-length `C2PA` without its pad byte; RIFF size one less | extracts; `claimSignature.validated`, then `assertion.dataHash.mismatch` → `Invalid` | — |
| `pad-nonzero.webp` | the pad byte `FF` instead of `00` | extracts; `claimSignature.validated`, then `assertion.dataHash.mismatch` → `Invalid` | — |
| `odd-chunk-before.webp` | an unknown 3-byte chunk `XXXX` (+ pad) before `C2PA` | extracts; `claimSignature.validated`, then `assertion.dataHash.mismatch` → `Invalid` — the pad is handled, the moved bytes are not | — |
| `chunk-overruns-file.webp` | `C2PA` length +1,000; RIFF size correct for the file, so only the chunk overruns | `Error: asset could not be parsed: RIFF chunk declared size exceeds file size` | — |

SHA-256 (as printed by the script):

```
27d0eb8cfbe62e2e13ed6700dca8dd8b75bbf669ab16e072741e23f4c055af6c  not-a-riff.bin
ecf071663050044d395839af26ec12404046bab35e6526382c5bc2a6b763e111  riff-not-webp.webp
91d3385d6f71cd35bd7144b7a6fd2ae683bc7a33ef3f81090e3cce050d83e615  truncated-in-c2pa.webp
ff0ac178580f14a251b17e4e9c0e871d7a502b8c9133ee3b7b4eb202b5e955db  truncated-between-chunks.webp
016cddd52d01691bb38cae15f48424256f667adaf8284fbbc2d2df0b830cb727  two-c2pa.webp
927341b6273e359513663facd7dfaced81cf8393424823188178d101c0e1cd38  c2pa-before-vp8l.webp
3b9a8752766767e9b135636700de54b644e725210f864df0c014f75128672717  length-differs.webp
117dd698708ebee96f966d1081522b1bfc875eb9746b661ff0f24d9192236bc3  lbox-differs.webp
035f85ee5123a72706779538f3d7b14da3a0e7f1ce4567a7ba33c0a7b711a4cf  riff-size-plus-one.webp
d78116c8c9e891510d05e52c2cd2adb18919f4f501db0b7efc66cb956dcb568d  riff-size-excludes-c2pa.webp
abd3f49a40a15c20c17079c82182a6de1d3c86faa502e936a37094c01eb125f5  c2pa-too-short.webp
6e06c809bb97848828b8cd84487eccc954769532250502c1e1e3a567c9150bea  c2pa-empty.webp
a4a103e4823484eb55cf2d6ca72a95cffc302153d9c1fd3e82de23a8f24c0049  pad-missing.webp
262e677de9f2b8fb136de10f8ea2803ff63f8d596b30c7755f923bad4b050a8b  pad-nonzero.webp
155d6b74045bff641640238d0035c165e26d9e0d5556fc61e3451af49a1bfafe  odd-chunk-before.webp
b4080c3161f244bfa92ccda219e2a225ef802e84d09ed44fbe413e096bf98f52  chunk-overruns-file.webp
```
