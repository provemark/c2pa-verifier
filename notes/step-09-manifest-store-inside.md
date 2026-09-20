# Step 09 — The manifest store from the inside: measuring before M2

*2026-09-20.* M1 ends with bytes; M2 must turn them into data. Before a
single spec of M2 is written, this step measures what is actually in
those bytes — the three stores of our own fixtures and one store from a
foreign writer — with two throw-away probes (a JUMBF tree walker and a
CBOR inventory over the raw encoding, cross-checked against
`spomky-labs/cbor-php` 3.4.2, installed in a scratch directory only) and
`c2patool 0.27.22 --detailed` as the oracle. No verifier code, no spec.

## The stores

Extracted with our own M1 extractors, hashes equal to steps 02/04/06:

| | bytes | SHA-256 |
|---|---|---|
| `fixture-signed.jpg` | 94,740 | `f47af93e…` |
| `fixture-signed.png` | 46,025 | `1a018eb8…` |
| `fixture-signed.webp` | 100,635 | `5062cb0a…` |

## The JUMBF tree (measured, all three identical in shape)

```
0      jumb  L=46025
  8      jumd  L=30  toggles=3   label='c2pa'                       uuid c2pa…  (manifest store)
  38     jumb  L=45987
    46     jumd  L=71  toggles=3   label='urn:c2pa:488bf983-…'       uuid c2ma…  (manifest)
    117    jumb  L=32909
      125    jumd  L=41  toggles=3   label='c2pa.assertions'          uuid c2as…  (assertion store)
      166    jumb  L=32470
        174    jumd  L=70  toggles=19  label='c2pa.thumbnail.claim'   uuid 40cb0c32…  + c2sh(16 bytes salt)
        244    bfdb  L=20     "image/jpeg"                            (embedded-file description)
        264    bidb  L=32372  the JPEG thumbnail                      (embedded-file data)
      32636  jumb  L=195
        32644  jumd  L=65  toggles=19  label='c2pa.actions.v2'        uuid cbor…  + c2sh
        32709  cbor  L=122
      32831  jumb  L=195
        32839  jumd  L=64  toggles=19  label='c2pa.hash.data'         uuid cbor…  + c2sh
        32903  cbor  L=123
    33026  jumb  L=646
      33034  jumd  L=39  toggles=3   label='c2pa.claim.v2'            uuid c2cl…
      33073  cbor  L=599
    33672  jumb  L=12353
      33680  jumd  L=40  toggles=3   label='c2pa.signature'           uuid c2cs…
      33720  cbor  L=12305
```

(PNG shown; the JPEG and WebP trees differ only in the thumbnail's size
— 81,079 and 86,972 bytes — and a few bytes in the hash-data assertion.)

What the tree says:

- **Box frame**: 4-byte big-endian LBox, 4-byte TBox, as in M1. LBox 0
  ("to end") and 1 (64-bit XLBox) do not occur; every walk ends exactly
  where its superbox's LBox says.
- **Box types seen**: `jumb` (superbox), `jumd` (description), `cbor`,
  `bfdb` + `bidb` (an embedded file: a 12-byte description with the media
  type, then the bytes), and — in the foreign store below — `json`.
  Counts: 8 `jumb`, 8 `jumd`, 4 `cbor`, 1 `bfdb`, 1 `bidb`; depth 4.
- **Description box** = 16-byte UUID, 1 toggles byte, then optional
  fields the toggles announce: bit 1 (2) a NUL-terminated label, bit 2
  (4) a 4-byte id, bit 3 (8) a 32-byte signature, bit 4 (16) a *private*
  box. Toggles seen: 3 (requestable + label) and 19 (+ private).
- **The private box is `c2sh`**, 16 bytes of salt, on every assertion of
  our fixtures (C2PA 2.4 §9 "salting"): it makes the assertion's hash
  unguessable from its content. For M4: the hash in the claim is over the
  whole assertion superbox, salt included.
- **Six content-type UUIDs** (the first four bytes read as ASCII):
  `c2pa` manifest store, `c2ma` manifest, `c2as` assertion store, `c2cl`
  claim, `c2cs` claim signature, `cbor` CBOR assertion; plus
  `40cb0c32-bb8a-489d-a70b-2ad6f47f4369` for the embedded-file (thumbnail)
  assertion. These, with `json`'s, are the whole list M2's JUMBF spec must
  recognise.

## The CBOR (measured over the raw encoding)

Every `cbor` box decoded to the end with a walker of forty lines and, for
the values, with cbor-php. Over the three stores, 12 CBOR blobs:

| | major types | additional info | tags | indefinite | floats | negatives | depth |
|---|---|---|---|---|---|---|---|
| all three, each | 0 ×2, 2 ×8, 3 ×41, 4 ×5, 5 ×10, 6 ×1, 7 ×1 | ≤23, 24, 25 | 18 ×1 | **0** | **0** | **0** | 4 |

