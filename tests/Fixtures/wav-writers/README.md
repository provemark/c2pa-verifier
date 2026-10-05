# WAV files from other writers (step 210)

Every WAV with C2PA content found in the public C2PA repositories on
2026-10-05, copied unchanged. `c2pa-org/public-testfiles` has no WAV (its
audio is m4a); `c2pa-org/conformance-public`, the Encypher conformance
suite, `richardwooding/c2pa`, `TrustNXT/c2pa-ts`, `contentauth/c2pa-js` and
`contentauth/c2pa-node` have none either.

| file here | source | what it is | `c2patool` 0.27.22 and 0.28.1 | this verifier |
|---|---|---|---|---|
| `c2pa-python-sample1_signed.wav` | `contentauth/c2pa-python` `tests/fixtures/files-for-reading-tests/sample1_signed.wav` at `192023c` | signed by `c2pa-c test 0.2` (the C binding of c2pa-rs), ES256, the c2pa-rs test certificate; chunks `fmt `, `data`, `LIST`, `id3 `, `C2PA` (last) | `Valid`; `Trusted` with `../matrix/test-roots.settings.json` | the same, code for code |
| `c2pa-rs-sample1.wav` | `contentauth/c2pa-rs` `sdk/tests/fixtures/sample1.wav` at `e4f63a2` | the unsigned source of the file above | `Error: No claim found` | no manifest |
| `c2pa-rs-sample3.invalid.wav` | the same repository, `sample3.invalid.wav` | unsigned; the RIFF size claims 1,000,000 bytes more than the file holds | `Error: asset could not be parsed: Invalid RIFF format` | `Invalid`, `general.error` naming both sizes |
| `c2pa-rs-riff_bomb_1000.wav` | the same repository, `riff_bomb_1000.wav` | hostile: one `LIST` chunk nesting 1,000 `LIST` chunks | `Error: No claim found` | no manifest; 0.05 s, 33 MB peak (the walk does not descend into `LIST`) |

The c2pa-rs and c2pa-python repositories are licensed **Apache-2.0 OR
MIT**; both texts of each are alongside. That licence attaches to these
files, not to this package's code (MIT). Attribution: © Adobe and the
c2pa-rs and c2pa-python contributors.

`c2patool`'s JSON for the signed file, with and without the test roots,
from both versions, is under `../c2patool/wav-writers/`.
