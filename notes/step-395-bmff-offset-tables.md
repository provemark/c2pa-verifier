# Step 395 — SPEC-071 amendment 1: offset tables are hashed, never excluded

*2026-10-23.* The second finding of the review of 2026-10-23. Option A,
chosen by Maurice after the measurement below. It is a fix in released
code (SPEC-071, 0.7.0), so the commit stays local until a release.

## The finding

A BMFF hash may exclude boxes. SPEC-071 allowed the offset fields of
`stco`, `co64`, `tfhd` and `trun` (from offset 16) and `meta/iloc`,
because an older `c2pa-rs` wrote those exclusions. With them excluded,
the offsets that say where each chunk of media lies are not signed. With
the chunk offsets of `c2pa-rs/video1.mp4` changed, the binding still
matched here.

## Measured before choosing

- C2PA 2.4 §18.6.2 (read): boxes with file offsets "shall be included in
  the input data contributed to the hash with their post-embed values";
  the three ways it describes all hash them.
- `c2patool` 0.28.2 signs with `c2pa.hash.bmff.v3`, excluding only
  `/uuid`, `/ftyp`, `/mfra`, `/free`, `/skip`. The same `stco` rewrite of
  such a file is `assertion.bmffHash.mismatch` here.
- `c2pa-rs` 0.91.1 and 0.88.0 (source read): the offset exclusions in
  `set_default_exclusions_with_options()` are commented out, "no longer
  mandatory".
- Of the 46 BMFF fixtures, only `c2pa-rs/video1.mp4` (`v2`, c2pa-rs
  0.28.2, 2024) carries them; Pixel Camera does not.
- Option B (allow, but check that chunks lie inside a hashed `mdat`, do
  not overlap and rise per track) holds on every real file measured (the
  chunks tile `mdat` exactly) and catches the rewrite (5 overlaps). It
  needs a new `stsc`/`stsz` reader and covers `stco`/`co64` only. Not
  chosen.

## The fix

`BmffHashCheck::refusedExclusion()` refuses `stco`, `co64`, `meta/iloc`,
`tfhd` and `trun`, whole or in part, naming why. `/mfra` and
`/mfra/tfra`, the random-access index, stay allowed.

## Measured after

- Red first: 8 tests. Three existing tests that pinned `video1.mp4`'s
  match moved with it, as the amendment predicts.
- Mutations, each failing: `trun`, `stco`, `meta/iloc` back on the list.
- The corpus before and after: 20 of 1,854 runs, 10 files. By state and
  codes only `video1.mp4` moved (`match` → `mismatch`; `Invalid` under
  the corpus settings, `Valid` → `Invalid` under the DigiCert settings);
  the other nine were refused already, and only the wording moved.
- `composer check`: 1,293 passed, 1 skipped.

What it costs: a file an older `c2pa-rs` signed with these exclusions is
`Invalid` here.
