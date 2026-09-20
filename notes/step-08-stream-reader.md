# Step 08 — One stream reader (SPEC-004; SPEC-001 amendment 2)

*2026-09-20.* The step announced in steps 05 and 07: the three copies of
the stream helpers become one class, and the JPEG extractor's imprecise
end-of-file probe goes with them.

## What was built

`src/Container/StreamReader.php` — `final readonly`, wrapping the stream
and a noun ("segment" or "chunk") for its messages:

- `readExactly($length, $offset, $what)`: exactly the bytes or a
  `ContainerException` naming `$what`, the segment/chunk at `$offset`, how
  many were wanted and how many came. Zero bytes is `''` without a read.
- `readUpTo($length)`: up to the bytes, fewer at the end of the file — for
  the three callers that want to see a short read and name the fault
  themselves (the WebP twelve-byte header, the PNG chunk header, the WebP
  pad byte). Added while implementing, outside the API sketch; without it
  those three kept a direct `fread` and the reader was not the one seam it
  is meant to be.
- `skip($length, $offset)`: forward without reading, truncation decided by
  the file's end. Ends *inside* the skipped bytes: error naming the
  segment/chunk, where it ends and where the file ends. Ends *exactly*
  after: not the reader's error — the caller's next read reports what is
  missing.
- `tell()`, `end()` (the file length, position restored), `hex()` (the
  untrusted-bytes formatter, now in one place).

The three extractors construct one reader per `extract()` call and keep
their own marker/chunk logic. Their private `readExactly`, `skip`, `tell`,
`fileEnd` and `hex` are gone; `grep -n "fread\|fseek\|ftell"` over the
three files finds nothing. `src/Container/` went from 752 to 692 lines
with the new class included.

## The one behaviour change: SPEC-001 AC16

The JPEG `skip()` used to probe one byte after the segment (amendment 1).
For a file that ends exactly on a segment boundary that probe reported
"inside the segment" — for a segment that is whole. Measured before the
change on the new `truncated-between-segments.jpg` (20 bytes, SOI + APP0;
c2patool 0.27.22 → `Could not parse input JPEG`):

```
before: unexpected end of file inside the segment at offset 2
after:  unexpected end of file while reading marker of the segment at offset 20: wanted 1 bytes, got 0
```

The second names the byte that is missing. AC14's message gained the two
numbers the shared `skip` reports (`…at offset 2: it ends at 20, the file
at 12`), and its exact-message test was updated to match — same
criterion, more precise message.

## Measured

- Red: 7 SPEC-004 tests (the class missing; AC1 also on the three copies
  still present), SPEC-001 AC16 (the old message), SPEC-001 AC14 (the old
  message) — commit `302cfd0`.
- After the reader alone: SPEC-004 6 passed, AC1 still red (the copies).
- After the move: `composer check` exit 0 — spec-check `OK: 5 spec(s), 5
  test file(s)`, Pint passed, PHPStan `No errors`, Deptrac 0 violations,
  Pest **71 passed (164 assertions)**: the 63 of M1 unchanged in outcome,
  plus 7 and 1.

## Reasoned, not measured

- That "ends exactly after" is the caller's to report: the reader cannot
  know whether the caller expects more bytes. The PNG and WebP extractors
  already worked this way (steps 05, 07); the JPEG one now does too.
- `end()` costs two seeks per `skip`; on a file with many segments this is
  more syscalls than the probe's one read. Not measured; not a concern at
  M1's file sizes, and correctness came first.
