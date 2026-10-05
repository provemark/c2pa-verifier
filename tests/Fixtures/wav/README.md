# WAV variants (step 204)

Every file here is derived from `../fixture-signed.wav` by
`bin/make-wav-variants.php`. Each variant moves whole chunks or changes one
field, and nothing else. The RIFF size in the header is recomputed, except
in the variants that are about that field. The first eighteen follow the
WebP variants of SPEC-003 (`../webp/README.md`) case by case. The last three
are WAV's own. Regenerate with `php bin/make-wav-variants.php`. The script
prints each file's SHA-256; the values below are the ones committed on
2026-10-05.

The files were measured with `c2patool` 0.27.22 and 0.28.1 on the same day,
without trust settings (`notes/step-204-wav-measured.md`). Since SPEC-055 (step 208)
this verifier reads them; the last column names the criterion each file
exercises and what this verifier answers.

| file | what is wrong | `c2patool` 0.27.22 | `c2patool` 0.28.1 | SPEC-055 |
|---|---|---|---|---|
| `riff-form-xxxx.wav` | form type `XXXX` instead of `WAVE` | `Invalid`: signature validated, `assertion.dataHash.mismatch` (the form type is not checked; the header is hashed) | the same | AC3 error (extractor); AC14 `unknown` |
| `truncated-in-c2pa.wav` | file ends 1,000 bytes into the `C2PA` data | `Error: asset could not be parsed: RIFF chunk declared size exceeds file size` | the same | AC4 error |
| `truncated-between-chunks.wav` | file ends where the `C2PA` chunk header should start; RIFF size still claims the full file | `Error: asset could not be parsed: Invalid RIFF format` | the same | AC4 error |
| `two-c2pa.wav` | the same `C2PA` chunk twice, at the end | `Invalid`: signature validated, `assertion.dataHash.mismatch` | the same | AC6 error (stricter than the oracle) |
| `c2pa-before-data.wav` | `C2PA` before `data`, so not the last chunk | `Invalid`: signature validated, `assertion.dataHash.mismatch` | the same, with two `mismatch` entries: *exclusion does not match the manifest location* and *hashes do not match* | AC7 extracts; `Invalid`, `assertion.dataHash.mismatch` |
| `c2pa-first.wav` | `C2PA` as the first chunk | as `c2pa-before-data.wav` | as `c2pa-before-data.wav` | AC7 extracts; `Invalid`, `assertion.dataHash.mismatch` |
| `chunk-after-c2pa.wav` | an unknown 4-byte chunk after `C2PA`, so not the last chunk | `Invalid`: signature validated, `assertion.dataHash.mismatch` | the same | AC7 extracts; `Invalid`, `assertion.dataHash.mismatch` |
| `c2pa-in-list.wav` | `C2PA` nested inside the `LIST` chunk, not at the top level | `Error: No claim found` | the same | AC15 no store (`hasManifest` false) |
| `length-differs.wav` | chunk length +1, data untouched (the pad byte becomes data) | **`Valid`** | `Invalid`: `assertion.dataHash.match` **and** `mismatch`, *exclusion does not match the manifest location* | AC9 error |
| `lbox-differs.wav` | LBox inside the box +1, chunk length untouched | **`Valid`** | **`Valid`** | AC8 error (stricter than both versions) |
| `riff-size-plus-one.wav` | RIFF size in the header +1 | `Invalid`: signature validated, `assertion.dataHash.mismatch` (the header is hashed) | the same | AC4 error |
| `riff-size-excludes-c2pa.wav` | RIFF size as if `C2PA` were absent | `Error: No claim found` (the walk stops at the declared size) | the same | AC4 error |
| `c2pa-too-short.wav` | a `C2PA` of 4 bytes, shorter than a box header | `Error: unexpected end of file` | the same | AC10 error |
| `c2pa-empty.wav` | a `C2PA` of length 0 | `Error: No claim found` | the same | AC10 error |
| `pad-missing.wav` | the odd-length `C2PA` without its pad byte; RIFF size one less | `Invalid`: signature validated, `assertion.dataHash.mismatch` | the same | AC11 error |
| `pad-nonzero.wav` | the pad byte `FF` instead of `00` | `Invalid`: signature validated, `assertion.dataHash.mismatch` (the pad byte is hashed) | the same | AC11 error |
| `odd-chunk-before.wav` | an unknown 3-byte chunk (+ pad) before `C2PA` | `Invalid`: signature validated, `assertion.dataHash.mismatch` | the same, with two `mismatch` entries | AC12 extracts; `Invalid`, `assertion.dataHash.mismatch` |
| `chunk-overruns-file.wav` | `C2PA` length +1,000; RIFF size correct for the file | `Error: asset could not be parsed: RIFF chunk declared size exceeds file size` | the same | AC5 error |
| `trailing-bytes.wav` | 100 bytes after the end of the RIFF chunk, RIFF size unchanged | `Invalid`: signature validated, `assertion.dataHash.mismatch` (bytes after the RIFF chunk are hashed) | the same | AC4 error |
| `second-riff.wav` | a second RIFF chunk after the first, holding a copy of `C2PA` | `Invalid`: signature validated, `assertion.dataHash.mismatch` | the same | AC4 error |
| `rf64.wav` | the 64-bit form: `RF64`, size `FFFFFFFF`, a `ds64` chunk first | `Error: error parsing RIFF: invalid file signature: invalid header: expected "RIFF", got "RF64"` | `Error: asset could not be parsed: invalid header: expected "RIFF", got "RF64"` | AC14 `unknown` |

