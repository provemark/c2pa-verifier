# Step 261 — GIF: SPEC-059 amendment 1 built

*2026-10-06. The three tests of step 260, red, made green.*

## Built

- **A — every extension is sub-blocks after its label.** The reader read a
  "block size" byte after every label; a Comment Extension has none, so an
  empty comment (`21 FE 00`) threw the walk out of step. Now a
  non-application extension is read as sub-blocks from its label on (the
  fixed block of a graphic control or plain text extension is simply the
  first); an Application Extension's first sub-block must still be 11 bytes
  (AC5).
- **B — sub-blocks read in pieces.** `subBlocks()` reads up to 64 KiB at a
  time, walks the size bytes in memory and seeks to just after the
  terminator; the store is appended to one string. The store bound is
  checked per sub-block; the memory budget is asked once per 64 KiB of
  store, for 64 KiB ahead (asking per sub-block cost 11 s on a 15 MiB store
  of 1-byte sub-blocks).
- **C — an empty `C2PA_GIF` block counts as a block.** The first block's
  offset is kept whether it holds data or not, so a second is refused; the
  store counts as reached at the first byte of data kept, or when a second
  block holds data.
- **D** — the 4,096-block bound stays; named in `docs/comparison.md`.
- **E** — `bin/fuzz.php` sends a file to the GIF reader on `GIF87a` or
  `GIF89a`, as `FormatDetector` does.

## Measured

- `GifTest`: 28 passed (three red in step 260). `composer check`: exit 0,
  833 tests; `bin/api-check.php`: the recorded surface matches.
- The reviewer's files, `php -d memory_limit=128M`:

  | file | before | now |
  |---|---|---|
  | a signed GIF with an empty comment (`Trusted` in both `c2patool` versions) | `Invalid`, `general.error` | `Trusted` |
  | a 4 MiB store in 1-byte sub-blocks (8 MB) | fatal: memory exhausted | `Invalid`, `general.error` (not JUMBF), 2.9 s |
  | a 15 MiB store in 1-byte sub-blocks (30 MB) | — | `Invalid`, `general.error`, 3.8 s, 59 MB resident |
  | 30 MB of another application's 1-byte sub-blocks | 30.5 s | 2.3 s, no store |
  | a signed GIF with 5,000 comments | `Invalid` | `Invalid` (D, named) |
  | 100 MB of comment before the image | 1.09 s | 0.41 s |

- The corpus against step 259: 2 of 1,440 measurements moved, both
  `gif/truncated-in-c2pa.gif`: the same verdict and code, the message now
  `unexpected end of file inside the block at offset 781`. Six new rows,
  the three new fixtures.
- 47 GIF files (the fixtures, the variants, five shapes signed by both
  versions and their flipped copies, the reviewer's files) with the test
  roots against `c2patool` 0.27.22 and 0.28.1: no `Valid` or `Trusted` here
  that both do not give; every other difference is a named one.
- Fuzzing: the release set of step 259 with seed 20261005 (17,109 runs over
  317 files, the three new fixtures shift the random sequence), 0 faults,
  127 `Valid`, each `Valid` in both versions; five GIF-focused seeds of 300
  rounds over 36 files (47,340 runs), 0 faults, every `Valid` (471) `Valid`
  in both. Two suspects of the GIF runs shared a name, because
  `signed-empty-comment.gif` was given twice; the same bytes.
