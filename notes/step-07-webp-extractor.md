# Step 07 — The WebP extractor (SPEC-003 implemented); M1 complete

*2026-09-20. Oracle: c2patool 0.27.22, measured in step 06.*

## What was built

`src/Container/WebpManifestStoreExtractor.php`, the third and last
extractor of M1; `ManifestStoreBytes` and `ContainerException` reused.

The walk, in the order the code does it:

1. Twelve bytes of header: `RIFF` (AC3), the size, the form type `WEBP`
   (AC4 — c2patool reads a `WAVE` form as WebP; this extractor is for
   WebP and says so).
2. **The size against the file, before anything else.** One seek to the
   end gives the file length; the stream is put back at offset 12 *before*
   the comparison, so that after the exception it stands where the spec
   says (AC16 measured `ftell ≤ 12`, and was red until the reposition moved
   ahead of the throw). A size that disagrees with the file is one error
   naming both numbers (AC5) — four fixtures, one message: the header +1,
   the header as if `C2PA` were absent, and both truncations. With the size
   proven equal to the file, the loop's end is simply the file's end.
3. Chunk by chunk: eight bytes of header (`unpack('a4type/Vlength')` —
   little-endian, unlike PNG), an overrun check against the end (AC6), then
   either `fseek` over the data (AC8: position is not this layer's concern)
   or, for `C2PA`: refuse a second (AC7), the limit (AC14) and the 8-byte
   minimum (AC11) before any read, the LBox from the first four bytes —
   big-endian inside the box although RIFF is little-endian around it —
   against the chunk length (AC9, AC10), then the rest of the data.
4. After every odd-length chunk, one pad byte: present and `00` (AC12);
   never part of the store (AC1's hash is of 100,635 bytes, the pad
   excluded). This is what makes AC13's odd `XXXX` chunk harmless.

## Measured

- Before: 21 failed on the missing class (commit `baaeb13`).
- First run of the class: 20 passed, 1 failed — AC16, `ftell` at 100,956
  because the file-end seek preceded the throw and nothing put the stream
  back. Fixed by repositioning before the comparison.
- After: `composer check` → exit 0: spec-check `OK: 4 spec(s), 4 test
  file(s)`, Pint passed, PHPStan `No errors`, Deptrac `Violations 0`,
  Pest **63 passed (139 assertions)**.

## Stricter than the oracle, on purpose

Seven of the sixteen fixtures c2patool extracts (and mostly lets M4
reject) are errors here, each written next to its criterion and in the
fixture README: form type not `WEBP` (AC4), a header size that disagrees
with the file (AC5 — the maintainer's decision in step 06), two `C2PA`
chunks (AC7 — c2patool takes the first silently), LBox against the chunk
length in both directions (AC9, AC10), an empty chunk (AC11), and a pad
byte missing or non-zero (AC12 — the maintainer's decision). All the
container disagreeing with itself; all errors where the oracle says
`Valid` or `Invalid`, never the reverse.

One consequence worth stating plainly: `riff-size-excludes-c2pa.webp`,
which c2patool reports as "no claim found", is an error here, not
`null`. A file whose header hides a chunk that is physically present is
not a file without a store.

## M1 is complete

Three containers, three extractors, 52 fixtures, 63 tests; every
"done when" of M1 measured: the SHA-256 of the extracted store equals
what a probe of the file gives, for JPEG (`f47af93e…`, 94,740 bytes,
two pieces), PNG (`1a018eb8…`, 46,025 bytes) and WebP (`5062cb0a…`,
100,635 bytes), each with LBox equal to the box and the box a `jumb` with
a `c2pa` description. What M1 does not know is what is inside — that is
M2.

## Next, before M2

The three extractors carry three copies of `readExactly`, `tell` and a
`skip`, and two different answers to "where does a file end" (the JPEG
probe, the PNG/WebP seek). One step, its own spec or amendment, to fold
them into a shared stream reader and harmonise the JPEG probe (step 05).

## Reasoned, not measured

- The pad byte must be zero: the RIFF specification; no writer known to
  emit another value; c2patool does not check.
- Chunk types that are not four printable ASCII bytes are shown as hex in
  messages and otherwise not checked (as SPEC-002).
