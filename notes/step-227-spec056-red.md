# Step 227 — SPEC-056 approved; its tests, red

*2026-10-05. SPEC-056 approved by Maurice van Loon with the three
proposals: a C2PA GEOB under unsynchronisation is refused, the MIME type
is matched exactly, and `mp3` is detected when MPEG audio follows the tag
or opens the file (FLAC stays `unknown` until its own spec).*

## What was written

- `tests/Unit/Container/Id3ManifestStoreExtractorTest.php`: AC1–AC12 over
  the fixture and step 225's variants, with a few cases built in memory
  (versions 2 and 5, the unsigned tag with the unsynchronisation flag,
  4,097 frames).
- `tests/Unit/Verifier/Mp3Test.php`: AC11 through the verifier, AC13
  (detection, built FLAC-after-ID3 case included) and AC14.
- `tests/Fixtures/c2patool/mp3/`: `c2patool` 0.27.22's and 0.28.1's JSON
  on the signed MP3, with and without `trust/full.settings.json`
  (`Valid`, `Trusted`, in both).
- SPEC-056's Traceability: placeholder rows.

## Measured: red

```
vendor/bin/pest --group=SPEC-056   34 failed
vendor/bin/pest                    34 failed, 690 passed
```

25 on *Class "Provemark\C2paVerifier\Container\Id3ManifestStoreExtractor"
not found*; the verifier's on substance (`format` not `mp3`, no data-hash
verdict). PHPStan: 11 errors, all in the extractor test file and all
following from the missing class. Pint and `bin/spec-check.php` clean.
