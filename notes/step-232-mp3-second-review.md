# Step 232 — A second MP3 review; amendment 3; its tests, red

*2026-10-05. Steps 230–231 rewrote most of the MP3 reader, so a second
code review ran over `07a8379..HEAD` before MP3 was called done. Steps
230–231 were pushed meanwhile (CI run 37294961059 green), as Maurice
asked.*

## Findings, checked

| # | finding | checked | outcome |
|---|---|---|---|
| 1 | text fields re-scanned whole after every 64 KiB: quadratic | measured: 4 MB 3.6 s, 8 MB 14.8 s | fix (linear), AC20 timed |
| 2 | the grouping flag on a GEOB that still reads as C2PA is accepted | measured: store read here, *No claim found* in both versions; a signer setting the flag before signing gets `Valid` here | **amendment 3, AC22: a fault** |
| 3 | a tag running past the end of the file is `unknown` | measured: both versions read the manifest (`Invalid`) | amendment 3, AC23: `mp3` |
| 4 | the scan of AC5 ignores an extended header | read; red in AC23 | fix |
| 5 | the `FF 00` check reads with raw `fread`, and a short read means "none" | read | fix: `Read::upTo`, a short read is a fault |
| 6 | free-format MPEG without a tag is no longer `mp3` | reasoned | amendment 3, AC25: a named limit |
| 7 | an ID3v2.2 tag before MPEG audio is `unknown` | measured: both versions find no claim | amendment 3, AC24: `mp3`, refused by name |
| 8 | `FormatDetector`'s docblock still says "twelve bytes" | read | fix |
| 9 | SPEC-056's Traceability names `tagEnd()` and `containsUnsynchronisedBytes()` | read | fixed in this step |
| 10 | a 64 KiB padding probe after every tag; duplicated header checks | read | fix: a small probe first |

The reviewer's four non-findings were checked too: an iTunes size of 256
(`00 00 01 00`) is misread as 128 by both `c2patool` versions and here
alike; a frame after the GEOB running past the tag is *No claim found* in
both; the unsynchronisation flag on the signed fixture is `Invalid` on
both sides.

**Finding 2 was my error in step 231**: the reasoning that a grouped
GEOB never matches the MIME type holds only when the group byte is there.

## Red

Five variants added to `bin/make-mp3-variants.php` and measured with both
versions (`tests/Fixtures/mp3/README.md`). Tests:

```
vendor/bin/pest --group=SPEC-056   7 failed, 50 passed
vendor/bin/pest                    7 failed, 740 passed   (with Pest's result cache cleared)
```

AC25 was green on arrival: it records an existing limit. PHPStan, Pint and
`bin/spec-check.php` clean.

**Two things found while checking this commit.** This note first gave
the full-suite count as 738 without that line having been read; it is
740. And the full suite then stopped with *Allowed memory size of
134217728 bytes exhausted* in SPEC-024's `ResourceBoundsTest`: Pest runs
the tests that failed last time first, so the new 8 MB text-field test,
still quadratic, ran before the 20 MB store test, and together they passed
the 128 MB the suite runs in. With the cache cleared the suite completes.
The same commit at step 231 showed the same with the cache from this
step. The suite's headroom under 128 MB is small; that is noted for a
later step, not changed here.
