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

## Added 2026-09-21 (step 24, for SPEC-011)

Eight store-level variants from `bin/make-hashed-uri-variants.php`, the
same PNG store, measured with c2patool 0.27.22 the same day
(`notes/step-24-hashed-uri-variants.md`). Every one changes the claim
and/or the store's length, so `claimSignature.mismatch` and, where the
length changed, `assertion.dataHash.mismatch` come along; the column
shows what matters to SPEC-011.

| file | what is wrong | c2patool 0.27.22 | spec |
|---|---|---|---|
| `hashed-uris-two-changed` | one bit of the claim's `hash` for `c2pa.hash.data` and for `c2pa.thumbnail.claim` | `assertion.hashedURI.mismatch` on both, `match` on `c2pa.actions.v2` | SPEC-011 AC3 |
| `hashed-uri-truncated` | the `hash` for `c2pa.hash.data` cut from 32 to 31 bytes | `assertion.hashedURI.mismatch` on `c2pa.hash.data` — a report, not an error | SPEC-011 AC4 |
| `assertion-duplicate-label` | a second `c2pa.actions.v2` box, byte for byte the first, appended to the assertion store | `Error: assertion missing: url = c2pa.actions.v2` — no report | SPEC-011 AC5 (`assertion.undeclared`) |
| `assertion-undeclared-unknown-uuid` | the same copy with UUID `deadbeef-0011-0010-8000-00aa00389b71` and label `c2pa.extraz.v2x` | `Error: assertion missing: url = c2pa.extraz.v2x` — no report | SPEC-011 AC6 (`assertion.undeclared`) |
| `uri-alg-sha384` | the `c2pa.hash.data` entry given `alg: sha384` and a 48-byte SHA-384 hash; the claim's `alg` still `sha256` | three `assertion.hashedURI.match` | SPEC-011 AC7 |
| `claim-alg-sha1` | the claim's `alg` → `sha1` | three `assertion.hashedURI.mismatch` — §15.4.2/§13.1 say `algorithm.unsupported` | SPEC-011 AC7 |
| `claim-alg-missing` | the claim's `alg` pair removed | `Error: unknown algorithm` — no report | SPEC-011 AC7 (`algorithm.unsupported`) |
| `claim-redacted` | `redacted_assertions: ["self#jumbf=c2pa.assertions/c2pa.actions.v2"]` added to the claim | `assertion.action.redacted` (actions may not be redacted, §6.7); redacting the thumbnail instead gives no status at all although the box is still there | SPEC-011 AC8 (`general.error` until M7) |

SHA-256 of the stores (as printed by the script):

```
1d0f3e64f71701712f92132173dbf368cc2b8242f92bbe63dc92295149460b92  hashed-uris-two-changed.bin
ae3b97e5c6524547cb4a7073c2d9a7d7195b7bfb83c6b442b98cfffa44554f04  hashed-uri-truncated.bin
48a94e997e4247c559bd5716b7adddd9e56567047f36fa1cb4e567abc3fdd4a6  assertion-duplicate-label.bin
a845421bc2cdc368d40b10c65b3e5d48731deacf26a3408daed7f197d2b05b7e  assertion-undeclared-unknown-uuid.bin
235964f17c47a23d829bd9d7685d38cd24ac8904137a379fbd23c38fd3c177d7  uri-alg-sha384.bin
aa1117a22c2b8cded2aa20b86ec289b899d72257643616a8c838cfbd5586228d  claim-alg-sha1.bin
5427a3829343fb9927f7c6c7a2c612e6ed1951ba6532abad8de183666dc12c5d  claim-alg-missing.bin
778d9d6a0d399229e83aa568f01533409ce776441a53b45a17c26658d22206bd  claim-redacted.bin
```

## Added 2026-09-21 (step 26, for SPEC-012)

Twelve store-level variants from `bin/make-data-hash-variants.php`, the
same PNG store, measured with c2patool 0.27.22 the same day
(`notes/step-26-data-hash-variants.md`). Where the edit is meant to be
*valid* (`exclusion-extra`, `exclusions-unsorted`, `alg-missing`,
`alg-sha384`, `hard-bindings-two`, and the two relabelled ones) the data
hash was recomputed over the carrier PNG minus the ranges, and the claim's
hashed URI for the assertion recomputed, so that only the signature is
broken — c2patool's `assertion.dataHash.match` on those is the check on
the script's arithmetic. The shape-fault variants leave the hashed URI
as it was.

