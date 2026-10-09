# Step 299 — ISOBMFF in the fuzzer

*2026-10-09. Tooling, and a finding for the next step; no change to `src/`.*

Until now `bin/fuzz.php` hardly reached ISOBMFF (MP4, MOV, AVIF, HEIC). Its
file filter did not know the extensions, `fuzzStore()` had no branch for
`ftyp`, and none of the ISOBMFF sets were in the default list. Only
`fixture-signed.mp4`, named as a file, was mutated, and only blindly: the
mutations aimed at the store were skipped because no store was found. Yet
ISOBMFF, with `c2pa.hash.bmff.v2`, its Merkle trees and its exclusions
(C2PA 2.4 §A.5; SPEC-029, SPEC-051), is the least documented part.

## What changed

- `mp4`, `mov`, `avif` and `heic` added to the file filter.
- `fuzzStore()` reads a file that has `ftyp` at offset 4 with
  `IsobmffManifestStoreExtractor`, as `FormatDetector` does (SPEC-026).
- Added to the default list: `fixture-signed.mov`, `.avif` and `.heic`,
  and the sets `isobmff`, `bmff`, `bmff-shape`, `bmff-tail`,
  `bmff-fragmented` and `bmff-fragmented/broken`. Segments (`.m4s`) are
  not fuzzed on their own.
- With `--trust`, the signed ISOBMFF fixtures and `c2pa-rs/video1.mp4` are
  verified under `trust/full-plus-digicert-g4.settings.json` (through the
  extensions), and the `bmff-shape` probes under their own
  `probe-root.settings.json`.

## Measured

Since step 297 each file has its own random stream, so the files that
were already in the list keep their mutations. The one exception is
`fixture-signed.mp4`: its store is found now, so the store-aimed kinds no
longer skip it.

| run | files or pairs | runs | faults | raised | suspects | of which ISOBMFF |
|---|---|---|---|---|---|---|
| `20261005 60` | 287 files (was 249) | 15,036 | 0 | — | 238 (was 126) | 112 more |
| `20261005 60 --trust` | 140 pairs (was 120) | 7,002 | 0 | 0 | 201 | 101 |
| `20261009 200 --trust` | 140 pairs | 23,340 | 0 | 0 | 683 | 339 |

Without settings, all 238 suspects are `Valid` in both `c2patool`
versions (the plain-text one in the text-enabled build). With `--trust`,
every suspect was judged by `c2patool` 0.28.1 under the same settings, on
the state and the `timeStamp.*` codes. None is more lenient here. Four
files that `c2patool` cannot read at all are `Invalid` here.

## The finding: a negative serial number

The run without settings printed two PHP deprecation notices from
`src/Support/Bytes.php:57`: "Invalid characters passed for attempted
conversion". A flip in an ISOBMFF fixture's certificate made its serial
INTEGER negative. OpenSSL reports such a serial as `-0FDB…`, and
`Bytes::hexToDecimal()` does not handle the sign: `ltrim('0')` leaves the
`-`, and `hexdec()` skips it with the notice. The chunks are cut one
character off, so the decimal is wrong and the sign is lost.
`Certificate::$serialDecimal` is reported as `cert_serial_number`. It is
also what `OcspCheck::matching()` compares with the serial of a stapled
OCSP response, which `Der::integer()` reads with its sign. A response about
a certificate with a negative serial would therefore match nothing, and be
set aside like no revocation information. RFC 5280 §4.1.2.2 requires a
positive serial, so this needs a CA that breaks that rule. It is the next
step.

## Checked

`composer check`: 945 passed. PHPStan in Docker `php:8.3-cli`: no errors.
