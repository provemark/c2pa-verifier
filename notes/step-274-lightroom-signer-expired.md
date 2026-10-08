    # Step 274 — Adobe's Lightroom signer expired: two tests that turned red overnight

*2026-10-08. Found while building step 273. No code changed.*

## What happened

`composer check` failed in two tests on an unchanged `HEAD`:
SPEC-013 AC13 (the writers corpus) and SPEC-020 AC3 (ingredient deltas).
Both verify `writers/adobe-20260425-lightroom-classic-church.jpg`, which
was `Valid` on 2026-10-07 (step 272).

Adobe's signing certificate in that file, `CN=Adobe C2PA`, is valid from
2025-10-07T16:39:07Z to 2026-10-07T16:39:07Z. The file carries a
timestamp from a DigiCert TSA. Without an anchor for that TSA this
verifier does not trust the timestamp (ADR-0004), judges the signer at
now, and reports `signingCredential.expired`: `Invalid`. Both `c2patool`
versions say `Valid`; they accept the timestamp without a configured
anchor.

Measured on 2026-10-08 at 16:47 UTC:

- `bin/c2pa-verify` without settings: `Invalid`, `signingCredential.expired`
  "checked at now (the timestamp's TSA is not trusted)" and
  `signingCredential.untrusted`.
- With `trust/full-plus-digicert-g4.settings.json` or
  `trust/digicert-trusted-root-g4.settings.json`: `Valid`, only
  `signingCredential.untrusted`.
- `c2patool` 0.28.1 without settings: `Valid`.

## What changed

- `SPEC013_WRITERS_TSA_NOT_CONFIGURED` in `tests/Pest.php` names the
  Lightroom file, next to Amazon's and Google's. AC13 expects `Invalid`
  with `signingCredential.expired` for it, the known divergence.
- SPEC-020 amendment 4, confirmed: AC3 runs with
  `full-plus-digicert-g4.settings.json`. The fifteen files of
  `SPEC020_SINGLE` give the same deltas and the same `validation_state`
  as `c2patool` under it.

`composer check`: 6 failed (step 273's, red by design), 912 passed.

## The next one

A scan of every signed fixture for an active signer that expires within
a year finds one: `writers/openai-20260826-c2pa_2x.png`, signer valid
until 2027-04-23. Its OpenAI TSA is `timeStamp.untrusted` without
settings, so the same two kinds of test will turn on that day unless the
file is named first.

## For users (reasoned, not measured)

Every Lightroom export signed under this Adobe certificate is `Invalid`
in this verifier without a TSA anchor since 2026-10-07, and `Valid` with
the DigiCert root that `docs/trust-settings.md` recommends as a `"tsa"`
anchor.
