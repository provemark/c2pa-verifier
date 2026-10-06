# GIF variants (step 256)

Made by `bin/make-gif-variants.php` from `../fixture-signed.gif` (signed
with `c2patool` 0.27.22 and `../fixture-signed-gif.manifest.json`) and
`../fixture-unsigned.gif` (the sister library's `tests/Fixtures/fixture.gif`,
64×64, GIF89a, with a NETSCAPE2.0 and a graphic control extension). The
signed file is the unsigned one with one `C2PA_GIF` Application Extension
inserted at offset 781, right after the global colour table: 130,681 bytes,
511 sub-blocks of 255 bytes and one of 105, the store 130,155 bytes. Its
data hash excludes exactly that block: `[781, 130681]`.

Each variant moves whole blocks or changes one field. Answers of
`c2patool` 0.27.22 and 0.28.1, recorded 2026-10-06 (state and sorted
failure codes, or the error):

| file | what changed | 0.27.22 | 0.28.1 |
|---|---|---|---|
| `two-c2pa.gif` | a second, identical `C2PA_GIF` block right after the first | `Invalid`, `assertion.dataHash.mismatch`, `signingCredential.untrusted` | the same |
| `c2pa-after-netscape.gif` | the block moved after NETSCAPE2.0 and the graphic control extension, still before the image (§A.3.8 allows the place; the exclusion still says 781) | `Invalid`, `assertion.dataHash.mismatch`, `signingCredential.untrusted` | `Invalid`, `assertion.dataHash.mismatch`, `assertion.dataHash.mismatch`, `signingCredential.untrusted` |
| `c2pa-after-image.gif` | the block moved after the image, before the trailer (§A.3.8: before the first image descriptor) | Error: No claim found | the same |
| `auth-2-0.gif` | authentication code `02 00 00` (a version 2.0 block) | Error: No claim found | the same |
| `auth-1-1.gif` | authentication code `01 01 00` (version 1.1) | Error: No claim found | the same |
| `ident-other.gif` | identifier `C2PA_GIX` | Error: No claim found | the same |
| `block-size-12.gif` | the extension's block size `0x0C` instead of `0x0B` | Error: asset could not be parsed: Invalid block size for app block extension 12! | the same |
| `rechunked-100.gif` | the same store re-split into sub-blocks of 100 bytes (a block of another length) | `Invalid`, `assertion.dataHash.mismatch`, `signingCredential.untrusted` | `Invalid`, `assertion.dataHash.mismatch`, `assertion.dataHash.mismatch`, `signingCredential.untrusted` |
| `early-terminator.gif` | the 10th sub-block's size set to 0: the block ends early, the rest reads as stray bytes | Error: invalid embedded file box | the same |
| `c2pa-empty.gif` | a `C2PA_GIF` block without sub-blocks (from the unsigned file) | Error: No claim found | the same |
| `c2pa-not-jumbf.gif` | a `C2PA_GIF` block whose 600-byte payload is not JUMBF (from the unsigned file) | Error: invalid JUMBF header | the same |
| `truncated-in-c2pa.gif` | cut 5,000 bytes into the block | Error: failed to fill whole buffer | the same |
| `truncated-after-c2pa.gif` | cut right after the block (no image, no trailer) | `Invalid`, `assertion.dataHash.mismatch`, `signingCredential.untrusted` | the same |
| `no-trailer.gif` | the trailer byte `0x3B` removed | `Invalid`, `assertion.dataHash.mismatch`, `signingCredential.untrusted` | the same |
| `trailing-bytes.gif` | 16 zero bytes after the trailer | `Invalid`, `assertion.dataHash.mismatch`, `signingCredential.untrusted` | the same |
| `gif87a.gif` | the header made `GIF87a`, a version without extensions | `Invalid`, `assertion.dataHash.mismatch`, `signingCredential.untrusted` | the same |
| `flip-image.gif` | one byte of image data flipped | `Invalid`, `assertion.dataHash.mismatch`, `signingCredential.untrusted` | the same |
| `flip-store.gif` | one byte deep in the store flipped (the thumbnail's data) | `Invalid`, `assertion.hashedURI.mismatch`, `signingCredential.untrusted` | the same |

SHA-256:

```
a097dc614a4de0cbfa6afc3f977c38dc87f0e82ce64ef9d68b4a7f3d9fe11dde  two-c2pa.gif
82c81221167f423952f898c4c195c64501efccf0c64d3ba80df92835570e423f  c2pa-after-netscape.gif
bd23bcc55e1b49d8a3d8591cf42f19595da80598df54671fd8935d31b43dbaa1  c2pa-after-image.gif
842970b8205ad50a6e17389f19aae464a0c1b11238df6528e1feeb46479de58c  auth-2-0.gif
288a84ee250c0cf09c39b85e8212e7e3984b441751deaa241c30c51c934bd13f  auth-1-1.gif
e57a515fd64f74856f126ced1428ea4b60a43a960a81cdf78e3c14ec91e039ec  ident-other.gif
4f3bd9c9b5866dc640feeb3036ade9726919c61a7ca78f333c0b2dc0185f1285  block-size-12.gif
7360843137f100b68e9c58150f4c37f940cf2fe45a5022bebc523aa1c5d00c96  rechunked-100.gif
610ae0fc40d3db3974305e84095e547818d77635e8d8d9473116f01cd4678189  early-terminator.gif
8847501ebf573cd1f1d9c27673d8999395433aa1b79fffa9434858d030e1c978  c2pa-empty.gif
a341033b2ef9a25fa12b6ae67b4c839b804acb4f627e070c13f610d2f04d9254  c2pa-not-jumbf.gif
af9437434af1afa4702ca5b46d6a9776366ff6a05180efe4fbaecb7d7ba457b0  truncated-in-c2pa.gif
077bfbb247e9d816834d34beeb7ec415d6251558fd557b7f9abcd838c329396b  truncated-after-c2pa.gif
84565526933e72069ffe36ab18af64d4586cd2685ac433bc961fb7a89aef7e78  no-trailer.gif
ec33d437465b797e9801d176a28a7cf4ca145f86500e81063692d630c466cff4  trailing-bytes.gif
ee15000894c228d9d0c1ddc5a84f59bbda6dc946e98d4146316bcffb01489156  gif87a.gif
57156ff33dd820b555fda88eb29fadb89b698a30e548b4393125bd213b58ca2a  flip-image.gif
d3e425ff28acd0a465d6aad025e94f84cd147301eeeec9599667127c122bfb9c  flip-store.gif
```
