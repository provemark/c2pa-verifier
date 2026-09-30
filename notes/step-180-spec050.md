# Step 180 — SPEC-050 built: a short read is not the end of the file

*2026-09-30. SPEC-050, drafted in step 179 and approved the same day,
built tests-first. PHP 8.5.8, OpenSSL 3.6.3.*

## Why

The WordPress plugin that bundles this verifier was measured next to WP
Offload Media, which hands out the AWS SDK's S3 stream wrapper. Opened as
seekable, that stream returns fewer bytes than asked on some reads, as
PHP's `fread()` may on any stream that is not a plain file. Five read
sites took a short read for the end of the file, and two genuine JPEGs came
out `Invalid` ("wanted 63992 bytes, got 16364"). A plain local file never
returns a short read before its end, so no test had seen it.

## The tests, red first

`tests/Support/ShortReadStream.php` is a stream wrapper for the tests
only: it serves a real fixture but returns at most 7 bytes per read, and
seeks, tells and stats as the file does. A stalling variant gives nothing
from a chosen offset on without ever reporting the end; a reader that keeps
asking it fails after 10,000 empty reads instead of hanging the suite.

`tests/Unit/Verifier/ShortReadTest.php`, group `SPEC-050`, before the
change: **11 failed, 1 passed**.

- AC1 (seven signed fixtures, JPEG to HEIC): the JPEG failed with
  "unexpected end of file while reading APP11 header of the segment at
  offset 20: wanted 16 bytes, got 10"; the six others with "unsupported
  file type", because the format detector got a 7-byte probe.
- AC2 (the five-fragment DASH stream): "unsupported file type", where the
  disk read is `Trusted`.
- AC3 (three truncated files): a different, earlier error than from disk.
- AC4 (a stream that stops giving bytes): **passed before the change**. It
  reads once today, so it cannot hang; the test guards the loop this step
  adds. It was not seen red, and says so here.

## What was built

- `src/Container/Read.php` (`@internal`): `Read::upTo($stream, $length)`
  reads until it has `$length` bytes or a read gives nothing (`''` or
  `false`); nothing is the end, whether or not `feof()` says so, so a
  stalled stream ends the read.
- `StreamReader::readExactly()` and `::readUpTo()`,
  `FormatDetector::head()`, and `BmffHashCheck`'s exclusion matcher and
  fragment leaf call it instead of a single `fread()`.
  `BmffHashCheck`'s range digest already looped and is unchanged;
  `RemoteManifestDetector` uses `stream_get_contents()`, which reads to the
  length or the end.
- No message, limit or status code changed.

## Measured

- After: the SPEC-050 group 12 passed; SPEC-004 7 passed.
- `composer check`: exit 0, **583 passed** (571 before); spec-check,
  api-check, Pint, PHPStan level max and Deptrac clean.
- **31,749 runs, before and after**: every media fixture (557) with no
  settings and with each of the 56 readable settings files, the verdict and
  every status code with its URL. The two outputs are identical; nothing
  was thrown.

## Reasoned, not measured

That no read site can turn a short read into a wrong `Valid` or
`Trusted`: a short read only removes bytes, and a missing byte makes a
hash differ or a structure fail to parse. The loop cannot add bytes that
are not in the stream.

## What no test covers

The AWS SDK's wrapper itself. The test wrapper stands in for any stream
that returns short reads; the S3 case was measured once, in the plugin's
environment, before this step.

## Disclosure

Not a security fix: the fault gave a wrong `Invalid` on a genuine file,
never a wrong `Valid` or `Trusted`. Local until the release that carries
it.
