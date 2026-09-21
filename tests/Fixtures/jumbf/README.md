# Manifest-store variants for SPEC-005

Every `.bin` here is the manifest store of `../fixture-signed.png` (46,025
bytes, SHA-256 `1a018eb8…`, extracted with the SPEC-002 extractor) with one
field changed, or one box grown by a few bytes with every enclosing LBox
adjusted — nothing else — by `bin/make-jumbf-variants.php`; `depth-17.bin` is
synthetic. Next to every `.bin` is a `.png`: the fixture with that store
re-embedded in its `caBX` chunk (CRC recomputed), which is what c2patool was
given. Regenerate with `php bin/make-jumbf-variants.php`; the script prints
each `.bin`'s SHA-256, and these are the values committed on 2026-09-21.
Measured with c2patool 0.27.22 the same day (`notes/step-10-jumbf-variants.md`).

Offsets (PNG store, from step 09): root 0, manifest 38, assertion store 117,
thumbnail 166 (`bidb` 264), `c2pa.hash.data` 32,831 (its `c2sh` 32,879),
claim 33,026 (`jumd` 33,034, `cbor` 33,073).

| file | what is wrong | c2patool 0.27.22 on the `.png` | SPEC-005 |
|---|---|---|---|
| `unknown-uuid` | the thumbnail assertion's type UUID → `ff…ff` | `Error: could not create valid JUMBF for claim` — not because the box is unknown, but because the claim references it and the reference cannot be resolved as an assertion | AC7: `UnknownBox` in the tree; the unresolved reference is SPEC-007's error |
| `lbox-zero` | the claim superbox's LBox → 0 | `Error: invalid JUMB box` | AC8 error |
| `lbox-one` | the claim superbox's LBox → 1 | `Error: invalid JUMBF header` | AC8 error |
| `lbox-seven` | the claim superbox's LBox → 7 | `Error: invalid JUMB box` | AC8 error |
| `child-overruns` | the `c2pa.hash.data` superbox's LBox 195 → 205 | `Error: invalid JUMB box` | AC9 error |
| `root-lbox-plus-one` | the root's LBox 46,025 → 46,026, bytes unchanged | extracts; **`Valid`** — the root LBox is not checked against the children | AC10 error (stricter than the oracle) |
| `first-child-not-jumd` | the claim's `jumd` TBox → `jumx` | `Error: expected JUMD` | AC11 error |
| `toggles-bit5` | the claim's toggles 3 → 35 (bit 5 set) | extracts; **`Valid`** — unknown toggle bits are ignored | AC12 error (stricter than the oracle) |
| `toggles-no-label` | the claim's toggles 3 → 1 (Label Present cleared) | `Error: unexpected end of file` | AC12 error |
| `label-no-nul` | the claim label's NUL → `x` | `Error: unexpected end of file` | AC12 error |
| `label-slash` | the claim label `c2pa.claim.v2` → `c2pa/claim.v2` | extracts; `Invalid`, `claim.multiple` — the claim box is no longer found under its label | AC12 error (stricter: an error, not a verdict) |
| `label-control` | the claim label with U+0001 in place of the first `.` | extracts; `Invalid`, `claim.multiple` | AC12 error (stricter: an error, not a verdict) |
| `salt-20` | 4 bytes added to the `c2pa.hash.data` salt (20 bytes), every enclosing LBox +4 | extracts; `Invalid`, `assertion.hashedURI.mismatch` (the salt bytes changed) and `assertion.dataHash.mismatch` (the store grew) — the salt length itself is not checked | AC12 error (stricter than the oracle) |
| `salt-32` | 16 bytes added to the same salt (32 bytes, valid per §8.4.2.3), every enclosing LBox +4 | extracts; `Invalid`, `assertion.hashedURI.mismatch` and `assertion.dataHash.mismatch` — M4's concern | AC3: parses, salt of 32 bytes |
| `private-not-c2sh` | the salt box's TBox `c2sh` → `c2sx` | `Error: unexpected end of file` | AC12 error |
| `uuid-c2cm` | the manifest's UUID `c2ma` → `c2cm` (compressed) | `Error: C2PA provenance not found in XMP` | AC13 error |
| `uuid-c2um` | the manifest's UUID `c2ma` → `c2um` (update) | `Error: claim missing hard binding` | AC13 error |
| `brob` | the claim's `cbor` content box TBox → `brob` | `Error: claim cbor box not valid` | AC13 error |
| `bidb-missing` | the thumbnail's `bidb` TBox → `bxdb` | `Error: invalid JUMB box` | AC14 error |
| `root-uuid-c2ma` | the root's UUID `c2pa` → `c2ma` | `Error: "c2pa" block not found` | AC15 error |
| `root-label` | the root's label `c2pa` → `c2pb` | extracts; **`Valid`** — the root label is not checked | AC15 error (stricter than the oracle) |
| `not-a-superbox` | the root's TBox `jumb` → `cbor` | `Error: invalid JUMBF header` | AC15 error |
| `depth-17` | a synthetic store of 17 nested superboxes (604 bytes), not derived from the fixture | `Error: C2PA provenance not found in XMP` | AC16 error at the 17th level |

