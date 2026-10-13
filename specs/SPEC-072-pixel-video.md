# SPEC-072: Pixel Camera video — the QuickTime `meta`, indexed paths, and Pixel's exclusions

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | SPEC-071's allow-list, which it widens by exactly Pixel's measured exclusions |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-071 named its own trigger: *"a Pixel video, once one is found"*. One
was found on 2026-10-13 (step 358). It is the attachment to `c2pa-rs`
issue #2373: 4,846,215 bytes, sha256
`2f1c2bd8a056f86d6bd6d3b7e34c600104cdfe73999c5c94525c31b04d9774d6`, with no
licence, so it is kept outside the repository. Under
`trust/google-pixel-intermediates.settings.json`, `c2patool` 0.28.1 and
0.28.2 verify its BMFF hash (`Valid`, the chain untrusted there). This
verifier says `Invalid`, for three reasons, each enough on its own:

1. **The QuickTime `meta`.** Pixel writes `/moov/meta` in the QuickTime
   style: no version and flags, so its first child (`hdlr`, handler
   `mdta`) starts right after the box header. The box walk
   (`IsobmffManifestStoreExtractor::CONTAINERS`, `'meta' => 4`) always
   skips four bytes, as ISO/IEC 14496-12 §8.11.1 has it for a FullBox. It
   then reads garbage at offset 4,841,342 ("declares 1751411826 bytes")
   and refuses the file. `c2pa-rs` reads both forms. This is a wrong
   `Invalid`, not a wrong `Trusted`.
2. **Indexed paths.** Pixel excludes `/moov[1]/trak[3]`, the third track,
   whose handler is `meta` ("MetaHandle", timed metadata); the first two
   are `vide` and `soun`. `c2pa-rs` resolves indexed steps since PR #2434
   (released in 0.91), with 1-based indices, as in XPath. This verifier
   compares paths literally, so the exclusion matches nothing.
3. **SPEC-071's allow-list.** Pixel also excludes `/moov/free`,
   `/moov/udta` and `/moov/meta`, which are not on the list, so the file
   is refused by path.

The full list Pixel writes (`c2pa.hash.bmff.v3`, sha256): `/ftyp`, `/uuid`
(the C2PA data filter), `/mfra`, `/free`, `/moov/free`, `/moov/udta`,
`/moov/meta`, `/moov[1]/trak[3]`. The same list appears in the issue and
in the capture that Darkrock-Studios/C2PAVerify keeps
(`pixel-video-bmff.detailed.json`, structure only).

**The design question in (3).** Allowing a whole `trak` to be excluded,
by its index, would let a signer exclude the *video* track: its sample
table says which `mdat` bytes are frames, in what order and with what
codec. The allow-list must therefore not allow "a track". It allows a
track whose handler is timed metadata, which is what Pixel excludes, and
nothing else. As in SPEC-069 amendment 1, the informational says that
`udta` and `meta` are not signed: they can hold a location and a capture
time.

## Scope

**In scope**

- **A. The `meta` box, both forms.** When the four bytes after a `meta`
  box's header are followed by `hdlr` (bytes 12–15 of the box are `hdlr`),
  it is read as QuickTime: children start at offset 8. Otherwise it is
  read as ISO, children at offset 12, as today. A `meta` that is neither
  (no `hdlr` at 12 or at 16) keeps today's reading.
- **B. Indexed paths.** An `xpath` step may carry `[n]` with `n` a decimal
  integer ≥ 1: the n-th child of that type under that parent, counted in
  file order. A step without an index matches every child of that type,
  as today. Any other bracket syntax (`[0]`, `[-1]`, `[a]`, `[1`, `[]`,
  or several indices on one step) is `assertion.bmffHash.malformed`.
- **C. Pixel's exclusions on the allow-list.** `/moov/free`, `/moov/udta`
  and `/moov/meta`, whole. And `/moov[1]/trak[n]` (any `n`), allowed only
  when the track it resolves to holds `mdia/hdlr` with handler type
  `meta`. A track with any other handler, or none, or an index that
  resolves to no track, is `assertion.bmffHash.mismatch` naming the path
  and the handler found.
- The informational (`assertion.bmffHash.additionalExclusionsPresent`),
  when any of C's exclusions is present, ends with *"these boxes are not
  covered by the signature: what they hold (user data, metadata, a
  timed-metadata track, such as a location or a capture time) is not
  verified"*.

**Out of scope** (each needs its own spec before it may be built)

- Indexed steps anywhere else on the allow-list (only `/moov[1]/trak[n]`
  is allowed indexed). Other writers trigger their own amendment, with a
  real file.
