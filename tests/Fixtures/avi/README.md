# AVI variants (step 209)

Built by `bin/make-avi-variants.php` from `../fixture-signed.avi` (one
RIFF chunk) and from `signed-avix.avi` (two RIFF chunks). `unsigned-avix.avi`
is written by the script: the unsigned fixture followed by a second RIFF
chunk of form `AVIX` holding a `movi` list with one 100-byte frame, as an
OpenDML AVI over 1 GB continues. `signed-avix.avi` is that file signed with
`c2patool` 0.27.22 and the c2pa-rs ES256 test certificates (the command is
in `notes/step-209-avi-measured.md`). The script builds the two-RIFF
variants only when it is present. Measured with `c2patool` 0.27.22 and
0.28.1 on 2026-10-05, without trust settings. Since SPEC-058 (step 241)
this verifier reads them: each gives `c2patool`'s answer, or the named
stricter one of SPEC-003 (`two-c2pa`, `lbox-differs`, `pad-nonzero`);
`tests/Unit/Verifier/AviTest.php` holds every file.

| file | what is wrong | `c2patool` 0.27.22 | `c2patool` 0.28.1 |
|---|---|---|---|
| `unsigned-avix.avi` | nothing: two RIFF chunks, no store | `Error: No claim found` | the same |
| `signed-avix.avi` | nothing: the store is the last chunk of the first RIFF chunk, `AVIX` after it | **`Valid`** | **`Valid`** |
| `avix-byte-flipped.avi` | one byte of the `AVIX` frame flipped | `Invalid`: `assertion.dataHash.mismatch` (the second RIFF chunk is hashed) | the same |
| `avix-truncated.avi` | the file cut 10 bytes into `AVIX` | `Invalid`: `assertion.dataHash.mismatch` (no parse error: `AVIX` is not walked) | the same |
| `avix-size-plus-one.avi` | the `AVIX` size +1, file unchanged | `Invalid`: `assertion.dataHash.mismatch` | the same |
| `avix-trailing-bytes.avi` | 100 bytes after `AVIX` | `Invalid`: `assertion.dataHash.mismatch` | the same |
| `second-form-avi.avi` | the second RIFF chunk's form `AVI ` instead of `AVIX` | `Invalid`: `assertion.dataHash.mismatch` | the same |
| `avix-with-c2pa.avi` | a copy of the `C2PA` chunk inside `AVIX` as well | `Invalid`: `assertion.dataHash.mismatch` (the first store is read; the copy is hashed) | the same |
| `c2pa-only-in-avix.avi` | the `C2PA` chunk moved from the first RIFF chunk into `AVIX` | `Error: No claim found` (only the first RIFF chunk is searched) | the same |
| `two-c2pa.avi` | the same `C2PA` chunk twice, at the end | `Invalid`: `assertion.dataHash.mismatch` | the same |
| `c2pa-before-idx1.avi` | `C2PA` before `idx1`, so not the last chunk | `Invalid`: `assertion.dataHash.mismatch` | the same, with two `mismatch` entries |
| `c2pa-in-movi.avi` | `C2PA` nested in the `movi` list | `Error: No claim found` | the same |
| `lbox-differs.avi` | LBox +1, chunk length untouched | **`Valid`** | **`Valid`** |
| `length-differs.avi` | chunk length +1 | **`Valid`** | `Invalid`: `assertion.dataHash.mismatch` (*exclusion does not match the manifest location*) |
| `pad-nonzero.avi` | pad byte `FF` | `Invalid`: `assertion.dataHash.mismatch` | the same |
| `riff-size-plus-one.avi` | first RIFF size +1 | `Invalid`: `assertion.dataHash.mismatch` | the same |
| `truncated-in-c2pa.avi` | file ends 1,000 bytes into the `C2PA` data | `Error: asset could not be parsed: RIFF chunk declared size exceeds file size` | the same |
| `trailing-bytes.avi` | 100 bytes after the only RIFF chunk | `Invalid`: `assertion.dataHash.mismatch` | the same |

SHA-256 (as printed by the script):

```
b701f5c81655311f197a6a85c9160bcef7e7129f43b66e607ed55a9ab79351f8  unsigned-avix.avi
a7520afb17a05790384009ee517f4db63e088e6d6cf1e99366094c0795df192f  two-c2pa.avi
c1197c6c91b8264e7bd3c2219e250b8eac2b5fdd7a329e76868ced514fd0c4bd  c2pa-before-idx1.avi
9f091f5500732be4edf2207843ce8b2fe0e660bdf194190eadf4eb593d14000e  c2pa-in-movi.avi
db0925e01ec9f50e9cbc984441553a878772c88c8ea495b3e4655d10fba98bea  lbox-differs.avi
51742327a0343657b0caf22a8f40536b613dc21ffb0d9bbb185e529b61cad0ee  length-differs.avi
d3a31d547ac3bad70972fa7b1de9720a1059a63034ecfa9624826df6190f1e9b  pad-nonzero.avi
01c42a88e3f4912bd6d28a6ee7313fd7afa680ca97fc8df8c7fcf2e81a572155  riff-size-plus-one.avi
73af0e79e02a27c937255b431e363f24ebd6d5ae6d099d067d7686bc24eb7bdb  truncated-in-c2pa.avi
ecd85707b9a5e3e017b8d90d392e6eafa8708bb3a5da8d6422a7f4d3aac30f77  trailing-bytes.avi
7f8834ce2ad1266b5b31ac6e5d051e4a6abdf86fd2eb4e5599e116be09317f81  avix-byte-flipped.avi
a6350086e7ff7a68b6de9975da0aed2753b57e1bad355e79fa89570e841ef3b6  avix-truncated.avi
465c03b4807baf0c1737ba73fa37a0b786d0015507da53918a8bd02b658177a6  avix-size-plus-one.avi
1d8c6922ef2949384fdcc6b55c220835a6924d5b3e55acd2a91d48961f21eb26  avix-trailing-bytes.avi
6c06fc2732b66c147fb01cd598a18a011501db41d5b6278ffb7cafa93fc58b99  second-form-avi.avi
68c51a585eb77e887c3ab57b35ffafd61129a96b51dff61375a48702691fe735  avix-with-c2pa.avi
0294bc608dbd0de054d107dc19c961b0404dcdb67660519935dab1bcb7c106ef  c2pa-only-in-avix.avi
```
