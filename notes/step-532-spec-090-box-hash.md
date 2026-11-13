# Step 532 — SPEC-090 drafted and approved: the box hash for JPEG

*2026-11-13.* Maurice:
- "schrijf een draft spec voor de box hash";
- "ga verder met de box-hash-spec";
- SPEC-090 approved with every proposal (asked when "ga verder met de
  code" came before an approval).

No code.

## Read

- **C2PA 2.4** (`c2pa-org/specifications` at `4eb2c67`, the rendered HTML
  converted to text):
  - §9.2.2;
  - §15.12.3 and §15.12.3.1–3;
  - §18.7.1–18.7.3;
  - the CDDL `box-map`;
  - the five `assertion.boxesHash.*` codes.
- **C2PA 2.5** (the `.html.md` build at `f314ed9`, 2026-10-16). Its
  §14.12.3 and §16.3.3 were compared sentence by sentence with 2.4. Only
  the section numbers and "JPEG" → "JPEG 1" differ.
- **`c2pa-rs` at `58b9a092`:**
  - `box_hash.rs` (`verify_stream_hash_with_progress`, `split_exclusions`).
    Names are matched position by position. A name that differs, or a
    list that runs out, is `unknownBox`; boxes left over at the end are
    `unknownBox` too (CAI-13354). `C2PA` must stand alone in its map.
    `exclusions: []` is `malformed`. A missing `PNGh` is skipped.
  - `jpeg_io.rs` (`get_box_map`). `SOS` includes the entropy-coded data.
    APP1 (Exif, XMP), APP13 and `COM` payloads may be excluded as
    metadata.
- **Upstream #2949** (merged 2026-11-02), from its description: bytes
  after the last box become a synthetic `c2pa.after` box. Unlisted, it is
  `unknownBox`; listed and hashed, it is accepted.
- **SPEC-042 open question 3.** `store.rs` `is_uri_redacted` compares a
  `redacted_assertions` entry as written with `to_absolute_uri()` of the
  reference, which returns a slash-less URI unchanged. So `c2pa-rs` does
  not normalise either side.

## Measured

The Bing file's `c2pa.hash.boxes`, read with this verifier's manifest
reader:
- `alg: "sha256"`;
- twelve box-hash-maps of one name each: `SOI`, `C2PA` (hash `00`),
  `APP0`, `DQT`, `DQT`, `SOF0`, four `DHT`, `SOS`, `EOI`;
- no `exclusions`, no `excluded`, empty `pad`.

The file has one APP11 piece, the store (offset 2, 13,093 bytes), and no
bytes after `EOI`. A scratch script walked the segments and hashed each
listed box over its segment, with `SOS` up to the next marker that is
neither `FF00` nor `RSTn`. All eleven hashed boxes match.

## Decided by Maurice

SPEC-090 approved, its five open questions as proposed:
1. JPEG only;
2. boxes out of order are `mismatch`, by the letter;
3. a minor release (`0.11.0`) for the five `StatusCode` cases;
4. a listed `c2pa.after` is refused as `unknownBox`;
5. the `redacted_assertions` difference is recorded in
   `docs/comparison.md`, nothing changes.

## Next

Fixtures (the Bing variants and this project's own box-hash writer), both
`c2patool` oracles, then the failing tests.
