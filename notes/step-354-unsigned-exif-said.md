# Step 354 — SPEC-069 amendment 1: the allowed bytes are said to be unsigned

*2026-10-13.*

Golaszewski et al. (arXiv 2604.24890) changed the GPS in the Exif of a
Pixel 10 Pro file, inside its exclusion range, and a validator showed the
false location. SPEC-069 allows exactly that range. The verdict was right,
since the image is bound, but the informational ("honoured as signed,
each inside an allowed region (APP1 Exif payload, …)") could be read as
saying that the Exif data is signed. Maurice approved the amendment today.

The informational now ends: *"these bytes are not covered by the
signature: what they hold (Exif or XMP metadata such as a GPS position or
a capture time) is not verified"*. The code, url, state and allow-list are
unchanged.

## Measured

- Tests first: two assertions added to `ExclusionAllowanceTest` (the Pixel
  10 file and `jpeg-exif-allowed.jpg`). 2 failed before, green after.
- Corpus before and after: 793 files, two modes. Only
  `writers/google-20250919-pixel10-npld-picnic-table.jpg` differs, in this
  explanation alone.
- `composer check`: 1,058 passed.
