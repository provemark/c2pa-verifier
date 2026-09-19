# JPEG variants for SPEC-001

Every file here is derived from `../fixture-signed.jpg` by
`bin/make-jpeg-variants.php` — whole segments moved, or one field changed,
nothing else. Regenerate with `php bin/make-jpeg-variants.php`; the script
prints each file's SHA-256, and these are the values committed on
2026-09-19. Measured with c2patool 0.27.22 the same day.

| file | what is wrong | c2patool 0.27.22 | SPEC-001 |
|---|---|---|---|
| `swapped-pieces.jpg` | piece 2 before piece 1 | `Error: invalid embedded file box` | AC3 error |
| `gap-between-pieces.jpg` | COM segment between the pieces | extracts; `claimSignature.validated`, then `assertion.dataHash.mismatch` | AC4 extracts |
| `truncated-in-piece-2.jpg` | file ends 1,000 bytes into piece 2 | `Error: asset could not be parsed: Could not parse input JPEG` | AC5 error |
| `missing-piece-2.jpg` | piece 2 removed, LBox still 94,740 | `Error: invalid embedded file box` | AC6 error |
| `lbox-differs.jpg` | piece 2's LBox 94,740 → 94,741 | `Error: invalid embedded file box` | AC7 error |
| `app11-not-jp.jpg` | an APP11 with `XX` instead of `JP` before piece 1 | extracts; then `assertion.dataHash.mismatch` | AC8 extracts |
| `not-a-jpeg.bin` | plain text, no `FF D8` | `Error: Unsupported file type` | AC10 error |
| `two-instance-numbers.jpg` | piece 2's En 529 → 530 | `Error: invalid embedded file box` | AC11 error |
| `pieces-after-sos.jpg` | both pieces after the scan data | `Error: No claim found` | AC13 null |

SHA-256 (as printed by the script):

```
17a294c77d72ee2bd99f9184f30ddd368a923a64859dffff9cc26249e3d4ab5c  swapped-pieces.jpg
571f2dee89b48b3dfd3049877b2098872ed79e7d700a246322b421d6e2fa13c6  gap-between-pieces.jpg
f3b912060d880cde5e912384c249c05ecb9e63923ac4c29aa5e1168dfbcb9727  truncated-in-piece-2.jpg
289a7b6ab7c98cf4e954402bef697ada3cd215ed4fce11b76a2facbefacda3f2  missing-piece-2.jpg
d5e2254cce324298820e06c408a5b1cddc9a2bbbf223607b97d6fda76ecce814  lbox-differs.jpg
97c53f9c596c7e25e19b73f9d9bb8f6d0b826b0538068ce331a9123e5c0203fe  app11-not-jp.jpg
cee0c928aca558f017ea158b420f88e8731786c495c97370c7c10d2d2334592d  not-a-jpeg.bin
ea08c3c3d66a468f4671d03de4dee4303fb20465443ab29f72417d7d593f2dd7  two-instance-numbers.jpg
0aa264e285b9b62d9b40c54887d54511a41af4eb5cf5f77b7fa91fedb157f6ef  pieces-after-sos.jpg
```
