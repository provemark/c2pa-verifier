# Step 352 — SPEC-069: where an exclusion beyond the store may lie

*2026-10-13.*

SPEC-068 (step 351) refuses a data hash over zero bytes. One byte left
got past it, and so did a PNG whose image data was excluded:
`idat-excluded-pixel-edited.png` was a wrong `Trusted` here and in
`c2patool` 0.28.x. SPEC-069, approved by Maurice today together with its
two choices (an allow-list, and "wholly inside one payload"), closes both.

## The rule

An exclusion of a `c2pa.hash.data` that holds no part of the manifest
store is allowed only wholly inside the payload of an APP1 segment of a
JPEG's primary image whose identifier is `Exif\0\0`,
`http://ns.adobe.com/xap/1.0/\0` or `http://ns.adobe.com/xmp/extension/\0`.
That is what the Google Pixel 10 excludes, the only writer among 708
fixtures that excludes anything beyond the store (step 350). Anywhere
else, and in every other data-hash format, it is
`assertion.dataHash.mismatch`, the explanation names where the range
falls, and the file is not hashed.

## What changed

- `src/Hash/ExclusionAllowance.php` (interface), `ExclusionRegion.php`,
  `NoExclusionAllowance.php` (nothing allowed: PNG, WebP, GIF, WAV, AVI,
  MP3, FLAC, plain text, and the default), `JpegExclusionAllowance.php` (a
  bounded marker walk from SOI to the first SOS, at most 1,024 segments;
  a walk that cannot reach SOS throws, and then nothing is allowed).
- `DataHashCheck::check()` takes the allowance as a fifth parameter,
  defaulting to none. The allowance is asked only when there are extra
  exclusions, so a file without them is never walked. The informational
  now names the regions: *"… honoured as signed, each inside an allowed
  region (APP1 Exif payload, APP1 XMP payload): …"*.
- `Verifier` passes JPEG's allowance for `jpeg` and none otherwise.
- `bin/make-exclusion-coverage-variants.php` gained four JPEG variants:
  Exif payload exactly (the positive control), the whole Exif segment, the
  ICC payload, and 64 bytes of scan data. All four are `Trusted` in
  `c2patool` 0.28.1 and 0.28.2, and were `Trusted` here before this step.
  All files and answers were regenerated under a new throw-away
  hierarchy; the PNG answers did not change.
- SPEC-012 amendment 11. Its AC4/AC5 tests use a test allowance for the
  honoured path and pin the refusal by default. SPEC-068 AC3's test
  checks that spec alone, since this one now answers the one-byte file.
  The drift alarm (SPEC-013 AC10) names the divergence on
  `variants/exclusion-extra`. Two rows in `docs/comparison.md`.

## Measured

- Tests first: 21 failed before the change (every SPEC-069 case, plus the
  amended SPEC-012 AC4 and AC5). After it, the group was green.
  `composer check` then failed twice, on SPEC-068's floor and the drift
  alarm, both expected changes, and both tests were brought in line.
- Mutations caught: containment by start only, no allowance for JPEG, no
  rewind after the walk, a region counting whether allowed or not, the
  whole segment allowed instead of the payload. "Any APPn checked for the
  identifiers" survived the first round, and the segment-map test now
  carries an Exif identifier in APP2.
- AC6, the corpus before and after: 793 fixture files outside
  `exclusion-coverage/`, without settings and under
  `trust/full.settings.json`, compared on state, every code and the full
  data-hash explanations. Exactly three files differ, all expected:
  `binding/exclusion-extra.png` and `exclusions-unsorted.png` (now
  refused, still `Invalid`) and the Pixel 10 file (only the
  informational's explanation; under its own trust settings it stays
  `Trusted`, AC1).
- `composer check`: 1,058 passed. The public contract is unchanged
  (135 symbols).

## Not measured

Cameras and apps other than the Pixel 10. A real file that excludes
somewhere else will now be `Invalid` here. It widens the list only by
amendment, with that file.