SHA-256 (as printed by the script):

```
1956647e1e8dd72ae0c58b442de0b63310c42958bc1ecdefd1ffd82cd989b6f5  riff-form-xxxx.wav
f3333ea9ce291f000ffc12f1afefa6cf264edbcb82e8bd41210d005b2206b770  truncated-in-c2pa.wav
8e881feccf59d5b6b633ddc7f34c4b1a093793541df8207ec9d858cc00f1f7a7  truncated-between-chunks.wav
288af5a736e5aca24e63a3ceb1baa9cf4439a362857d791cecff92ce59772688  two-c2pa.wav
d135f03dda245b6fad816913b93140a52c346ab0404bac135883be5437f5655f  c2pa-before-data.wav
79fe14cc90d660b4788f2e72d9d5d054756ec091da7412f2927caf2a85371441  c2pa-first.wav
e4cc2a930ff1e4bcd133f894a974e2fddb3b3d61974a45d0451abd6044c76fe0  chunk-after-c2pa.wav
57b43d282e4b9be4bfcb2cfbf610af1185140f4d418773f0a116acd931d721e5  c2pa-in-list.wav
cabbbe26779f9a0472320efe2960e3405d358ec07851a58a07fced169993d781  length-differs.wav
0f1dc39cf2b8a43a90f5e7b72a3c2a42b779560e64dde1e4e05ee6456e6e6ee1  lbox-differs.wav
e005c1c6d4de44f2749260e781eee1840a9fc2de34e231c6a4e3a28f7e133a1d  riff-size-plus-one.wav
9cc4266c1a5dfdeeafcffad5983adafd44d61c5d9f630e02df27b623fa96207c  riff-size-excludes-c2pa.wav
e163b157e23068f11dd4183311fe067c08aebd345c7ba1cbe017f595b0a98a90  c2pa-too-short.wav
8d267e49539b84536cb7146db08ba5a46ed0bb694bae0595aba081f0e6ff26ff  c2pa-empty.wav
eaef88b10b309c0113118e636944b4a9b373c887efa7860d8c0c5d2e66b3d43d  pad-missing.wav
e22b841f2e6018a7dc961b1c296b6836b5112abcc4acb4a81c211fb44fa1b229  pad-nonzero.wav
f07249182a8ff33a4ab0f3c60700f488baa64bacd57f6461ce9ca91c5ebf329f  odd-chunk-before.wav
094da5e765d09c7931c525cd294fa8a6fa337b30bc4e4ff64ecb9e99fff0d931  chunk-overruns-file.wav
e0be33bb41718620eb108d4c8a6dba08f80efe2b6c330509e1266da185e69ec2  trailing-bytes.wav
05526407814b2d9b705ce9a288b0782c2daf559d8d67ce19cfdf5bd4c0b46b95  second-riff.wav
916ce4000c51e68752af193c796953da6fb42d0f3340e5dbe037c09dac455201  rf64.wav
```
