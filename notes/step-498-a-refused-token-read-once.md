# Step 498 — A refused time-stamp token is read once

*2026-10-28.* Finding 8 of the review of 0.9's code (`v0.8.3..HEAD`);
Maurice: "akkoord, begin met 497 en 498". No spec changes, no report
changes.

## Why

`TimestampCheck::evidence()` (SPEC-084) reads a token itself when its
caller hands it none, to fill in the token's time and certificates. Where
the caller had just tried to read it and the read was refused — a header
token, or a `c2pa.time-stamp` assertion's token, that does not parse — the
caller handed in null all the same, and the token was read a second time,
to fail a second time.

## What changed

- `evidence()` takes `TimeStampToken|false|null`: false is "the caller's
  read refused it", and it is not read again. The two places that catch
  the refusal pass false.

## Not changed

The review also proposed not reading the tokens no one tried. SPEC-084
asks for their time and certificates in the evidence, and
`TimestampEvidence` holds them as values; reading them lazily would change
that contract. How many there can be is bounded by the store's limits
(SPEC-043). Over the corpus they cost little: every token read in 2,088
runs comes to 286 reads.

## Measured

- `TimeStampToken::fromHeaderValue()` counted in two scratch copies
  (`35ed0ab` and this change), over every corpus file without settings and
  with `trust/full.settings.json`: 294 reads of which 16 refused before,
  286 of which 8 refused after. The 8 second reads are gone.
- No test can count the reads (`DerReader` is final); what the tests do
  pin is the evidence of a refused token. A hand mutation dropping the
  false branch fails 12 tests of the SPEC-084 and SPEC-088 groups.
- The corpus against step 497: 6,264 runs, with and without a recording
  source, the hash of `toJson()`, `toCrJson()` and `evidenceToJson()` plus
  the source's calls: identical.
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.
