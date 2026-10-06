# SPEC-059: GIF — the `C2PA_GIF` Application Extension → manifest store bytes, verified

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

C2PA 2.4 §A.3.8 puts the store of a GIF in one Application Extension block
(`21 FF 0B`, identifier `C2PA_GIF`, the authentication code `01 00 00` used
as the block's version 1.0), split into data sub-blocks of at most 255
bytes and ended by `00`, "after the header and prior to the first image
descriptor", quantity one. A signed GIF is `unknown` here today:
`Invalid`, `general.error`, *unsupported file type* — no wrong verdict, but
no useful one either.

Step 256 (`notes/step-256-gif-measured.md`) measured what `c2patool`
0.27.22 and 0.28.1 write and read. Both insert the block first, right after
the global colour table, and change nothing else; the data hash has one
exclusion, **the whole block** from `0x21` to its terminator, so the block's
header and the sub-block size bytes are excluded, not hashed. Eighteen
variants (`tests/Fixtures/gif/`) were answered alike by both versions.

The binding is `c2pa.hash.data`; SPEC-012's check applies unchanged once a
reader returns the store with the block as its range.

## Scope

**In scope**

- A reader that walks the blocks between the global colour table and the
  first image descriptor: extensions (introducer `0x21`, a label, a block
  of its stated size, then sub-blocks to a `00`), stopping at the first
  image descriptor (`0x2C`) or the trailer (`0x3B`). Image data is never
  read.
- The `C2PA_GIF` block recognised by its block size 11, identifier and
  version `01 00 00` together; its sub-blocks joined into the store,
  checked as a JUMBF box (its LBox equal to the joined length), within
  SPEC-024's store bound.
- The result as `ManifestStoreBytes`, its range the whole block, as the
  exclusion `c2patool` writes.
- Detection: `GIF87a` and `GIF89a` are `gif`.
- The verifier route, `format` `gif`, the unknown-format message naming
  GIF, and `has_manifest` from whether the block had been reached
  (SPEC-013 amendments 16–18).

**Out of scope** (each needs its own spec before it may be built)

- A `C2PA_GIF` block after the first image descriptor: not looked for, as
  in `c2patool` (AC9).
- Checking the GIF's images, frames, colour tables or other extensions
  beyond walking past them; the data hash covers them.
- XMP in a GIF, and a remote manifest URL in a GIF: not read.
- A release.

## Behavior

Variants are under `tests/Fixtures/gif/` (`bin/make-gif-variants.php`,
step 256); every `c2patool` answer is in that directory's README.

- **AC1 — the fixture yields the store, byte-exact, with the block as its range**
  - Given `tests/Fixtures/fixture-signed.gif`
  - When the extractor runs
  - Then 130,155 bytes, the sub-blocks of the `C2PA_GIF` block joined,
    first bytes a JUMBF box header (`jumb`), SHA-256 equal to the join
    measured in step 256 (the full value in the test), one range
    `[781, 130681]`

- **AC2 — no `C2PA_GIF` block is no manifest**
  - Given `fixture-unsigned.gif`, `gif/ident-other.gif` and
    `gif/c2pa-empty.gif` (a block with no sub-blocks)
  - Then the extractor returns `null`; verified, `format` `gif`,
    `hasManifest` false, no failure (*No claim found* in both versions)

- **AC3 — a block of another version is not a store**
  - Given `gif/auth-2-0.gif` and `gif/auth-1-1.gif`
  - Then `null`, as AC2 (*No claim found* in both versions): a version
    this verifier does not know is not read as 1.0

- **AC4 — two `C2PA_GIF` blocks are an error** *(required: malformed input;
  stricter than the oracle, named)*
  - Given `gif/two-c2pa.gif`
  - Then `ContainerException` naming the two offsets; verified, `gif`,
    `hasManifest` true, `Invalid`, one `general.error`. (`c2patool` reads
    the first and the data hash catches the second: `Invalid`,
    `assertion.dataHash.mismatch`.) The rule of SPEC-003 AC7 and SPEC-056
    for a second store.

- **AC5 — a malformed block is an error** *(required: malformed input)*
  - Given `gif/block-size-12.gif` (an Application Extension whose block
    size is 12), `gif/c2pa-not-jumbf.gif` (a payload that is not JUMBF),
    and `gif/early-terminator.gif` (the store's tenth sub-block size set
    to 0, so stray bytes follow the block)
  - Then each is a `ContainerException` naming what and where; verified,
    `gif`, `Invalid`, one `general.error`. All three are errors in both
    versions.

- **AC6 — a file cut inside the block is an error, after the store was reached**
  - Given `gif/truncated-in-c2pa.gif`
  - Then `ContainerException` (unexpected end of file); verified, `gif`,
    `hasManifest` true, `Invalid`, `general.error` (an error in both
    versions)

- **AC7 — what the hash judges is read and left to the hash**
  - Given `gif/rechunked-100.gif` (the store in 100-byte sub-blocks),
    `gif/c2pa-after-netscape.gif` (the block after NETSCAPE2.0, still
    before the image), `gif/truncated-after-c2pa.gif`, `gif/no-trailer.gif`,
    `gif/trailing-bytes.gif`
  - Then each store is read, and each verifies `Invalid` with
    `claimSignature.validated` and `assertion.dataHash.mismatch`, as both
    versions say

- **AC8 — the range is the whole block**
  - Given the fixture and `gif/rechunked-100.gif`
  - Then each range starts at the block's `0x21` and ends after its `00`
    terminator: `[781, 130681]` and `[781, 131472]`

- **AC9 — nothing after the first image is read**
  - Given `gif/c2pa-after-image.gif`
  - Then `null` (*No claim found* in both versions); and a test that
    counts the stream's reads shows no byte of image data was read for the
    fixture

- **AC10 — the bounds apply before memory is spent** *(as SPEC-024)*
  - Given a GIF with more than 4,096 blocks before its first image (built
    in the test), and a `C2PA_GIF` block whose sub-blocks add up to more
    than the store bound (built in the test, with a small bound)
  - Then each is a `ContainerException` naming the bound, before the
    blocks or the store are held

- **AC11 — detection: `GIF87a` and `GIF89a` are `gif`, nothing else is guessed**
  - Given the fixture, `gif/gif87a.gif`, `fixture-signed.png`, and the
    bytes `GIF88a…`
  - Then `gif`, `gif`, `png`, `unknown`; and `gif/gif87a.gif`, verified,
    is `Invalid` with `assertion.dataHash.mismatch`, as both versions say;
    the unknown-format message names GIF

- **AC12 — the signed fixture verifies as `c2patool` says**
  - Given `fixture-signed.gif`, without settings and with
    `trust/full.settings.json`
  - Then `Valid` and `Trusted`, state and sorted codes equal to the four
    recordings under `tests/Fixtures/c2patool/gif/`; `gif/flip-image.gif`
    is `Invalid` with `assertion.dataHash.mismatch`; `gif/flip-store.gif`
    is `Invalid` with `assertion.hashedURI.mismatch`, as both versions say

## References

- Specification: C2PA 2.4 §A.3.8 (read in step 256); the GIF89a
  specification (W3C, 1990) only to walk the blocks: §15 data sub-blocks,
  §20 the image descriptor, §26 the Application Extension.
- Oracle: `c2patool` 0.27.22 and 0.28.1; the fixture and the 18 variants
  of step 256, every answer in `tests/Fixtures/gif/README.md`; the four
  recordings under `tests/Fixtures/c2patool/gif/`.
- Reasoned: that stopping at the first image descriptor loses nothing a
  `c2patool` reading finds (AC9's oracle), and that the 4,096-block bound
  is far above any real GIF's blocks before its first image (measured
  when built).

## API sketch

```php
/** @internal SPEC-059 */
final readonly class GifManifestStoreExtractor
{
    public const DEFAULT_MAX_STORE_LENGTH = 16 * 1024 * 1024;   // SPEC-024's bound
    public const MAX_BLOCKS = 4096;                              // before the first image

    public function __construct(int $maxStoreLength = self::DEFAULT_MAX_STORE_LENGTH, MemoryBudget $budget = new MemoryBudget) {}

    /** @param resource $stream  @return ManifestStoreBytes|null  @throws ContainerException */
    public function extract($stream): ?ManifestStoreBytes;
}
```

`FormatDetector` returns `'gif'`; `Verifier` gains the extractor as its
last constructor parameter; a fault before the block is reached carries
`storeReached: false` (`ContainerException::withStoreReached()`, step 253).

## Open questions

1. **Two blocks (AC4): refuse, stricter than `c2patool`.** Proposal: yes,
   as for a second RIFF `C2PA` chunk and a second ID3 GEOB. The hash
   already makes the file `Invalid` in `c2patool`, so the verdict state is
   the same; only the code differs (`general.error` against
   `assertion.dataHash.mismatch`). Non-blocker.
2. **Another version (AC3) and an empty block (AC2): no manifest, as
   `c2patool`.** Proposal: yes. Neither can yield a wrong `Valid`; refusing
   them would turn a file `c2patool` calls unsigned into an error.
   Non-blocker.
3. **When the store counts as reached (`has_manifest`).** Proposal: once a
   block with block size 11, identifier `C2PA_GIF` and version `01 00 00`
   has been read; so `block-size-12` (AC5) is `hasManifest` false, and
   `truncated-in-c2pa` (AC6) true. Non-blocker.
4. **Amendments this forces** (named now): SPEC-013 (the `format` value
   `gif`, the message, the constructor parameter), SPEC-024 (the GIF
   bound in AC1's list). Non-blocker.

## Amendments

None.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
| AC10                 | —                           | —                    |
| AC11                 | —                           | —                    |
| AC12                 | —                           | —                    |
