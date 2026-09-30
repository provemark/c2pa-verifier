# SPEC-050: A short read is not the end of the file

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

`Verifier::verify($stream)` takes any readable, seekable stream. Every
place that reads from it calls `fread($stream, $n)` once and treats fewer
than `$n` bytes as the end of the file. PHP does not promise `$n` bytes.
The PHP manual for `fread()` says that when the stream is not a plain file,
at most one read of up to the chunk size is made, so a call can return
fewer bytes while the file goes on. Only `feof()` says the file has ended.

A plain local file never returns a short read before its end, so every
test and every fixture so far passed. Other streams do:

- **Measured** (the WordPress plugin `provemark/tracefern-image-check`, its `notes/offload-media.md`,
  2026-09-30): WP Offload Media Lite 3.4.3 hands out the AWS SDK's stream
  wrapper (`s3useast1://…`). Opened with its context option
  `seekable => true`, `fixture-signed.jpg` and a Lightroom JPEG, both
  `Valid` from disk, came out **`Invalid`** with `general.error`
  "unexpected end of file while reading piece 1 data of the segment at
  offset 20: wanted 63992 bytes, got 16364". Reading the same stream in a
  loop returned all 96,939 bytes, with 11 reads shorter than asked before
  the end. A copy of the same stream into `php://temp` gave `Valid`.

That is a wrong verdict on a genuine file: fail closed, but wrong. It
reaches anyone who hands the verifier something other than a local file:
a stream wrapper, a compressed or network-backed stream, or a
caller's own wrapper.

The read sites, read in `src/` at `0cf13e4`:

| where | reads | short read today |
|---|---|---|
| `Container\StreamReader::readExactly()` (SPEC-004), used by the JPEG, PNG, WebP and ISOBMFF extractors and by `DataHashCheck` | once | `ContainerException` "unexpected end of file", and in `DataHashCheck` a mismatch |
| `Container\StreamReader::readUpTo()` (SPEC-004) | once | fewer bytes, which PNG and WebP read as a missing header or pad byte |
| `Container\FormatDetector::head()` | once | a shorter probe; a format may not be recognised |
| `Hash\BmffHashCheck`, the exclusion matcher and the fragment leaf (SPEC-027, SPEC-028) | once | a shorter slice, so a wrong match or hash |
| `Hash\BmffHashCheck`, the range digest | in a loop | correct already |
| `Container\RemoteManifestDetector` | `stream_get_contents()` | correct: it reads until the length or the end |

Reasoned, not measured: a short read only ever removes bytes, and a
missing byte makes a hash differ or a structure fail to parse, so no read
site can turn a short read into a wrong `Valid` or `Trusted`.

## Scope

**In scope**

1. **One way to read *n* bytes.** A read asks again until it has the bytes
   it asked for or `feof()` is true. Only then does it return fewer bytes,
   and only then does a caller call the file truncated. All five sites in
   the table that read once use it.
2. **A stream that makes no progress.** `fread` returning `''` or `false`
   while `feof()` is still false would loop forever. It ends the read as
   if the file ended there, so the caller reports a truncated file: fail
   closed, never a hang.
3. The messages stay as they are. A truncated file reports the same code
   and text, through a slow stream as from disk.

**Out of scope** (each needs its own spec before it may be built)

- Streams that cannot seek or rewind. `FormatDetector` refuses them with
  an `InvalidArgumentException`, and SPEC-004 requires a seekable stream.
  Measured: the AWS SDK wrapper opened without `seekable` is refused this
  way, which is correct.
- A stream whose seek claims success but does not move (the same wrapper
  without `seekable`, measured: `fseek` to the end leaves `ftell` at 0).
  Seek results are a separate concern.
- Any change to a limit, a status code or a message.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and
will be covered by a Pest test tagged `->group('SPEC-050')`. The test
support has a stream wrapper, for tests only, that serves a real fixture
file but returns at most a few bytes per read (a small odd number, so
reads also end in the middle of headers), and seeks, tells and stats as
the file does.

- **AC1 — every format verifies the same through short reads**
  - Given `fixture-signed.jpg`, `.png`, `.webp`, `.mp4`, `.mov`, `.avif`
    and `.heic`
  - When each is verified from disk and through the short-read wrapper,
    with the same settings
  - Then both reports' `toArray()` are equal, state and status list
    included, for every file

- **AC2 — a fragmented stream verifies the same through short reads**
  - Given a fragmented ISOBMFF fixture from `tests/Fixtures/bmff-fragmented/`
    that is `Valid` from disk
  - When `FragmentedVerifier` reads the init segment and every fragment
    through the short-read wrapper
  - Then the report equals the report from disk

- **AC3 — a truncated file is still truncated** *(error path)*
  - Given `jpeg/truncated-in-piece-2.jpg`, `png/truncated-in-cabx.png`
    and `webp/chunk-overruns-file.webp`
  - When each is verified through the short-read wrapper
  - Then the state and status list, explanations included, equal those
    from disk: the loop does not read past the real end, and a truncated
    file is not accepted

- **AC4 — a stream that stops giving bytes ends the read** *(error path)*
  - Given a wrapper that serves the first part of `fixture-signed.jpg` and
    then returns `''` on every read without reporting the end of the file
  - When the file is verified
  - Then the verifier returns within the test's time limit with `Invalid`
    and the same kind of "unexpected end of file" explanation a truncated
    file gives; it does not hang and does not throw

## References

- Measured: `provemark/tracefern-image-check`, `notes/offload-media.md` (2026-09-30),
  WP Offload Media Lite 3.4.3, the AWS SDK's S3 stream wrapper against a
  local S3-compatible gateway; the verifier `v0.2.6` bundled there.
- PHP manual, `fread()`: for streams that are not plain files, a single
  read of up to the chunk size; `feof()` for the end of a stream.
- Oracle: the verdict of the same file read from disk by this verifier,
  which the fixtures' notes already tie to `c2patool` 0.27.22.
- Reasoned: the table of read sites (from `src/` at `0cf13e4`), and that
  no short read can produce a wrong `Valid`.
- Governing rule: SPEC-004 (the one stream reader; "never returns a
  partial result").

## API sketch

Illustrative only. The public API does not change.

```php
// namespace Provemark\C2paVerifier\Container;

/** @internal */
final class Read
{
    /**
     * Up to $length bytes: fewer only when the stream is at its end, or
     * gives nothing without saying so (treated as the end).
     *
     * @param resource $stream
     */
    public static function upTo(mixed $stream, int $length): string;
}

// StreamReader::readExactly() and ::readUpTo(), FormatDetector::head()
// and BmffHashCheck's two slices call Read::upTo() instead of fread().
```

## Open questions

- None blocking. The helper's name and place (`Container\Read`, or a
  method on `StreamReader` that `BmffHashCheck` can reach) are an
  implementation choice.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