- What the timed-metadata track holds (gyro, location), and how a caller
  should display it.
- Other QuickTime-specific boxes beyond `meta` (`wide`, `keys` inside
  `meta`): the walk does not descend into them today and need not.

## Behavior

- **AC1 — the Pixel video's binding is verified** (measured locally; not
  in CI, see Open questions)
  - Given the Pixel file at a path named by an environment variable
    (`C2PA_PIXEL_VIDEO`), under `trust/google-pixel-intermediates.settings.json`
  - When verified
  - Then `assertion.bmffHash.match`, the informational with AC C's
    sentence, and the same state and failure codes as `c2patool` 0.28.2.
    Without the variable, the test is skipped and says why.

- **AC2 — a QuickTime `meta` in CI** (synthetic fixture)
  - Given `fixture-signed.mp4` rebuilt with a QuickTime-style `/moov/meta`
    (an `hdlr` of type `mdta` as first child) and a third track with
    handler `meta`, re-signed under a throw-away root, its exclusions
    Pixel's list, and the digest computed by this verifier's own BMFF
    hash
  - When verified, and when `c2patool` 0.28.2 verifies it
  - Then this verifier says `assertion.bmffHash.match` and so does
    `c2patool`. The external oracle is what makes a digest computed by the
    code under test acceptable.

- **AC3 — the video track excluded by index is refused** *(required: error path)*
  - Given the AC2 fixture with `/moov[1]/trak[1]` (handler `vide`) in
    place of `trak[3]`, and one with `/moov[1]/trak[9]` (no such track)
  - When verified
  - Then `assertion.bmffHash.mismatch` naming the path and the handler
    found (`vide`, or none), before anything is hashed.

- **AC4 — malformed indices** *(required: malformed input)*
  - Given exclusions with `xpath` `/moov[0]/trak`, `/moov[1]/trak[-1]`,
    `/moov[a]`, `/moov[1`, `/moov[]`, `/moov[1][2]`
  - When the assertion is read
  - Then `assertion.bmffHash.malformed`, naming the step.

- **AC5 — both `meta` forms in the box walk**
  - Given byte strings of an ISO `meta` (version and flags, then `hdlr`)
    and a QuickTime `meta` (`hdlr` at once), each with one child after it
  - When `boxTree()` walks them
  - Then both list the same children at the right offsets, and a `meta`
    whose bytes match neither form is walked as today.

- **AC6 — nothing else moves**
  - Given the corpora and every fixture
  - When verified before and after
  - Then no state or code changes outside the new fixtures. A file with an
    ISO `meta` (the HEIC and AVIF fixtures) walks as before.

## References

- Specification: C2PA 2.4 §18.6 (BMFF hash, `xpath`); ISO/IEC 14496-12
  §8.11.1 (`meta` as a FullBox); the QuickTime File Format's `meta`
  (no version and flags), as `c2pa-rs` reads it.
- Oracle: `c2patool` 0.28.2 on the Pixel file (locally) and on the AC2
  fixture (in CI's recorded answers).
- Measured (step 358): the Pixel file's box layout, its three handlers,
  its `meta`'s first child, and its exclusion list.
- Reasoned: that `c2pa-rs` indices are 1-based. This is confirmed by the
  file: `trak[3]` is the third track and the one with handler `meta`.

## API sketch

```php
// Container\IsobmffManifestStoreExtractor: CONTAINERS['meta'] becomes a probe:
//   bytes 12–15 'hdlr' → skip 0 (QuickTime), else skip 4 (ISO).
// Each box entry gains 'index' (1-based among same-type siblings), so that a
//   path step "trak[3]" can be matched.
// Hash\BmffHashCheck: matches() parses steps with an optional [n];
//   refusedExclusion() gains the Pixel entries; the trak[n] rule is checked
//   after the box walk, against the resolved track's mdia/hdlr.
```

## Open questions

- **The Pixel file in CI (blocking for approval).** The file has no
  licence. Options: (a) keep it out, test it locally behind
  `C2PA_PIXEL_VIDEO` (AC1), and let CI rest on the synthetic AC2/AC3
  fixtures; (b) ask the issue's author for permission to include it under
  a licence. That is outward-facing, so it is Maurice's call. Proposal:
  (a) now, and (b) later if it is wanted.
- **Release (non-blocking).** In 0.7.0, so that Pixel videos stop being a
  wrong `Invalid` in the same release that tightens the allow-list?
  Proposal: yes.
- **The informational's sentence (non-blocking).** It applies to `udta`,
  `meta` and the metadata track, not to `free`/`skip`, which are padding.

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
