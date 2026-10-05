# Step 207 — SPEC-055's tests, red

*2026-10-05. SPEC-004 amendment 2 confirmed by Maurice van Loon the same
day.*

## What was written

- `tests/Unit/Container/WavManifestStoreExtractorTest.php`: AC1–AC13 and
  the extractor half of AC15, each beside its SPEC-003 twin, over the
  fixture and the step-204 variants. The numbers in the messages (sizes,
  offsets, LBox values) were read from the files in step 205.
- `tests/Unit/Verifier/WavTest.php`: the verifier half of AC7 and AC15,
  AC14 (detection; another RIFF form and RF64 stay `unknown`), AC16 and
  AC17.
- `tests/Fixtures/c2patool/wav/`: `c2patool` 0.27.22's and 0.28.1's JSON
  on the signed WAV, without settings and with `trust/full.settings.json`.
  The two versions give the same codes.
- SPEC-055's Traceability: placeholder rows, as SPEC-054's red step had,
  so that `bin/spec-check.php` passes.

## One choice in the tests

SPEC-055 AC16 says the fixture's codes are "held by the drift alarm". The
drift alarm of SPEC-013 (AC10) counts exactly 22 recordings, and
SPEC-014 shares its list. Adding the WAV there would amend SPEC-013. AC16's
own test does the same comparison instead — state, success codes and
failure codes, sorted, against all four recordings — so the WAV is held by
`c2patool` without touching SPEC-013's list. If Maurice prefers the
WAV inside SPEC-013's list, that is a SPEC-013 amendment in the build step.

## Measured: red

```
vendor/bin/pest --group=SPEC-055
  Tests:    37 failed (42 assertions)
vendor/bin/pest
  Tests:    37 failed, 621 passed
```

The extractor tests fail with *Class
"Provemark\C2paVerifier\Container\WavManifestStoreExtractor" not found*.
The verifier tests fail on substance: `format` is `unknown`, no
`claimSignature.validated`, and the unknown-format message does not name
WAV. PHPStan reports 22 errors, all in the extractor test file and all
caused by the missing class. Pint and `bin/spec-check.php` are clean.

Along the way: a type error in the new verifier test (`json_decode`'s
result passed as `array<string, mixed>`) gave two extra PHPStan errors
that had nothing to do with the missing class. It was fixed before this
commit, so that only the intended errors remain.

## Next

Step 208: build `WavManifestStoreExtractor`, detection and the verifier
route; the amendments SPEC-055 names (SPEC-013, SPEC-024, SPEC-025); the
public text.
