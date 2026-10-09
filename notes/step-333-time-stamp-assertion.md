# Step 333 — The time-stamp assertion is read (SPEC-064)

*2026-10-09.*

SPEC-064, with the probes of step 332, is built. A later manifest's
`c2pa.time-stamp` assertion now gives an earlier manifest a trusted time
when that manifest's own header token is absent or did not pass (C2PA 2.4
§15.8.1.2). A malformed one, or a second one in a manifest, is
`assertion.timestamp.malformed` (§15.10.3.2.6, §18.18.3).

## What changed

- **`Timestamp\TimestampAssertions`** (new) collects every manifest's
  time-stamp assertions into tokens per label and faults per holder. It
  takes no token from a manifest whose time-stamp assertions are
  malformed.
- **`TimestampCheck::forManifest()`** follows §15.8.1's order. The header's
  token stands when it passed, validated and trusted. Otherwise each
  offered token is judged by the existing `judge()`, and the first that
  passes gives the time, reported with the assertion's url.
- **`Verifier`** (the active manifest) and **`IngredientManifestCheck`**
  (every ingredient manifest it validates) call it in place of `check()`.
- **`StatusCode`**: `assertion.timestamp.malformed`, a failure; the API
  surface and its two counters grow by one.

## Three forms of the token (amendment 1)

The first measurement showed `timeStamp.mismatch` on a real file of the
corpus, `c2pa-rs/update_manifest.jpg`. Its update manifest stamps its
version 1 parent. Its token's imprint matched neither the COSE signature
field (what `c2pa-rs` 0.91.1 writes) nor the CounterSignature structure
(§18.18.3). It matched the SHA-256 of the parent's whole COSE_Sign1,
which is what earlier `c2pa-rs` stamped (`Claim::signature_val()`). All
three forms bind the same signature, so all three are accepted, in that
order. The real file's token now validates. Its TSA is no `tsa` anchor in
the corpus settings, so its time is not used and no verdict changes.

## Measured

- **Tests first.** `tests/Unit/Timestamp/TimestampAssertionTest.php`: 9
  failed, then 13 passed. The test on the real file was red with
  `timeStamp.mismatch` before the whole-COSE form.
- **The corpus.** 2,623 runs moved. Verdicts changed only for the probes:
  `raw`, `structure`, `update-raw` and `untrusted` (the same bytes as
  `raw`) became `Trusted` under the settings that hold their TSA.
  `update_manifest.jpg` and the update-manifest fixtures derived from it
  gained `timeStamp.*` lines, with the same verdicts.
- **The fuzzer.** 0 faults. With `--trust`, 534 and 1,765 suspects, each
  judged by `c2patool` 0.28.1, none more lenient here.
- `composer check`: 986 passed.

`docs/reading-c2pa-2.4.md`: five candidates covered (§15.8.1.2 ×2,
§15.10.3.2.6, §18.18.3 ×2); 16 remain. Issue #6 can be closed with the
release.