| file | what is wrong | c2patool 0.27.22 | spec |
|---|---|---|---|
| `exclusion-extra` | a second exclusion `{46500, 64}` over IDAT data; the store's exclusion grown by the 19 bytes the entry adds (46,056); hash and hashed URI recomputed | `assertion.dataHash.match` + informational `assertion.dataHash.additionalExclusionsPresent`; `claimSignature.mismatch` | SPEC-012 AC4 |
| `exclusions-unsorted` | the same two ranges, written in reverse order | the same | SPEC-012 AC5 (sorted first) |
| `exclusions-not-list` | `exclusions` the one map itself, not a list of one | `Error: could not decode assertion c2pa.hash.data … invalid type: map, expected a sequence` — no report | SPEC-012 AC6 (`.malformed`) |
| `exclusion-start-negative` | `start` −1 (CBOR major type 1) | `Error: … invalid value: integer -1, expected u64` — no report | SPEC-012 AC6 (`.malformed`) |
| `exclusion-length-text` | `length` the text `"46037"` | `Error: … invalid type: string "46037", expected u64` — no report | SPEC-012 AC6 (`.malformed`) |
| `hash-as-text` | `hash` a 32-character text string instead of 32 bytes | decodes: `assertion.hashedURI.mismatch` (not recomputed) + `assertion.dataHash.mismatch` | SPEC-012 AC6 (`.malformed` — stricter) |
| `exclusions-too-many` | 1,025 zero-length ranges (`99 04 01`) | decodes: `assertion.hashedURI.mismatch` + `assertion.dataHash.mismatch` + the informational — no bound | SPEC-012 AC6 (`.malformed`, `maxExclusions` 1024) |
| `alg-missing` | the assertion's `alg` pair removed (the claim's `sha256` applies); the store's exclusion shrunk by 11 (46,026) | `assertion.dataHash.match`; `claimSignature.mismatch` | SPEC-012 AC7 |
| `alg-sha384` | `alg` `sha384`, a 48-byte hash; the store's exclusion grown by 16 (46,053) | `assertion.dataHash.match`; `claimSignature.mismatch` | SPEC-012 AC7 |
| `hard-binding-missing` | the `c2pa.hash.data` box and the claim's url for it relabelled `c2pa.othr.data` | `Error: claim missing hard binding` — no report | SPEC-012 AC8 (`claim.hardBindings.missing`) |
| `hard-binding-bmff` | relabelled `c2pa.hash.bmff.v2` (the exclusion left at 46,037; the box is 3 bytes longer) | `Error: could not decode assertion c2pa.hash.bmff.v2 … missing field xpath` — no report | SPEC-012 AC8 (`general.error`, M8) |
| `hard-bindings-two` | a second, identical `c2pa.hash.data` box at the end of the store and a second, identical claim entry; the store's exclusion grown to 46,319, hash and hashed URIs recomputed in both | `assertion.multipleHardBindings` with url `self#jumbf=/c2pa/urn:c2pa:488bf983-…` (the manifest, not the claim box — SPEC-012 amendment 1); two `dataHash.match`; `claimSignature.mismatch` | SPEC-012 AC8 |

SHA-256 of the stores (as printed by the script):

```
398dbc7d578a939bf96004e04d9767302264c9ad9015b7c908909c73f0bc84e9  exclusion-extra.bin
c18add206430e6f79c984f133eee13743f1b43ee700a4bbfb6122ec07219bca8  exclusions-unsorted.bin
3f47ee86479bb72ab9f7b40ff790179b1e107d490781b303bdd1ba05863f1909  exclusions-not-list.bin
6c978af9171e9d384ef819d0072cbd53718f362793a76184c59efabc6a9e89b5  exclusion-start-negative.bin
96732f33552f4405767a9dc172a410833d7ec517c5af2d73ba39b0eac17b4c75  exclusion-length-text.bin
d38421c3ea4d9f3edfdcc0cf47c0f3886846a5c2400a3923a5372696277855c0  hash-as-text.bin
55ff5244cdfadf003cf00cc7b2a5dccc23912420e09448f240deb5628a4ae8a0  exclusions-too-many.bin
6ed29b335374e3364201c4fc65df0bf74cdcb20ba045eab0ffb44e8417361382  alg-missing.bin
f680656d80323b6423e3df7c0bff0722278a23d36c133d92fe2fe66a61047327  alg-sha384.bin
274b1d77e4220869240846b23a8ac9908731bf71e80949831e9d5a799acb3343  hard-binding-missing.bin
328dbb4c3cc6b5b7c0120816487030901636a92741422f7cc8dad2b2f9d5c8cb  hard-binding-bmff.bin
2fa508250976bd2f9f033d9e9ff995ad4c769773b2061aa28702bb0f72c20f0d  hard-bindings-two.bin
```

## Added 2026-09-21 (step 31, for SPEC-014)

One store-level variant from `bin/make-trust-variants.php`, measured with
c2patool 0.27.22 (`notes/step-31-trust-variants.md`):

| file | what is wrong | c2patool 0.27.22 | spec |
|---|---|---|---|
| `x5chain-leaf-only` | the intermediate removed from the protected header's `x5chain` (625 bytes) and the unprotected `pad` grown by exactly 625, so the store keeps its length, every box its LBox, the exclusion and the hashed URIs their values — only the signature breaks (the Sig_structure covers the protected header) and the chain no longer reaches an anchor | with the full settings and without: `Invalid`; `signingCredential.untrusted` + `claimSignature.mismatch`, nothing else | SPEC-014 AC4 |

```
fd4f6c7ef15644d1bb48e404e6ff0a040259f7b289f742158b53ced602834ef4  x5chain-leaf-only.bin
```

## Step 47 (SPEC-013 amendment 10): a signed manifest with no hard binding

`bin/make-no-hard-binding-variant.php <scratch>` — the `c2pa.hash.data`
box removed from the assertion store, the claim's `created_assertions`
holding the actions assertion instead and `gathered_assertions` the
thumbnail, and the claim re-signed with a throw-away P-256 hierarchy
(keys outside the repository, deleted at the end of the run; the public
root in `no-hard-binding-root.pem` and, as the only anchor, in
`no-hard-binding-root.settings.json`). Every run makes a new hierarchy,
so the store's hash differs per run; the shape does not.

| variant | what | c2patool 0.27.22 | this verifier before step 47 |
|---|---|---|---|
| `no-hard-binding` | signature valid, both hashed URIs match, no `c2pa.hash.data` anywhere | `Error: claim missing hard binding` — exit 1, no report, with and without the root as anchor | **`Valid`** without settings, **`Trusted`** with the root as anchor — the data-hash check never ran (`checks_performed` ended at `hashedUris`) |

The case the brief's §8 puts first: a wrong `Valid`. Found by reasoning
in step 46, shown here, closed in step 47 (SPEC-013 amendment 10:
the data-hash check runs unless `c2pa.hash.data` is declared and its
hashed URI failed; absent → `claim.hardBindings.missing`).

