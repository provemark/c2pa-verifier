# Step 350 — Two specs for the exclusion gap: SPEC-068 and SPEC-069

*2026-10-13.*

Step 349 measured a wrong `Trusted`: a `c2pa.hash.data` may exclude
everything but the manifest store, or only the image data, and a pixel can
then change under a valid signature, here and in `c2patool` 0.28.x. This
step writes two specs, both `draft`. No code changes.

- **SPEC-068**: a data hash that leaves no byte of the file to hash is
  `assertion.dataHash.mismatch`. A floor, one line in `DataHashCheck`. One
  byte left gets past it, and its AC3 pins exactly that.
- **SPEC-069**: an exclusion beyond the store is allowed only where a
  measured writer puts one. That is JPEG APP1 payloads with an Exif or XMP
  identifier in the primary image. In every other place and every other
  data-hash format, `assertion.dataHash.mismatch`. An allow-list rather
  than a deny-list, because a missing entry then costs a wrong `Invalid`,
  not a wrong `Trusted`.

## Measured for the specs

A scan of 708 fixture files with `bin/c2pa-verify --text` (a scratch
script, not kept) found 393 `assertion.dataHash.match` statuses and one
file with additional exclusions:
`writers/google-20250919-pixel10-npld-picnic-table.jpg`. A marker walk over
that file shows its four ranges are exactly the payloads of its Exif APP1
segment and three XMP APP1 segments (`[6, 43697]`, `[50643, 1158]`,
`[51805, 65458]`, `[117267, 24606]`). No range touches the tables, the
frame header, the scan or the gain-map image. The fewest bytes any fixture
hashes is 51 (`text/nfd-emoji-signed.txt`), so no file hashes zero bytes.

## Not measured

Other cameras and apps. The allow-list holds the one measured writer and
grows only by amendment with a real file.
