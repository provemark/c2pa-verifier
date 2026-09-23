# Step 05 — The PNG extractor (SPEC-002 implemented)

*2026-09-20. Oracle: c2patool 0.27.22, measured in step 04.*

## What was built

One class, `src/Container/PngManifestStoreExtractor.php`, next to the JPEG
one; `ManifestStoreBytes` and `ContainerException` are reused unchanged.
The maintainer wrote the class skeleton and the AC1 test; the rest of the
tests and the body of the class were written with Claude Code, one test at
a time with an explanation, then the remainder (`AI-LOG.md`).

The walk: the eight-byte signature, then chunk after chunk — eight bytes of
header (length, type), and:

- `IEND`: stop.
- anything but `caBX`: skip data and CRC with `fseek`, unread. Where the
  chunk sits is not this layer's concern (AC8, AC9): c2patool extracts a
  `caBX` after `IDAT` or before `IHDR` and lets the hash binding reject the
  file with `assertion.dataHash.mismatch`, which is the more precise verdict.
- `caBX`: refuse a second one (AC5, both offsets named); check the length
  against the limit (AC12) and the 8-byte minimum (AC11) before reading
  anything; read the first four bytes, LBox, and compare with the chunk
  length (AC7, AC10); only then the rest of the data and the four CRC
  bytes; compute `crc32('caBX' . data)` and compare (AC6). Keep walking, to
  see a second `caBX`.

Two orders matter. LBox is checked before the CRC because a wrong length
field (AC10) breaks both, and the LBox message is the one that names the
cause. The limit is checked before the LBox read so that an oversized chunk
costs eight bytes of reading (AC12 measures `ftell ≤ 41`).

## Measured

- Before: `vendor/bin/pest --group=SPEC-002` → 15 failed, all on the
  missing class (commit `c514e5d`).
- First run of the class: **14 passed, 1 failed** — AC14.
- After the fix below: `composer check` → exit 0: spec-check `OK: 3
  spec(s), 3 test file(s)`, Pint passed, PHPStan `No errors`, Deptrac
  `Violations 0`, Pest **42 passed (93 assertions)**.

## AC14 found a flaw in the end-of-file probe

The JPEG extractor's `skip()` probes one byte after the skipped segment, so
that a file truncated inside that segment is reported at the segment's
offset (SPEC-001 amendment 1). The same probe, copied here, failed AC14:
`truncated-between-chunks.png` ends *exactly* after `IHDR`'s CRC, at
offset 33, and the probe reported `unexpected end of file inside the chunk
at offset 8` — inside `IHDR`. But the file does not end inside `IHDR`; it
ends after it, where the next chunk header should start. Those are two
different faults, and the AC asks for the second: `a chunk header was
expected at offset 33`.

The fix replaces the probe with a look-up of the file's end (`fseek(0,
SEEK_END)`, `ftell`): if the end lies before the end of the chunk, the file
is truncated inside it, named by its offset; if not, seek to the chunk's
end and let the loop report the missing header. Two seeks per skipped
chunk, no read.

The JPEG extractor still carries the probe, with the same imprecision for
a file that ends exactly on a segment boundary before SOS — an error in
any case, only the offset in the message is off. Left as is: SPEC-001 has
no criterion for that boundary, and changing it belongs to a step that
adds one. When WebP (SPEC-003) becomes the third copy of these stream
helpers, the three should become one shared reader; that is the step to
harmonise it.

## Stricter than the oracle, on purpose

Three variants that c2patool accepts or reads as absent are errors here,
each written next to its criterion in the spec and in the fixture README:
a CRC that does not match (AC6; c2pa-rs reads it and discards it), an
LBox that differs from the chunk length (AC7; c2pa-rs never compares
them), and an empty `caBX` (AC11; c2patool says `No claim found`). All
three are the container disagreeing with itself. The direction is the
safe one — error where the oracle says `Valid`, never the reverse — as
with SPEC-001's AC7.

## Reasoned, not measured

- `crc32()` is the PNG CRC in general (both are the IEEE CRC-32);
  measured on the fixture's four chunks and on the AC6 variant.
- Chunk types that are not four ASCII letters are not checked (SPEC-002
  open question, non-blocker).
- Bytes after `IEND` are never read; a second `IEND`, or a `caBX` after
  `IEND`, is invisible. c2patool stops at `IEND` too.