SHA-256 of the `.bin` files (as printed by the script):

```
4351bb3e6629daa982e08f7fc71337c8f0fc744375f0efae61b62a6733ef4c81  unknown-uuid.bin
78de7f87c435cbdb49d7105a91c4693a0454d7f028c8b63302c1bd98d3ea7d0a  lbox-zero.bin
39ebe769c77db27d02990505498a511f96c26e83af5466d61de04c7537175e6d  lbox-one.bin
4025d7d32e681d24b85b90b6f255591b6352796e3d7009881300bded5c81fc94  lbox-seven.bin
3b42f59a5488326ebf14bdf4d179deb09ba88706e88ac795527b10814f984452  child-overruns.bin
151be43b13132cb46725988c9891bd3f4c1e4e81d5aff0dfc5d9541525e4df7b  root-lbox-plus-one.bin
f26ef01a69b1295b1719379c97f9e5553ab91cc7f6a8575be8ebd7cd11408506  first-child-not-jumd.bin
74d0e56d51a709c2ceb25ca4d0dd28675721a4bc3e9411857a2062401a1a43f1  toggles-bit5.bin
ae6ba8b62af921024430fa652e6285093a2fac1fac381695886c04e01ee91ecb  toggles-no-label.bin
3314f1abcd936ea2b271af324c8574ec6ce7e8bb35bcfb06276cc843c4076ff9  label-no-nul.bin
691fc06f4ed0dc2d1aff09eccd242ff38de0fcd0339058a6a1f535acb5bd5712  label-slash.bin
6a4ae4f16a62353553bf4941d305f32fb0c2de08de18c852c723b6bc380ceb23  label-control.bin
737557b9ef2cdf9c429d69741321201216b5484a2fbd7fc85dad933a4a7b4808  salt-20.bin
986d05bbdbf58fb93f899640a0489a7f92e0d88020fa594ddfef0eb248119257  salt-32.bin
58d87b81e6d3cef65a33d5e6eab1ae1c5f2cda0a42a28af0f80d9d5d36aa1bff  private-not-c2sh.bin
fc9766826537c2e88391bbdec88a2d9190763682d04035420093847aebaac389  uuid-c2cm.bin
d833e2ae501def62200ed2991e6def5063e8aef3d2b1e0624db57038efb13d24  uuid-c2um.bin
748bad9e88b0b2062662cdb4ae2c077df2420c4170c4f32d782500ccbda97646  brob.bin
98990ca58d447d0bf0d4cf6f94550ae69200bdba3799158d3ee937e99c782c98  bidb-missing.bin
1142b581ecdb497e136a80bfcec063ca706cc5fd22d4f20ebf47554dd5dc1fcc  root-uuid-c2ma.bin
cf504538059eeb1e73122525ddeb09ade3e74e400929a8bb8db3554d65ea5986  root-label.bin
2e6e3444198758180dbdb44978e2ac8b6fb6573cb456a74d9c1828a49ba21b7a  not-a-superbox.bin
9ab176166e962dd0b71d3e1a2bd992de158fb212ffbbd99e95effef6b78476fb  depth-17.bin
```
