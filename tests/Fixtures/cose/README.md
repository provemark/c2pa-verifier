# Signature-level variants for M3 (SPEC-008, SPEC-009)

Every `.bin` here is the manifest store of `../fixture-signed.png` (46,025
bytes, extracted with the SPEC-002 extractor) with one thing changed in its
signature box by `bin/make-cose-variants.php`: a byte, a tag, or the whole
protected header (every enclosing LBox adjusted). Every variant still parses
as a JUMBF tree. Next to every `.bin` is a `.png`: the fixture with that
store in its `caBX` chunk (CRC recomputed), which is what c2patool was
given. Regenerate with `php bin/make-cose-variants.php`; the script prints
each `.bin`'s SHA-256, and these are the values committed on 2026-09-21.
Measured with c2patool 0.27.22 the same day (`notes/step-16-cose-signature.md`,
`notes/step-17-cose-variants.md`).

Offsets (PNG store, step 09): the signature's cbor data at 33,728 (12,297
bytes): `d2 84 | 59 0505 <1,285-byte protected header> | a1 63 pad 59 2ab4 <10,932> | f6 | 58 40 <64>`.

| file | what is wrong | c2patool 0.27.22 on the `.png` | spec |
|---|---|---|---|
| `tag-19` | the tag `d2` (18) → `d3` (19) | `Error: could not generate a trusted time stamp` (c2pa-rs's COSE parse failure surfaces through its timestamp path) | SPEC-008 AC7 error |
| `no-tag` | the tag byte removed: a bare four-item array | `Error: could not generate a trusted time stamp` | SPEC-008 AC7 error |
| `three-items` | the signature item removed: a three-item array | `Error: could not generate a trusted time stamp` | SPEC-008 AC7 error |
| `payload-present` | the payload `f6` (nil) → `40` (an empty byte string) | extracts; **`Valid`**, `claimSignature.validated` — the payload field is ignored; §13.2.3 forbids the empty byte string as "detached" | SPEC-008 AC8 error (stricter than the oracle) |
| `protected-not-map` | the protected map head `a2` → `84`: an array of four instead of a map | `Error: could not generate a trusted time stamp` | SPEC-008 AC9 error |
| `alg-missing` | the protected key `01` → `02`: no `alg` | `Error: could not generate a trusted time stamp` | SPEC-008 AC9 error |
| `alg-string-label` | the protected header replaced by `{"alg": -7}` (7 bytes) | `Error: could not find signing certificate chain in COSE signature` | SPEC-008 AC9 error |
| `x5chain-missing` | the label `18 21` (33) → `18 22` (34) | `Error: could not find signing certificate chain in COSE signature` | SPEC-008 AC10 error |
| `leaf-der-broken` | the leaf certificate's first DER byte `30` → `31` | `Error: COSE error parsing certificate` | SPEC-008 AC10 error |
| `chain-empty` | the protected header replaced by `{1: -7, 33: []}` | `Error: could not find signing certificate chain in COSE signature` | SPEC-008 AC10 error |
| `double-label` | the protected header with `x5chain` under both 33 (leaf first) and `"x5chain"` (reversed) | extracts; `Invalid`, `claimSignature.mismatch` (the header changed, so the signature cannot match) and `assertion.dataHash.mismatch` (the store grew) — which chain c2patool picked is not observable this way | SPEC-008 AC11: 33 wins |
| `claim-title-changed` | the first byte of the claim's `dc:title` (`fixture-signed.png` → `gixture-signed.png`) | `Invalid`, `claimSignature.mismatch` | SPEC-009 (mismatch) |
| `signature-changed` | one bit of the 64-byte ES256 signature | `Invalid`, `claimSignature.mismatch` | SPEC-009 (mismatch) |
| `alg-eddsa-with-ec-key` | the protected header's `alg` −7 (ES256) → −8 (EdDSA), the key still P-256 | `Invalid`, `claimSignature.mismatch` — no separate code for a key that does not fit the algorithm | SPEC-009 (key does not fit the algorithm) |

SHA-256 of the `.bin` files (as printed by the script):

```
567c51262d991e4033f605771e845b9f9ed3e709d66ab2a9e17bdb8c76013139  tag-19.bin
09648ab2b101a3687841ac2eb39d21b7695aa31302e4b6ee2facfa83c8ccb091  no-tag.bin
26e90a5227bcaa5bc7704de45cc3fe5a59208f9e4743e6051114afef4ce5a9f0  three-items.bin
868a4c220646380d561295fbb201bd8a86aa972db87e92751ba927e5f2825e23  payload-present.bin
65a87a14e2ed14db138dc52b2dbde89ef9be610ad28a74fb5c4717ca6410715b  protected-not-map.bin
149383f4ab34d1e599d8b64bad974051b03aa62fb2f18dbec68e3fcf792bee0b  alg-missing.bin
9ed2cda863136beba54a0c41e79ad14cfc1a50c1ac6b377bfc48e1fb1b5cf6f1  alg-string-label.bin
4eadb95533476fc1bfc6da89856ef57f41a914cee05427352a778604fcec6bd6  x5chain-missing.bin
e3b6e784a31330f3d20b678e48be437f80c4f9967b375ac18fde44f7e772cd53  leaf-der-broken.bin
36defa8380513e418ff2ca0cd27955a37139fcd2af2b7a6ae630e068101e7337  chain-empty.bin
a5a2b5e95d4be8dc8503eb5598ae10aa3f5c8655b6f2af44e20265f4f9f49217  double-label.bin
9a42f90f72a775e3178c13001a7633f4822646e5bdec088324629274dc8dede7  claim-title-changed.bin
000fa25e38647fa27743d7eb70ff42d35119a087488e6afaec041e534b13cfe0  signature-changed.bin
23ece603dde721e6d4e32b07aa8020bb1b11d0d64a12248054248e07ca484cc9  alg-eddsa-with-ec-key.bin
```
