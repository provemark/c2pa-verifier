# Step 358 — SPEC-071: which boxes a BMFF hash may exclude, built; and a Pixel video

*2026-10-13.*

Maurice approved SPEC-071 with route (a) (build now, fail closed) and put
it in 0.7.0.

## What changed

`BmffHashCheck::refusedExclusion()` holds the allow-list: the C2PA `uuid`
box by its data filter; `/ftyp`, `/mfra`, `/free`, `/skip`, `/meta/iloc`
and `/mfra/tfra`, whole or narrowed; and the offset fields of `stco`,
`co64`, `tfhd` and `trun`, only through a subset that starts at offset 16
or later. `check()` refuses any other exclusion with
`assertion.bmffHash.mismatch`, naming the path, before the box walk and
before anything is hashed. The script gained three variants: `stco`
excluded whole and from offset 8 (digest as signed), and `/zzzz`.

## Measured

- Tests first: 31 failed before (AC2–AC4), green after. The 3 AC1 cases pin
  existing behaviour.
- After the change, SPEC-038 AC5 moved on `bmff/xpath-nested.mp4`
  (`/a/be`), the changed answer the spec names. It is still `Invalid`,
  now refused by path, and the test names it.
- Mutations caught: the refusal never applied, offset fields from 8, an
  empty subset allowed, `/mdat` on the list, any uuid box allowed.
- Corpus before and after (all fixtures outside `bmff-exclusion-coverage/`,
  two modes): only `xpath-nested.mp4` differs, and not in state.
- `composer check`: 1,098 passed.

## A Pixel video, found the same day

A background search found a Pixel Camera video: the attachment to
`c2pa-rs` issue #2373 ("XPath matching does not support indexed paths",
CAI-13143, fixed by PR #2434). It is 4,846,215 bytes, sha256
`2f1c2bd8a056f86d6bd6d3b7e34c600104cdfe73999c5c94525c31b04d9774d6`, with no
licence, so it is kept outside the repository. Its `c2pa.hash.bmff.v3`
excludes `/ftyp`, the C2PA `/uuid`, `/mfra`, `/free`, `/moov/free`,
`/moov/udta`, `/moov/meta` and `/moov[1]/trak[3]` (a track with `nmhd`,
the timed metadata).

Under `trust/google-pixel-intermediates.settings.json`:

| | answer |
|---|---|
| `c2patool` 0.28.1 / 0.28.2 | `Valid` (`signingCredential.untrusted`); the BMFF hash matches |
| this verifier before SPEC-071 | `Invalid`: "box 00 00 00 00 at offset 4841342 declares 1751411826 bytes and runs past the end of the file" |
| this verifier after SPEC-071 | `Invalid`: "the exclusion /moov/free is refused" |

The cause of the first refusal: Pixel writes `/moov/meta` in the
QuickTime style, with no version and flags, so it starts directly with
`hdlr`. The box walk reads every `meta` as an ISO FullBox and skips four
bytes: 4,841,330 + 12 = 4,841,342. So Pixel videos were a wrong `Invalid`
here before this step, for a parsing reason, and SPEC-071 does not change
their verdict.

This is the trigger route (a) named. A follow-up spec has to cover three
things: the QuickTime-style `meta`, indexed paths, and Pixel's exclusions.
The last raises a design question, because excluding a whole track could
unbind a video track unless the allowed one is restricted, for example to
a track whose handler is timed metadata.
