# Hash-binding variants for M4 (SPEC-011, SPEC-012)

Two kinds of variant, both made by `bin/make-binding-variants.php` from
`../fixture-signed.png` (and `.jpg`); the script prints each file's SHA-256,
and these are the values committed on 2026-09-21. Measured with c2patool
0.27.22 the same day (`notes/step-23-binding-measured.md`).

**File-level** (`.png`/`.jpg` only): the asset changed *outside* the
manifest store, the store itself byte for byte the original. The claim and
its signature still verify, so c2patool's verdict is about the binding
alone.

**Store-level** (`.bin` + `.png`): the `c2pa.hash.data` assertion or a
hashed URI in the store of the PNG fixture changed (every enclosing LBox
adjusted); the `.png` is the fixture with that store in its `caBX` chunk
(CRC recomputed), which is what c2patool was given. Changing an assertion
also breaks its hashed URI in the claim; changing the claim also breaks the
signature — the table says which codes come along.

Offsets (PNG store, step 09): the `c2pa.hash.data` cbor data at 32,911
(115 bytes): `a5 | 6a exclusions 81 a2 65 start 18 21 66 length 19 b3d5 |
64 name 6e "jumbf manifest" | 63 alg 66 sha256 | 64 hash 58 20 <32> | 63 pad
48 <8 zero bytes>`.

| file | what is wrong | c2patool 0.27.22 | spec |
|---|---|---|---|
| `pixel-changed.png` | one bit of the 101st IDAT data byte flipped, IDAT CRC recomputed — a clean pixel edit | `Invalid`; `assertion.dataHash.mismatch` ("Hashes do not match"), everything else `match`/`validated` | SPEC-012: the M4 "done when" |
| `pixel-changed.jpg` | one bit of a scan byte 100 bytes before the end | `Invalid`; `assertion.dataHash.mismatch` | SPEC-012 |
| `bytes-appended.png` / `.jpg` | 16 bytes of text after the last byte of the file | `Invalid`; `assertion.dataHash.mismatch` — bytes after the last exclusion are hashed too | SPEC-012 |
| `bytes-inserted-before-store.png` | a 16-byte `tEXt` chunk between IHDR and `caBX`: the store, and every exclusion offset, shifts by 16 | `Invalid`; `assertion.dataHash.mismatch` — the exclusion range is taken from the assertion literally, not re-found from the container | SPEC-012 |
| `exclusions-overlap` | two ranges, the second (`start 256, length 256`) inside the first | `Invalid`; `assertion.hashedURI.mismatch` + `assertion.dataHash.mismatch`, and the informational `assertion.dataHash.additionalExclusionsPresent` — §15.12.1 says `assertion.dataHash.malformed` for an overlap | SPEC-012 (malformed) |
| `exclusion-past-end` | `length` 46,037 → 65,535: the range ends 19,498 bytes past the file | `Invalid`; `assertion.hashedURI.mismatch` + `assertion.dataHash.mismatch` | SPEC-012 (§15.12.1: mismatch) |
| `exclusion-shifted` | `start` 33 → 32: the range begins on the IHDR CRC's last byte and ends one byte short of the chunk | `Invalid`; `assertion.hashedURI.mismatch` + `assertion.dataHash.mismatch`. With 33 → **34** c2patool said `assertion.dataHash.match`: the byte that enters the hash (33, `00`, the caBX length's high byte) and the byte that leaves it (46,070, `00`, the next chunk's length's high byte) are equal, so the hashed byte sequence is identical — a lesson about test data, not about the verifier | SPEC-012 |
| `hash-missing` | the `hash` entry removed (map of 4) | `Error: could not decode assertion c2pa.hash.data … missing field 'hash'` — no report at all | SPEC-012 (§15.12.1: `assertion.dataHash.mismatch`) |
| `alg-sha1` | `alg` `"sha256"` → `"sha1"` | `Invalid`; `assertion.hashedURI.mismatch` + `assertion.dataHash.mismatch` ("type is unsupported") — §15.12.1 says `algorithm.unsupported` | SPEC-012 |
| `pad-nonzero` | the 8 `pad` bytes `00` → `ff` | `Invalid`; `assertion.hashedURI.mismatch`, but `assertion.dataHash.match` — `pad` is not in the hash, and c2patool still evaluates the data hash after the assertion's own hashed URI failed | SPEC-012 (pad is filler; the hashed-URI failure is the verdict) |
| `hashed-uri-changed` | one bit of the `hash` in the claim's hashed URI for `c2pa.hash.data` | `Invalid`; `claimSignature.mismatch` (the claim changed) + `assertion.hashedURI.mismatch` for `c2pa.hash.data`; `assertion.dataHash.match` | SPEC-011 (mismatch) |
| `assertion-undeclared` | a copy of the `c2pa.actions.v2` superbox relabelled `c2pa.extraz.v2x` (same length) inserted into the assertion store, the claim untouched | `Error: assertion missing: url = c2pa.extraz.v2x` — no report; §15.10.3 says `assertion.undeclared` | SPEC-011 (undeclared) |

SHA-256 of the files (as printed by the script):

```
02866f566d357f52733f85463208c9ffd1f78b8e096baa8f4a216bee8e54cab9  pixel-changed.png
be8b7c6e40893d1a014215c573a5729a20dac2c288a955dfb5cf55bacc7ebd22  pixel-changed.jpg
f614119946aba63f63fd0e6b6ee813be81fb426eca2a67437ba125abcf334692  bytes-appended.png
3f33447449e10cf71dbe2afa5c81bbf25cff739edb5586c54654db503d0849c3  bytes-appended.jpg
6ed1f53364122d5c555f65b7156180204e8db22ecab957362f77f581b4ab7dfe  bytes-inserted-before-store.png
44b1bdfaae988a3833e0153365f657932fc3f766f7026cb09223fa20738c5b39  exclusions-overlap.bin
edaf69f8a3b57d19a1f1c36244c01a6563ad749633ad7673af0e282e7154a4aa  exclusion-past-end.bin
489a3e581c5e5e95519ac16ecd3547b2ace9f1d608e854f2d11006cbba5918de  exclusion-shifted.bin
f5ceebbe94ef0af18d44c63f9f10b189a823b51645ad9ba96ebd7eaaa65427d5  hash-missing.bin
ee644d5b267c0e05c208c01ec9023fc63aa3f649db775c55f999e495224ab320  alg-sha1.bin
f050e287388221f5a8fc7e1755f78e33093b61da5c37bfa5f52578a4b6f73f41  pad-nonzero.bin
b1f646605f0e81544661aac1b0ffaf20b562bb5663eda8af986d8310ad12e998  hashed-uri-changed.bin
963718e5de040adcb6dabd8bebadc8df3417198a0b0330622385f7c757fcd993  assertion-undeclared.bin
```
