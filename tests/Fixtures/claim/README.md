# Claim and manifest variants for SPEC-007

Every `.bin` here is the manifest store of `../fixture-signed.png` (46,025
bytes, extracted with the SPEC-002 extractor) with one thing changed — a
label, a claim field cut or re-typed, a box duplicated — and every enclosing
LBox adjusted, nothing else, by `bin/make-claim-variants.php`;
`json-broken.bin` is the Adobe store (`../public-testfiles/adobe-20220124-C.jpg`)
with one byte changed. Every variant still parses as a JUMBF tree (checked
with the SPEC-005 parser): only the meaning is wrong. Next to every `.bin` is
a `.png`: the PNG fixture with that store in its `caBX` chunk (CRC
recomputed), which is what c2patool was given. Regenerate with
`php bin/make-claim-variants.php`; the script prints each `.bin`'s SHA-256,
and these are the values committed on 2026-09-21. Measured with c2patool
0.27.22 the same day (`notes/step-14-claim-variants.md`).

| file | what is wrong | c2patool 0.27.22 on the `.png` | SPEC-007 |
|---|---|---|---|
| `claim-label-v3` | the claim label `c2pa.claim.v2` → `c2pa.claim.v3` | `Error: claim version is too new, not supported` | AC8 error |
| `claim-no-signature` | the `signature` pair cut from the claim map | `Error: claim could not be converted from CBOR` | AC9 error |
| `claim-no-created-assertions` | the `created_assertions` pair cut | `Error: claim could not be converted from CBOR` | AC9 error |
| `claim-no-instanceid` | the `instanceID` pair cut | `Error: claim could not be converted from CBOR` | AC9 error |
| `claim-no-claim-generator-info` | the `claim_generator_info` pair cut (v2, where it is required) | `Error: claim could not be converted from CBOR` | AC9 error |
| `uri-not-found` | the hash-data URI → `…/c2pa.hash.datb` | `Error: assertion missing: url = c2pa.hash.data` | AC10 error |
| `uri-wrong-place` | the hash-data URI → `self#jumbf=c2pa.claim.v2` | `Error: assertion missing: url = c2pa.hash.data` | AC10 error |
| `hash-as-text` | the hash-data entry's `hash` re-typed as the text `"abc"` | extracts; `Invalid`, `assertion.hashedURI.mismatch` (and the signature) — **the type is not checked** | AC11 error (stricter than the oracle) |
| `hash-missing` | the `hash` pair cut from the hash-data entry | `Error: claim could not be converted from CBOR` | AC11 error |
| `second-claim` | a copy of the claim superbox appended to the manifest | `Error: "c2pa" multiple claim boxes found in manifest` | AC12 error |
| `two-cbor-boxes` | the claim's `cbor` box duplicated inside the claim superbox | `Error: more than one claim description box was found for c2pa.claim.v2` | AC12 error |
| `assertion-store-label` | the assertion store labelled `c2pa.assertionz` | extracts; `Invalid`, `claim.multiple` | AC12 error (stricter: an error, not a verdict) |
| `no-manifest` | the manifest superbox's UUID `c2ma` → `c2as` (no manifest left) | `Error: C2PA provenance not found in XMP` | AC12 error |
| `generator-info-no-name` | `claim_generator_info.name` → `nome` | `Error: claim could not be converted from CBOR` | AC14 error |
| `json-broken` | the Adobe store with the `stds.schema-org.CreativeWork` JSON's first `{` → `[` (carried in the PNG, so the data hash also fails) | extracts; `Invalid`, **`assertion.json.invalid`** and `assertion.required.missing` — a status code, not a parse error | AC13 error here; the Verifier layer maps it to `assertion.json.invalid` later |
| `assertion-cbor-reserved` | the actions assertion's first CBOR byte `a1` → `bc` (additional information 28, reserved; RFC 8949 §3), same length (SPEC-088, step 481) | `Invalid`, **`assertion.cbor.invalid`** at the actions assertion; `c2patool` 0.28.3 refuses the file with an error | SPEC-088 AC1 |
| `assertion-cbor-break` | the same byte → `ff`, a break outside an indefinite-length item | the same | SPEC-088 AC1 |
| `assertion-cbor-trailing` | the same content's first byte → `00`, so bytes remain after the item (step 485) | `Invalid`, `assertion.cbor.invalid`; `c2patool` 0.28.3: "invalid type: integer `0`" | SPEC-088 amendment 1 |
| `assertion-cbor-utf8` | its start → `61 ff`, a text string that is not UTF-8 | `Invalid`, `assertion.cbor.invalid`; 0.28.3: "Invalid UTF-8" | SPEC-088 amendment 1 |
| `assertion-cbor-duplicate` | its start → `{"a": 0, "a": 0}` | `Invalid`, `assertion.cbor.invalid`; 0.28.3: "missing field `actions`" | SPEC-088 amendment 1 |
| `assertion-cbor-depth` | its start → 33 nested one-item arrays | `Invalid`, `general.error` (depth limit 32); 0.28.3: "invalid type: sequence" | SPEC-088 amendment 1 |
| `assertion-cbor-undefined` | its first byte → `f7`, the simple value undefined | `Invalid`, `general.error` (outside the subset); 0.28.3: "invalid type: unit value" | SPEC-088 amendment 1 |
| `assertion-cbor-bigint` | its start → `1b ff…ff`, 2^64 − 1 | `Invalid`, `general.error` (outside the subset); 0.28.3: "invalid type: integer `18446744073709551615`" | SPEC-088 amendment 1 |
| `claim-cbor-undefined` | the claim's first CBOR byte → `f7` | `Invalid`, `claim.cbor.invalid` (unchanged, SPEC-013); 0.28.3: "claim could not be converted from CBOR" | SPEC-088 amendment 1 (guard) |

