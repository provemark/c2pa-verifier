# SPEC-027: `c2pa.hash.bmff.v3` — the hard binding for ISOBMFF

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-22                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-026 reads the manifest store out of an MP4, MOV or AVIF, and then the
verifier refuses the file: `Invalid`, with `the hard binding
c2pa.hash.bmff.v3 is not supported yet` named in the report. That is the
honest answer, and it is a poor one. **A video whose pixels were replaced
after signing is `Invalid` here because nobody checked, not because anybody
caught it** — and a caller cannot tell those two apart from the verdict.
This spec is what turns the first into the second.

Step 76 could not reconstruct the algorithm from the assertion and stopped
rather than guess. Step 77 measured it, by patching two `eprintln!` lines
into c2pa-rs's hashing loop and running it against this repository's own
fixtures. The rule is:

> For each **top-level box that no exclusion matches**, in file order:
> hash the box's own offset as a **big-endian `u64`**, then hash the box's
> bytes.

Recomputed by hand it reproduces both stored hashes exactly:

| file | what is hashed | stored digest |
|---|---|---|
| `fixture-signed.mp4` | `u64(13610)` ‖ `moov` ‖ `u64(14563)` ‖ `mdat` | `87d4d42c438fe632766d…` |
| `fixture-signed.avif` | `u64(13611)` ‖ `meta` ‖ `u64(13846)` ‖ `mdat` | `81955e02ee8dee9c5305…` |

The offsets are the point. Without them this would be "the file minus some
boxes", and a box whose bytes are untouched could be moved freely; with
them, every included box is bound to **where it sits** as well as what it
holds. That is also what makes excluding the C2PA box safe: the box itself
is not hashed, but everything around it is pinned.

The exclusions are not byte ranges as in SPEC-012. They are box paths with
optional filters — measured on both fixtures as:

```
{xpath=/uuid  data={0={offset=8  value=<the C2PA UUID>}}}
{xpath=/ftyp}  {xpath=/mfra}  {xpath=/free}  {xpath=/skip}
```

The `data` form is how the manifest excludes **itself** without naming an
offset that would move: *the `uuid` box whose bytes at offset 8 are the
C2PA UUID*. `c2pa-rs` also honours `length`, `version`, `flags` with an
`exact` flag for bitwise matching, and `subset` to narrow the excluded
range — none of which any fixture here exercises.

## Scope

**In scope**

- Resolving a `c2pa.hash.bmff.v3` assertion's exclusions against the
  top-level boxes of an ISOBMFF file.
- The `data` filter, which both fixtures use and without which the C2PA
  box cannot exclude itself.
- The digest: offset marker plus bytes, per included top-level box, over
  the streaming reader SPEC-012 already has.
- The statuses: `assertion.bmffHash.match` and `.mismatch`, and what
  happens when the assertion is malformed.
- Removing the refusal SPEC-026 leaves behind, and with it the row in
  `docs/comparison.md` that says ISOBMFF has no hard binding.

**Out of scope** (each needs its own spec before it may be built)

- **Fragmented BMFF and Merkle trees.** `merkle_offset` is zero in every
  fixture here and the assertion carries no `merkle` field. A fragmented
  file must keep being refused by name, not guessed at.
- The `length`, `version`, `flags`/`exact` and `subset` filters. They are
  in `c2pa-rs` and in no file this project holds. Implementing a filter
  against no fixture is writing an untested branch that looks tested.
- `c2pa.hash.bmff.v2`, if a file with one ever turns up. The version is
  part of the label and a v2 assertion is not this one.
- `c2pa.hash.data.part`, `c2pa.hash.multi-asset`, and box hashes.

## Behavior

- **AC1 — the two fixtures verify** *(happy path; oracle: `c2patool` 0.27.22)*
  - Given `fixture-signed.mp4` and `fixture-signed.avif`
  - When each is verified with the test trust settings
  - Then the report carries `assertion.bmffHash.match`, the state is
    `Trusted`, and the failure codes equal those in
    `tests/Fixtures/c2patool/mp4.json` and `avif.json`. The refusal
    SPEC-026 produced is gone.

- **AC2 — one changed byte in an included box is a mismatch** *(required: the error path)*
  - Given each fixture with one byte of `mdat` altered
  - When it is verified
  - Then the report carries `assertion.bmffHash.mismatch` and the state is
    `Invalid`, and `c2patool` says the same on the same file.

- **AC3 — a box that moved is a mismatch even when its bytes did not**
  - Given a fixture whose included boxes are reordered, or into which a
    `free` box is inserted before them, so that every included byte is
    unchanged but the offsets are not
  - When it is verified
  - Then it is `assertion.bmffHash.mismatch`. This is the criterion the
    offset markers exist for, and without it the marker code could be
    deleted and every other test would still pass.

