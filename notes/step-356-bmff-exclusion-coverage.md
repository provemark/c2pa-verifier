# Step 356 — A BMFF hash may exclude the media: measured

*2026-10-13.*

The BMFF twin of step 349. In a `c2pa.hash.bmff` assertion an exclusion is
a box path, not a byte range. `BmffHashCheck::hasAdditionalExclusions()`
honours every path beyond `/ftyp`, `/mfra` and the C2PA `uuid` box with
the informational `assertion.bmffHash.additionalExclusionsPresent`. SPEC-069
left BMFF out of scope.

## Built

`bin/make-bmff-exclusion-coverage-variants.php`, from
`bin/make-spec038-variants.php` (its port of c2pa-rs's BMFF digest first
reproduces the digest the fixture was signed with). It writes five MP4s to
`tests/Fixtures/bmff-exclusion-coverage/` (see the README there) and pins
both `c2patool` answers.

## Measured

| file | c2patool 0.28.1 / 0.28.2 | this verifier |
|---|---|---|
| `unchanged-resigned` | `Trusted` | `Trusted` |
| `mdat-excluded`, `-edited` | `Trusted` | `Trusted` |
| `moov-excluded`, `-edited` | `Trusted` | `Trusted` |

As the control, the same mdat byte changed without the exclusion gives
`assertion.bmffHash.mismatch` in both. So the `-edited` files are a wrong
`Trusted` in all three verifiers. The informational is no warning: all
three also report it on the unchanged file, for `/free` and `/skip`.

Which paths real files exclude (36 BMFF hash assertions in the fixtures):
31 × `/uuid /ftyp /mfra /free /skip`; 2 × that plus `/meta/iloc`,
`stco`/`co64`, `tfra`, `tfhd`/`trun` (c2pa-rs `video1.mp4`); one synthetic
`/a/be`; 2 × none. No file excludes `/mdat` or `/moov`.

## Found while checking whether it was known

- `c2pa-rs` PR #2668 (merged 2026-09-21) started as "reject zero-coverage
  hard bindings", including BMFF and a whole-file data-hash exclusion
  (CAI-13331). As merged it only rejects a truncated box-hash list. Its
  BMFF and data-hash parts were dropped. That matches the measurements
  here and in step 349: `c2patool` 0.28.2 accepts both shapes.
- `c2pa-rs` PR #2434 added indexed xpaths (`/moov[1]/trak[3]`). A commit
  that links to it says Pixel writes `/moov[1]/trak[3]` to exclude its
  timed-metadata track. `BmffHashCheck::matches()` compares the path
  literally, so such an exclusion matches nothing here. A Pixel video
  would then fail its hash. Reasoned only: no Pixel video is in the
  fixtures. A BMFF allow-list has to account for it.
- A separate question, not examined: c2pa-rs itself excludes the offset
  tables (`stco`/`co64`, `iloc`). Whether changing them can make a player
  read other, hashed bytes as samples is worth its own look.

## Next

SPEC-071 (draft), for Maurice: a BMFF allow-list.
