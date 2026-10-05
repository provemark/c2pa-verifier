# Step 209 — The signed AVI, and the file that has two RIFF chunks

*2026-10-05. `c2patool` 0.27.22 and 0.28.1, the c2pa-rs ES256 test
certificates. SPEC-013 amendment 15, SPEC-024 amendment 2 and SPEC-055
amendment 1 confirmed by Maurice van Loon the same day. No verifier code,
no spec yet.*

## Why

AVI is a RIFF form like WebP and WAV, and C2PA 2.4 §A.3.7 covers all
three in one sentence: the `C2PA` chunk is *"the last sub-chunk of the
first RIFF header chunk"*. SPEC-055 showed that the shared walk carries a
second form without a new rule. AVI has one thing the other two do not:
an AVI over about 1 GB (OpenDML) is several RIFF chunks in a row, `AVI `
first and then one or more `AVIX`. The shared walk refuses that today,
because it requires the first RIFF chunk's size to equal the file. This
step measures what `c2patool` does with such a file before any spec
decides it.

## The fixtures

- `tests/Fixtures/fixture-unsigned.avi` (11,700 bytes, SHA-256
  `8a7ea6949aceaab495a6f85378535ff4bc35d26a9f76af580b25c5ed25d7ef6b`; the
  sister library's `fixture.avi`, unchanged): `LIST hdrl`, `LIST INFO`,
  `JUNK`, `LIST movi` with ten frames, `idx1`.
- `tests/Fixtures/fixture-signed.avi` (25,172 bytes, SHA-256
  `520a2e2b37c1d5d9bb7214ebb76d467578dda7ba5236388f38aeb38efefeca3d`),
  signed with `c2patool` 0.27.22, the WebP manifest definition with the
  title changed (`fixture-signed-avi.manifest.json`):

  ```
  c2patool fixture-unsigned.avi -m fixture-signed-avi.manifest.json -o fixture-signed.avi -f
  ```

  The `C2PA` chunk is the last one, at 11,700, after `idx1`; its length is
  13,463 (odd, so a pad byte follows). The store: SHA-256
  `83b33b30ffa81b3c0668b8383fee7a553af4cd34af049e3f090fbd6359ffb287`,
  first bytes `00 00 34 97 6a 75 6d 62`. `Valid` in 0.27.22 and 0.28.1,
  `Trusted` with `--settings tests/Fixtures/trust/full.settings.json`.
- `tests/Fixtures/avi/signed-avix.avi` (25,304 bytes, SHA-256
  `2e257341afcc2aee08cd1869cf0f8c322ca28f82a3a156d1be01cb7984a65b5a`):
  `avi/unsigned-avix.avi` (built by `bin/make-avi-variants.php`) signed the
  same way. `c2patool` put the `C2PA` chunk at the end of the **first**
  RIFF chunk (whose size became 25,164) and left the `AVIX` chunk after
  it, as §A.3.7 asks. 0.28.1 does the same (measured on a scratch copy).

## Measured

Every answer is in `tests/Fixtures/avi/README.md`. In short:

1. **The cases WebP and WAV share behave the same for AVI** in both
   versions: two `C2PA` chunks, `C2PA` not last, a wrong RIFF size, a
   non-zero pad byte and trailing bytes are read and fail the data hash;
   a truncated store is a parse error; `C2PA` nested in `movi` is not
   found; `lbox-differs` is `Valid` in both versions, and `length-differs`
   is `Valid` in 0.27.22 and `Invalid` in 0.28.1. Nothing new for the walk.
2. **Two RIFF chunks are read, and the second is hashed.** `signed-avix.avi`
   is `Valid` in both versions; its exclusion is the `C2PA` chunk alone
   (`[11700, 13471]`). One flipped byte inside `AVIX` gives
   `assertion.dataHash.mismatch`.
3. **The second chunk's structure is not checked, but its bytes are.**
   An `AVIX` cut short, an `AVIX` size off by one, bytes after `AVIX`, and
   a second chunk of form `AVI ` instead of `AVIX` are all read, and all
   fail the data hash, because each changed bytes that were signed.
4. **Only the first RIFF chunk is searched.** A `C2PA` chunk moved into
   `AVIX` is *No claim found*; a copy added to `AVIX` beside the real one
   is ignored, and the hash fails.

## What this verifier says today

Every AVI is `unknown` (`general.error`, *unsupported file type*).
`signed-avix.avi` would be refused even with an AVI route, because the
first RIFF chunk's size (25,164) does not equal the file length less 8
(25,296). That would be `Invalid` where both `c2patool` versions say
`Valid`. It is not a wrong `Valid`, but a genuine large AVI could never
be verified.

## What it means (reasoned)

- AVI can use the shared walk for the first RIFF chunk, criterion by
  criterion, as WAV did.
- **The AVI spec has one real question: what follows the first RIFF
  chunk.** The data hash covers everything after the store, so accepting
  further RIFF chunks cannot make a changed file `Valid`. The choice is
  how much structure to demand of them: none (as `c2patool`), whole
  `RIFF`/`AVIX` chunks that end exactly at the end of the file, or that
  as well as no `C2PA` inside them. That is Maurice's decision when the
  spec is drafted.

## Found on the way: the package is over its ceiling

`composer check` failed in this step, in SPEC-023's package tests: *the
archive is 4.0 MB, over the ceiling of 4.0 MB* (4,208,640 bytes against
4,194,304). It is not this step's files: the test archives `HEAD`, and
`HEAD` is step 208's commit. Step 208's check ran before that commit, so
it measured step 207. The dist grows with the prose that ships (specs,
notes, AI log): 3.92 MB at `v0.2.6`, 4.13 MB at `v0.2.9`, 4.21 MB now.
Nothing is pushed.

**Decided by Maurice van Loon (option 1 of three: exclude the fixture
builders; raise the ceiling; both):** `bin/make-*.php` and
`bin/variant-helpers.php` are `export-ignore` (SPEC-023 amendment 2). They
read `tests/Fixtures/`, which does not ship, so they could not run in a
consumer's `vendor/` anyway. Measured: SPEC-023's tests 4 failed before,
13 passed after; the dist is 341 files and 3,665,920 bytes (3.5 MB);
`composer check` exit 0. The ceiling stays at 4 MB.

## Next

The AVI spec as `draft`, with the question about what follows the first
RIFF chunk spelled out for Maurice.