`tests/Fixtures/jumbf/unknown-uuid.bin` (step 10) is AC10's third case: a URI
that resolves to an `UnknownBox`; c2patool → `Error: could not create valid
JUMBF for claim`.

SHA-256 of the `.bin` files (as printed by the script):

```
fd26402651e34d6cdcb0abc43b9914276e90facf165c6aa89583163765461050  claim-label-v3.bin
adda2513c0fa07eba7fdae71fba571f89ecbc547fb0b93ec3baaff59e29492bc  claim-no-signature.bin
a26a147534e0ae0ab6c0056747af5c440751f08bd1590d645861f7b494862d0f  claim-no-created-assertions.bin
ca15789a37e3650d94b9a9f523e855793f92169182b713b243ed71e1655cca50  claim-no-instanceid.bin
8f172650596f94e22b1d0b1afc7316d043c01e7d24245b60e100d2ac126100d0  claim-no-claim-generator-info.bin
0100fa39b5c8fe98e9e59927b50e3d98133b184d2d2eef2be603570e49c71e2f  uri-not-found.bin
6c64aee6c169a270f1d6dbe2402c69fa95dd2d7697be9b338f55c631e8eb7d8e  uri-wrong-place.bin
676c10fd23a118c4da65cc2bb109749d43fded8b081f1eda6249f6860b26fbc4  hash-as-text.bin
33a2e721667c40c8fa4736785cc6338e99e00bd61882cb19035310004c3454c2  hash-missing.bin
b2d3170809b7e762c1b65181834073d36841cdf0452497cd4449e1a51ae70e80  second-claim.bin
d33b2cd95bc967b67b8cd2c2efb7fbdaafc12843807e6daf258c7d7149b0d42a  two-cbor-boxes.bin
39835756535d0c8811cb208b66bb773bef73dca5b9ea29e1186840867f18313f  assertion-store-label.bin
8ccc7779620b2f16fea2ecf4e162ac25b4d22a3a86fed3d714772c20312edd89  no-manifest.bin
ee6d1d2af9c9a1014c8db1f2da5101f779e8d735879c35f61576fafef31a84db  generator-info-no-name.bin
f8c776ec2a496e0287823655f04005b738ebcc342b808c82e7bdad77bad9d277  json-broken.bin
fb2d7c5ecb799011394de55efc5f9c0b20cd6de95fac7f26053a5b58399e2ea6  assertion-cbor-reserved.bin
66f8d073184e99fe89eea8807bba5b92cb08d7a25f70df84f7b54f8aac19c729  assertion-cbor-break.bin
8765c765e63bf061324c2c7fb3c41d3ebf76c781bda0eda0b854b00894af8cb7  assertion-cbor-trailing.bin
f1d732e18f9d10ae8b5bef1ee97cc47730d304c65286b9f7200697b754e87e4b  assertion-cbor-utf8.bin
8ed8cbdac98d0b6057710cd3cefb3a134d736afc8bfcd378143a1d7d1a10e352  assertion-cbor-duplicate.bin
ded3c7f978a11362fb3a5dd2b86762e678b2521246ad12a6ad2b81cc8646d95f  assertion-cbor-depth.bin
1d26849828e984616e4fc2a3f8df21454ae5c8110d7c6c386feefc924f61fe59  assertion-cbor-undefined.bin
c57d7502d0eefdffaeb2dd6efd964a81e2b1eab8a970d3947b13b43aa4510875  assertion-cbor-bigint.bin
28c17ca748266695c0c406394a4802109fdc91f6247cdbf7eb736bddd6181dac  claim-cbor-undefined.bin
```
