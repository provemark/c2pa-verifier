# Step 215 — The three amendments approved; their tests, red

*2026-10-05. SPEC-003 amendment 3, SPEC-055 amendment 3 and SPEC-013
amendment 16 approved by Maurice van Loon.*

## One more measurement first

The WebP side of "the rest" had not been measured. Built in a scratch
directory from the fixtures:

| file | `c2patool` 0.27.22 | 0.28.1 |
|---|---|---|
| unsigned WebP, a 3-byte chunk with pad `FF` before `VP8L` | *No claim found* | *No claim found* |
| unsigned WebP, a 3-byte chunk without pad at the end | *No claim found* | *No claim found* |
| signed WebP, 128 bytes appended | `Invalid`, `assertion.dataHash.mismatch` | the same |

WebP behaves as WAV did in steps 213 and 214.

## The tests

The new cases are built in memory from the fixtures inside the tests, so
no new fixture file was needed.

- **SPEC-003** (`WebpManifestStoreExtractorTest`): AC5's "size without
  `C2PA`" case now expects `null`; AC16 now uses `riff-size-plus-one`
  (a size larger than the file stays refused before any chunk is read, so
  this test was green at once: the behaviour is unchanged, only its
  example moved); AC12 gains the pad byte after another chunk; AC17 bytes
  after the RIFF chunk; AC18 `ContainerException::$storeReached`.
- **SPEC-055**: AC4's error dataset keeps the three size-too-large cases;
  new tests for the size that ends early (`null`) and for
  `trailing-bytes` and `second-riff` (the store of AC1, and verified
  `Invalid` with `assertion.dataHash.mismatch`); AC19 for the three
  unsigned quirks and the signed file with an ID3v1 tag.
- **SPEC-013** (`VerifierTest`, under AC7): four RIFF faults before any
  `C2PA` chunk are `hasManifest` false with one `general.error`; two
  faults at the `C2PA` chunk stay `hasManifest` true.

Traceability rows for SPEC-003 AC17–AC18 and SPEC-055 AC19 name the
sources that will carry them.

## Measured: red

```
vendor/bin/pest --group=SPEC-003   7 failed, 20 passed
vendor/bin/pest --group=SPEC-055   9 failed, 41 passed
vendor/bin/pest --group=SPEC-013   1 failed, 20 passed
vendor/bin/pest                    17 failed, 661 passed
```

They fail on the old size check (*RIFF size … in the header*), the old
pad check (*pad byte at offset … is FF*), `has_manifest` still `true`,
and `storeReached` not existing. PHPStan: two errors, both from the
missing property. Pint and `bin/spec-check.php` clean. On the way, one
`match` in a new test lacked a default (a PHPStan error unrelated to the
change); fixed before this commit.

## Next

Step 216: build it — the walk, `ContainerException::$storeReached`, the
report — then the corpus, which may only move the files listed in step
214.
