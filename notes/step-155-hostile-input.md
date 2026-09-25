# Step 155 — Hostile input ends in a report or a refusal (SPEC-043)

*2026-09-25. The last six crash and denial-of-service findings of the
security review of the same day, none of which needs a key. Present in
0.1.0 to 0.2.1. At the maintainer's request they share one spec; each has
its own criterion, measurement and test seen red.*

The rule they share: whatever the input, the verifier ends with a report
(exit 0 or 1) or a refusal naming its reason (exit 2). It stays within
bounded memory and time, and prints nothing else.

## 155a — measured before any change

| AC | finding | before |
|---|---|---|
| 1 | CBOR items bounded per container, not in total | 1 MB file: 152 MiB; 4 MB: 514 MiB, a fatal error under PHP's default 128 MB, exit 255 |
| 2 | ISOBMFF `merkle` box read whole; purpose string read to a NUL without a bound | 200 MB `merkle` box: fatal under 128 MB (233 MiB under 1 GB); 20 MB purpose: 3.9 s |
| 3 | a thumbnail media type (`bfdb`) not UTF-8 | `JsonException` in `toJson()`, exit 255; both `c2patool` versions: `"format": ""` |
| 4 | `openssl_x509_parse()` and others not silenced | a NUL in a UTCTime: a warning on standard output ahead of the JSON; both `c2patool` versions refuse the certificate |
| 5 | a stream that cannot seek | a FIFO or piped `/dev/stdin`: uncaught `InvalidArgumentException`, exit 255 |
| 6 | the command's path passed to `fopen()` as given | a `data:` URL of a signed JPEG: `Valid`; `php://memory` opened; a real file named `data:,hello` read as the text "hello"; `http://` would fetch (reasoned, not run) |

**What real files need.** The CBOR item total was logged in a scratch
worktree over every fixture and the 78 current-writer files: 282,702
decodes. The largest total in one file is 5,285 items, in a synthetic
fixture.

**The OpenSSL warning.** It was reduced from the review's fuzzer output to
a single byte of `matrix/es256.jpg`. Both files are in
`tests/Fixtures/hostile/`, with the answers of both `c2patool` versions.

`tests/Unit/Verifier/HostileInputTest.php`: **6 of 6 red**, each on its
finding. Three tests stop at their first assertion, so their later parts
were run apart; they were red too:

- AC1 across a store: no limit named;
- AC2's `merkle` box: 200 MiB read;
- AC6's real file named `data:,hello`: the text "hello" read.

AC6 first used an absolute path, which PHP always opened as a file, so
that part was green before any change. It was rewritten to a relative
path, which is where the fault is, and then it was red.

## 155b — built

- **AC1.** `CborBudget` counts every item decoded; the default is 65,536.
  `ManifestStore::fromTree()` gives one budget to every claim and
  assertion of the store, because those stay in memory. COSE and the BMFF
  proof get a fresh budget per decode.

  SPEC-006 AC6's test for an indefinite array of `maxItems + 1` now meets
  the total first, one item earlier. It was given a wider total so that it
  still proves the per-container bound it is about. The rule did not
  change.
- **AC2.** `MAX_PURPOSE_LENGTH = 64` in both purpose loops. `merklePayload()`
  applies the store's box limit and memory budget before it reads.
- **AC3.** A media type that is not UTF-8 reads as `""`.
- **AC4.** `Certificate` reads, parses and verifies with warnings caught.
  A warning while parsing or reading the key makes the certificate
  unreadable, `signingCredential.invalid`, with the warning as the reason.
  `RsaPss` and `CoseSign1` were already silent.
- **AC5.** `Command::open()` refuses a stream that cannot seek, with exit
  2.
- **AC6.** `Command::local()` makes the path absolute against the working
  directory, without resolving symlinks, and opens it with `file://` in
  front, for the input and for `--settings`. A path that does not exist
  as a local file is *No such file*.

Measured:

- AC1 to AC6 green; `composer check` exit 0, 525 passed.
- Every review probe under a 128 MB limit: a report (exit 1, valid JSON,
  31 to 46 MiB) or a refusal (exit 2, a reason). The two CBOR bombs went
  from 152 and 514 MiB to 40 and 46.
- 19,788 runs over every signed fixture and settings file, compared by
  key: the only change is `hostile/certificate-time-nul.jpg`, still
  `Invalid`, now with `signingCredential.invalid`. The comparison had to
  be by key: before the change, that file's OpenSSL warnings put 203 lines
  into the sweep's own standard output, which is finding 4 itself.
- `php bin/fuzz.php 20260925 60`: 0 faults, the same 34 suspects as in
  steps 153 and 154.

## 155c — found by CI on Linux

The first version of `local()` used `realpath()`. On macOS that resolves
`/dev/stdin` to `/dev/fd/0`, and the tests were green. On Linux it leads
through `/proc/self/fd/0` to `pipe:[…]`, which `realpath()` cannot
resolve. CI on PHP 8.3, 8.4 and 8.5 failed AC5 on its message: exit 2 and
empty output as required, but *No such file* where the reason is that
the input cannot seek.

The refusal held, but the reason was wrong, and the release was not
tagged on red. A second attempt checked the absolute path with
`file_exists()` and failed on CI the same way: PHP's stat resolves the
link as `realpath()` does, and `open()` does not. `local()` now only puts
`file://` before the absolute path and leaves existence to `fopen()`,
whose own reason is *No such file or directory*. A path longer than
`PHP_MAXPATHLEN` cannot name a file and is *No such file* at once; `fopen()`
would say *Invalid argument* for a `data:` URL of an image. `/dev/stdin`
and a FIFO now reach the seekable check. Measured on
macOS: SPEC-043 6 passed and SPEC-019 12 passed. A FIFO gives *cannot
seek*; `data:`, `php://`, `http://`, `phar://`, a missing file and a
`--settings data:` each give *No such file*, with no request made. Linux
is measured by CI on the fix.

## Disclosure

Local until the security release, like steps 148 to 154.
