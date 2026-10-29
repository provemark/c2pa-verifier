# Step 518 — At most 4,096 JPEG segments before SOS, at most 65,536 PNG chunks

*2026-10-29.* The last open item of the security review before 0.10.0:
"many tiny JPEG/PNG segments", listed but not measured by the review.
SPEC-001 amendment 6 and SPEC-002 amendment 4, approved before the tests
were written.

## The finding

The JPEG walk from SOI to SOS limited APP11 pieces (2,048) but no other
segment. The PNG walk to `IEND` limited the length of the `caBX` chunk
but not the number of chunks. Each segment or chunk costs a header read
and a skip, so a file of nothing but empty segments costs time in
proportion to its size. That is about 100 times the cost per byte of
hashing the same file. The project brief asks every parser for hard
limits, the segment count among them. `JpegExclusionAllowance` already
stopped after 1,024 segments before SOS (SPEC-024); the extractors did
not.

## What was measured

Before, on `e1d8f49`:

| file | parts | `verify()` | `c2patool` 0.28.3 |
|---|---|---|---|
| JPEG, empty COM segments (`FF FE 00 02`), 8 MB | 2,000,000 | 2.8 s | 0.10 s |
| JPEG, the same, 64 MB | 16,000,000 | 22.5 s | – |
| PNG, empty `tEXt` chunks, 24 MB | 2,000,000 | 4.3 s | 6.2 s |

Memory stayed between 6 and 14 MB, and every verdict was `Invalid` with
no manifest.

The most found in real files, counted over `tests/Fixtures`, the sister
repository's fixtures and the measurement repository:

- 35 segments before SOS, in 191 JPEGs
  (`public-testfiles/adobe-20220124-CAIAIIICAICIICAIICICA.jpg`, 25 of
  them APP11);
- 98 chunks, in 518 PNGs (an OpenAI image after WordPress). In
  `tests/Fixtures` the most is 64 (`c2pa-rs/sample1.png`).

After:

- The 8 MB and 64 MB JPEGs are refused at segment 4,097 in 0.011 s. The
  24 MB PNG is refused at chunk 65,537 in 0.14 s. All three are
  `Invalid`, `general.error`, `has_manifest` false.
- AC18 and AC16 on `e1d8f49`: no exception at 4,097 segments or 65,537
  chunks, so both failed. After: both pass. A first run of AC16 flagged
  `png/two-cabx.png`, because the test filtered on the word "chunks" and
  that file's message is "two caBX chunks …". The filter now matches the
  limit's own wording, and no fixture is refused.
- The corpus before (`e1d8f49`) and after: 2,198 runs, none differs.
- `composer check` (1,709 passed, 1 skipped), `vendor/bin/pest
  --parallel` and `bin/package-check.php` pass.

## What was done

`JpegManifestStoreExtractor` counts every segment before SOS, APP11
pieces included, against `maxSegments` (default 4,096). The default
leaves room for the 2,048 pieces `maxPieces` allows, plus as many other
segments.

`PngManifestStoreExtractor` counts every chunk, `IEND` included, against
`maxChunks` (default 65,536). With libpng's default `IDAT` size of
8 KiB, a PNG would need 512 MiB to reach that.

The segment or chunk that exceeds the limit throws a
`ContainerException` naming the limit and the offset. Before the store
is reached this is `storeReached` false, as for any other early fault.

This is a deliberate difference from `c2patool`, which reads any number
of segments and chunks. It is recorded in `docs/comparison.md`. No file
measured comes near either limit.
