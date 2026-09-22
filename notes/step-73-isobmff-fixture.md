# Step 73 — ISOBMFF measured: M8 is smaller than it looked, and different where it counts

*2026-09-22.* M8 is the milestone the brief called "the least documented
part, and the place where c2pa-rs is still fixing bugs — hence last". This
step does for MP4 what steps 02, 04 and 06 did for JPEG, PNG and WebP:
build a signed fixture, measure the container, and find out what is
actually required before writing a line of a spec.

Nothing in `src/` changed.

## The fixture

`tests/Fixtures/fixture-unsigned.mp4` is the sister repository's 2 863-byte
MP4, copied unchanged. `tests/Fixtures/fixture-signed.mp4` is that file
signed with **c2patool 0.27.22** and the c2pa-rs ES256 test certificates,
16 441 bytes, one claim v2 manifest with `c2pa.actions.v2`
(`c2pa.created`, `digitalCapture`). The signing key lives in the sister
repository and was read into the signing process only; no key entered this
repository, as always.

`c2patool --detailed` on it is recorded beside the file as
`tests/Fixtures/c2patool/mp4.json`.

## Where the manifest store lives

One top-level `uuid` box, and nothing to reassemble:

```
@0      ftyp  32 bytes
@32     uuid  13578 bytes   d8fec3d6-1b0e-483c-9297-5828877ec481
@13610  moov  945 bytes
@14555  free  8 bytes
@14563  mdat  1878 bytes
```

Inside that box, measured byte by byte:

| offset | bytes | what |
|---|---|---|
| 32 | `00 00 35 0a` `uuid` | the box header |
| 40 | `d8fec3d6-1b0e-483c-9297-5828877ec481` | the C2PA UUID (16) |
| 56 | `00 00 00 00` | version and flags |
| 60 | `manifest` `00` | the purpose, a null-terminated string |
| 69 | eight zero bytes | `merkle_offset`, a uint64 |
| 77 | `00 00 34 dd` `jumb` | the JUMBF superbox begins |

Twenty-one bytes between the UUID and the JUMBF, and after that this is
the same manifest store as every other container carries.

## The finding that makes M8 smaller than it looked

Peel those twenty-one bytes off and hand the rest to the existing stack —
`JumbfParser`, `ManifestStore` — and **it reads, unchanged**:

```
1 manifest, claim v2, active urn:c2pa:7c2f5033-3a…
  c2pa.actions.v2
  c2pa.hash.bmff.v3
```

Every layer this project has built since SPEC-005 works on ISOBMFF as it
stands. M8's first slice is therefore the same shape as SPEC-001, SPEC-002
and SPEC-003: a container extractor, and nothing else. The BMFF *hash* is
the work, and it is a separate spec.

## Two corrections to the brief

**The assertion is `c2pa.hash.bmff.v3`, not v2.** The brief and
`docs/milestones.md` both name `c2pa.hash.bmff.v2`; c2patool 0.27.22 writes
v3. Anything planned against v2 is planned against the wrong label.

**Merkle trees are not on the first path.** `merkle_offset` is zero and the
assertion carries no merkle field: a plain, non-fragmented MP4 is hashed
whole, minus exclusions. Merkle is for fragmented and streaming BMFF, which
is a later question and not a precondition for reading an ordinary video.

## Where BMFF genuinely differs: the exclusions are not byte ranges

This is the part worth carrying into the spec. `c2pa.hash.data` excludes
**byte ranges** — start and length. `c2pa.hash.bmff.v3` excludes **boxes**,
by an XPath-like path, optionally matched on their content:

```
alg: sha256   name: "jumbf manifest"   hash: 87d4d42c438fe632766d… (32 B)

exclusions (5):
  0) {xpath=/uuid  data={0={offset=8  value=d8fec3d61b0e483c9297… (16 B)}}}
  1) {xpath=/ftyp}
  2) {xpath=/mfra}
  3) {xpath=/free}
  4) {xpath=/skip}
```

Exclusion 0 is the interesting one: *the `uuid` box whose bytes at offset 8
equal the C2PA UUID* — which is how the manifest excludes itself without
naming an offset that would change if the file moved. The other four
exclude whole box types by name.

So verifying a BMFF hash means walking the box tree, deciding per box
whether an exclusion matches it, and hashing what is left — a different
algorithm from the byte-range arithmetic of SPEC-012, not a variation of
it. That is the honest size of M8's second spec.

## The two verdicts today

| | verdict | codes |
|---|---|---|
| `c2patool` 0.27.22 | `Valid` | `assertion.bmffHash.match`, `assertion.hashedURI.match`, `claimSignature.validated`, `claimSignature.insideValidity` |
| this verifier | `Invalid` | `general.error`: *"unsupported file type: the file starts with 00 00 00 20 66 74 79 70 69 73 6F 6D…"* |

The refusal is the right shape: it fails closed and names the bytes it saw,
rather than reporting "no manifest" for a file that has one. That silent
skip is the failure mode the brief warned about (risk 5), and it is not
what happens.

## What follows

Two specs, in this order, and neither is written yet:

1. **ISOBMFF container → manifest store bytes.** The `uuid` box, its
   twenty-one bytes of preamble, the bounds, and the malformed cases —
   the same shape as SPEC-001/002/003, and by the same measurements the
   smallest of the four.
2. **`c2pa.hash.bmff.v3`.** The box walk, the exclusion matching including
   the data-match form, and what a fragmented file does — which needs a
   fragmented fixture this project does not have.

AVIF and HEIC are ISOBMFF too and would come along with the first spec;
`fixture.avif` sits unsigned in the sister repository, unmeasured here.