Major type 7 occurs once, as `null`. Tag 18 is `COSE_Sign1`. **The claim
in the brief ("the CBOR subset C2PA needs is small: major types 0–7,
definite lengths suffice") survives**: no indefinite lengths, no floats,
no negatives, no simple values but null, no 8-byte lengths. The longest
string is a text of 77 bytes; the longest byte string is the 10,932-byte
`pad`.

The four blobs, decoded (byte strings shown as lengths):

- `c2pa.actions.v2`: `{actions: [{action: "c2pa.created", digitalSourceType: "…/algorithmicMedia"}]}`
- `c2pa.hash.data`: `{exclusions: [{start: 33, length: 46037}], name: "jumbf manifest", alg: "sha256", hash: 32 bytes, pad: 8 bytes}` — the exclusion is **exactly the `caBX` chunk with its frame** (33; 12 + 46,025). For JPEG: `start 20`, the two APP11 segments. M4's binding excludes precisely what M1 extracts.
- `c2pa.claim.v2` — a map of **7** keys: `instanceID`, `claim_generator_info` (with `org.contentauth.c2pa_rs: "0.90.22"` next to our name/version), `signature` (`self#jumbf=/c2pa/urn:c2pa:…/c2pa.signature`, an absolute JUMBF URI), `created_assertions` (1: hash.data), `gathered_assertions` (2: thumbnail, actions — the auto-thumbnail in *gathered*, as the brief §7 warns), `dc:title`, `alg`.
- `c2pa.signature`: `18([protected: 1,285 bytes, {pad: 10,932 bytes}, null, 64 bytes])`.

Two facts M2 must get right:

1. **`claim_version` is not in the CBOR.** c2patool prints
   `"claim_version": 2`; it derives it from the box label `c2pa.claim.v2`.
   The label is data.
2. **The COSE payload is `null` — detached.** The signed bytes are the
   claim's CBOR, which M3 must supply itself when it builds the
   `Sig_structure`. c2pa-rs reserves 10,932 bytes of `pad` in the
   unprotected header so the signature box has a fixed size before
   signing.

The protected header, decoded: two keys — `1` (alg) = −7, ES256, and `33`
(x5chain) = two DER certificates of 651 and 622 bytes. No `sigTst`: our
fixtures carry no timestamp (c2patool without a TSA). The leaf: CN
`C2PA Signer`, issuer CN `Intermediate CA`, valid 2022-06-10 to
2030-08-26, `ecdsa-with-SHA256`, EKU E-mail Protection — as the brief §7
says. The signature is 64 bytes, R‖S, not DER.

## Against `c2patool --detailed` (measured)

Field for field on the PNG: the claim's keys are our seven plus the
derived `claim_version`; the assertion store has the same three entries;
the thumbnail is reported as `<omitted> len = 32364` — our `bidb` data
length; `hash.data`'s exclusion, hash and pad match; the signature summary
(`alg: es256, issuer: C2PA Test Signing Cert, common_name: C2PA Signer`)
matches the leaf certificate. Nothing in the bytes that c2patool does
not show, nothing shown that is not in the bytes or derived from a label.

## A foreign writer: `c2pa-org/public-testfiles`

The official test files (CC BY-SA 4.0; last commit `22beccc0`,
2025-12-05). The `2.2/image/good` directories hold only READMEs as of
today; the `legacy/1.4/image/jpeg` set has 26 Adobe/Nikon/Truepic files
with a naming legend — `C` claim, `A` parent ingredient, `I` ingredient,
`X` off the golden path, `E-sig-` / `E-dat-` / `E-uri-` / `E-clm-` the
four error classes. `adobe-20220124-C.jpg` (60 KB) was fetched into the
scratch directory and measured:

- **Our M1 JPEG extractor takes it without complaint**: 51,118 bytes,
  SHA-256 `832268c0…`. First foreign writer against SPEC-001; c2patool →
  `Valid`, `claim_version 1`, `timeStamp.validated`.
- The tree has 9 superboxes and one **`json` content box**
  (`stds.schema-org.CreativeWork`); salt on that one assertion only.
- **Claim v1** (`c2pa.claim`): `claim_generator` a string
  (`make_test_images/0.16.1 c2pa-rs/0.16.1`), one `assertions` list (no
  created/gathered), `dc:format`, a *relative* signature URI
  (`self#jumbf=c2pa.signature`), manifest label `contentauth:urn:uuid:…`,
  `c2pa.actions` (v1), `c2pa.thumbnail.claim.jpeg`.
- **COSE, the 1.x way**: protected header 4 bytes (`alg` only);
  **`x5chain` in the *unprotected* header**, three certificates;
  **`sigTst`** with one `tstTokens` entry of 5,951 bytes (an RFC 3161
  token — M6's first fixture); signature 512 bytes (RSA, PS256). CBOR
  depth 7.
- Still: no indefinite lengths, no floats, no negatives, one tag. The
  small-subset claim holds for a second writer and a 2022 vintage.

## What this settles for M2 (proposed)

- **One JUMBF spec** (box frame; superbox; description box with UUID,
  toggles, label, private `c2sh`; content boxes `cbor`, `json`, `bfdb` +
  `bidb`; the seven UUIDs; fail closed on LBox 0/1, an unknown toggle
  bit, a walk that does not end on its LBox, an unknown content type
  where a known one is required).
- **One CBOR spec** for exactly the measured subset: major types 0–7,
  additional info ≤ 27, definite lengths only; tag 18 passed through as a
  tag; `null`/`true`/`false`; **indefinite lengths, floats and unknown
  simple values are errors** (not "unsupported": fail closed). Limits on
  depth and size.
- **One claim spec** that models v1 and v2 side by side, the version from
  the label, and reads the assertion store — with a v1 fixture from
  public-testfiles.
- **Fixtures to add**, for the maintainer to decide: `adobe-20220124-C.jpg`
  (v1, JSON box, timestamp, RSA) now, and the `CA` / `CAI` / `E-*`
  files when M7 and M3–M4 need them. CC BY-SA 4.0 next to MIT: the
  licence attaches to the files, not the code; an attribution line in
  `tests/Fixtures/README.md` is the condition.

## Reasoned, not measured

- The meaning of toggle bits 2 and 3 (id, signature) — not seen in any
  store; from the C2PA text's description of JUMBF.
- That every other writer stays within this CBOR subset. Two writers and
  two vintages say so; a third (Truepic, Nikon) is in the same directory
  and cheap to measure when the CBOR spec is drafted.
- One probe printed certificate bytes raw to the terminal before being
  corrected to hex — a reminder that the "untrusted output" rule applies
  to tooling too.
