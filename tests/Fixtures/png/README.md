# PNG variants for SPEC-002

Every file here is derived from `../fixture-signed.png` by
`bin/make-png-variants.php` — whole chunks moved, or one field changed,
nothing else. Regenerate with `php bin/make-png-variants.php`; the script
prints each file's SHA-256, and these are the values committed on
2026-09-19. Measured with c2patool 0.27.22 the same day
(`notes/step-04-png-fixture.md`). The SPEC-002 column is filled when the
spec is approved.

| file | what is wrong | c2patool 0.27.22 | SPEC-002 |
|---|---|---|---|
| `not-a-png.bin` | plain text, no signature | `Error: Unsupported file type` | — |
| `truncated-in-cabx.png` | file ends 1,000 bytes into the `caBX` data | `Error: asset could not be parsed: PNG out of range` | — |
| `cabx-after-idat.png` | `caBX` moved after `IDAT` | extracts; `claimSignature.validated`, then `assertion.dataHash.mismatch` → `Invalid` | — |
| `cabx-before-ihdr.png` | `caBX` before `IHDR` (the PNG spec requires `IHDR` first) | extracts; `claimSignature.validated`, then `assertion.dataHash.mismatch` → `Invalid` | — |
| `two-cabx.png` | the same `caBX` chunk twice | `Error: more than one manifest store detected` | — |
| `crc-wrong.png` | one bit flipped in the CRC of `caBX`, data untouched | extracts; **`Valid`** — the CRC is read and discarded | — |
| `length-differs.png` | chunk length field +1, data and CRC untouched | `Error: asset could not be parsed: PNG out of range` (the chunk walk goes off by one and runs off the file) | — |
| `lbox-differs.png` | LBox inside the box +1 (46,026), chunk length 46,025, CRC recomputed | extracts; **`Valid`** — LBox is not compared to the chunk length, and the JUMBF parser tolerates the excess | — |
| `cabx-too-short.png` | a `caBX` of 4 bytes, shorter than a box header | `Error: unexpected end of file` | — |
| `cabx-empty.png` | a `caBX` of length 0 | `Error: No claim found` | — |

SHA-256 (as printed by the script):

```
fee15c8a8be36152c7ad7aad584eb6d1bdbd862235b451e0373700eb2c57c51b  not-a-png.bin
82a5098f82902caa3a022c4fa3f1fabea02019bbb107b4c775a86bf44ee93b8d  truncated-in-cabx.png
f6fdd1441b69c6fe78516848f5cddf941f431e51e8cd88077afbb579f0366750  cabx-after-idat.png
e164a9e2a6c24bca3364bdae6591a811e1914acfeac2d1519968f3262e842486  cabx-before-ihdr.png
03bdd318d183d1fba6168f1d8114b19458dad7927a3c4edf4e0b3ee7b520212b  two-cabx.png
a0cd0b383a314a34a3ab87473db2beb12a5469923edad3b841a06bf214216a99  crc-wrong.png
df094eb23075e98daf2aa7188ffb544447f9a4dd286056dd17cadb9cf7c9bf71  length-differs.png
8563d85c61f38fff5bfd647bc0128a37d54049bea1aed6eb5ec0069ebb8685be  lbox-differs.png
d125f4c3588145b1a49635636dbbab487caef9e04745c3d04a50da934a61bf1c  cabx-too-short.png
3977732b5a86a8372af440f231c4ed833a45bc08c2bb82cf0f0ec2492560467f  cabx-empty.png
```