- **AC4 — the C2PA box excludes itself through the data filter**
  - Given the fixtures, whose `/uuid` exclusion carries
    `data: [{offset: 8, value: <C2PA UUID>}]`
  - When the exclusions are resolved
  - Then the C2PA box is excluded and a `uuid` box carrying any other UUID
    is **not** — shown on `isobmff/uuid-not-c2pa.mp4`, where the box stays
    in the digest.

- **AC5 — an exclusion this verifier cannot honour is refused, not ignored** *(malformed input)*
  - Given an assertion carrying a `subset`, `length`, `version` or `flags`
    filter, or a `merkle` field, or an `xpath` with more than one segment
  - When it is verified
  - Then the file is `Invalid` with the unsupported element named. Ignoring
    a filter would compute a digest over the wrong bytes and call the
    result a match, which is the one outcome this project refuses above all
    others.

- **AC6 — the assertion's own shape is checked**
  - Given an assertion with no `hash`, an `alg` this verifier does not
    implement, or an `exclusions` list that is not a list of maps
  - When it is verified
  - Then each is `Invalid` with its own message, and no digest is computed
    from a half-read assertion.

- **AC7 — the corpus verdicts are unchanged** *(the drift alarm)*
  - Given every fixture in the four corpora and the matrix
  - When each is verified
  - Then every state and every failure code is what it was before this
    spec: no JPEG, PNG or WebP file changes its answer because ISOBMFF
    gained a hard binding.

## References

- Specification: C2PA 2.4 §11.3 and the BMFF hash section; ISO/IEC
  14496-12 for the box structure.
- Measured, step 77 (`notes/step-77-bmff-hash-reproduced.md`): c2pa-rs
  v0.90.22 instrumented in a container with two `eprintln!` lines in
  `sdk/src/utils/hash_utils.rs`, run against this repository's own two
  fixtures; the marker offsets it printed are exactly the offsets of the
  top-level boxes no exclusion matches, and recomputing the digest by hand
  from that rule reproduces both stored hashes.
- Measured, step 73 (`notes/step-73-isobmff-fixture.md`): the exclusion
  list of both fixtures, and that the assertion is `v3` rather than the
  `v2` the brief names.
- Read, not measured: `bmff_to_jumbf_exclusions()` in
  `sdk/src/asset_handlers/bmff_io.rs` for the `length`, `version`,
  `flags`/`exact` and `subset` filters, none of which any fixture here
  exercises — which is why AC5 refuses them rather than implementing them.
- Oracle: `c2patool` 0.27.22, `tests/Fixtures/c2patool/mp4.json` and
  `avif.json`, recorded in step 73.

## API sketch

The shape follows `DataHashCheck`, and the streaming reader is the one
SPEC-012 already has: what is new is the conversion and the marker.

```php
// namespace Provemark\C2paVerifier\Hash;

final readonly class BmffHashCheck
{
    public const LABEL = 'c2pa.hash.bmff.v3';

    /**
     * @param  resource  $stream
     * @return list<ValidationStatus>
     */
    public function check(Manifest $manifest, $stream): array;

    /**
     * The top-level boxes no exclusion matches, in file order.
     *
     * @param  list<array{offset: int, length: int, type: string}>  $boxes
     * @param  list<array<string, mixed>>  $exclusions
     * @return list<array{offset: int, length: int}>
     */
    public static function included(array $boxes, array $exclusions, callable $readAt): array;
}
```

## Open questions

1. **A file whose first top-level box is included.** Both fixtures begin
   with `ftyp`, which is excluded, so every included box in them follows an
   exclusion. The rule measured is "a marker before every included
   top-level box"; the alternative the fixtures cannot rule out is "before
   every included box that follows an excluded one". A file with a C2PA box
   after `moov` would settle it, and the tests-first step should build one
   rather than assume. **Blocker: AC3 and AC1 assert the digest, and the
   two readings differ on any such file.**
2. **Nested exclusion paths.** Every `xpath` in both fixtures is a single
   segment. A path like `/moov/trak` resolves to a nested box in `c2pa-rs`;
   whether it contributes a marker of its own, and how it splits the
   parent's bytes, is unmeasured. AC5 refuses multi-segment paths for now,
   which is safe but may refuse files that exist in the wild. Non-blocker,
   and worth revisiting the first time such a file is seen.
3. **Where `included()` lives.** Resolving box paths needs the box walk
   SPEC-026's extractor already has, and duplicating it would be a second
   truth. Either the extractor exposes its walk (widening `Container`'s
   surface, which SPEC-025 marks `@internal` anyway) or the walk moves to
   `Support`. Non-blocker, but it is a Deptrac arrow either way.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
