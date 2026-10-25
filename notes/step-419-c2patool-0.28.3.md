# Step 419 — The corpus against `c2patool` 0.28.3

*2026-10-25.* `c2patool` 0.28.3 was released on 2026-10-24. Its only
change is `c2pa` 0.91.2 → 0.91.6. The verdict here follows `c2patool`, so
Maurice approved measuring whether any verdict moved ("akkoord, meet
tegen 0.28.3"), then documenting it ("akkoord, documenteer en push").

## What changed in between (read from the release notes)

| `c2pa` | change |
|---|---|
| 0.91.3 | an Ed25519 certificate must carry no algorithm parameters |
| 0.91.4 | a time-stamp with a SHA-1 message imprint is ignored |
| 0.91.5 | a valid MOV that the BMFF type check refused is read |
| 0.91.6 | a new ECDSA stack (`c2pa_raw_crypto` 0.2.0) |

## What was run

- The macOS release zip of `contentauth/c2patool` v0.28.3; its SHA-256
  (`12db3a9b…2787`) equals the digest GitHub publishes for the asset.
- 0.28.2 and 0.28.3 on every media file under `tests/Fixtures/` (770),
  each without settings and with `trust/full.settings.json`: 3,080 runs.
- Per run: the exit code, `validation_state`, and every status code with
  its url, per manifest, sorted. The two versions compared file by file.
- A first attempt wrote all parallel runs to one stream and interleaved;
  it was discarded and rerun with one output file per run (770 lines per
  combination).

## Measured

One file moved, the same way with and without settings:

| file | 0.28.2 | 0.28.3 | here |
|---|---|---|---|
| `jpeg/rst-before-sos.jpg` | `Error: No claim found` | `Invalid`: `assertion.dataHash.mismatch`; signature validated, credential trusted with settings | `general.error`, "unexpected marker FF D0 at offset 20 before SOS" |

The other 769 files give the same state and the same codes in both
versions. Totals for 0.28.3 with settings: 74 `Trusted`, 237 `Valid`,
269 `Invalid`, 190 errors.

## What it means (reasoned)

- The fixture (step 3, SPEC-001 amendment 1, AC15) puts a bare RST0 before
  the first APP11. 0.28.2 read the two bytes after it as a length and
  skipped the store. 0.28.3 reads RST0 as a marker without a length,
  finds the store, and sees that the two inserted bytes break the data
  hash. That is the right answer for a file changed after signing.
- This verifier refuses the container and names the marker: RST markers
  belong inside entropy-coded data (ITU-T T.81), not before SOS. Stricter,
  and neither side says `Valid`. Kept.
- Which change in `c2pa` causes it was not traced; none of the four
  release-note items names JPEG markers.
- The corpus has no file for three of the four changes (an Ed25519
  certificate with parameters, a SHA-1 imprint, a crossed ECDSA curve).
  That they did not move shows nothing about them. Every ES256/384/512
  file in the corpus gives the same answer under the new ECDSA stack.

## What changed

- `docs/comparison.md`: a paragraph for 0.28.3.
- `tests/Fixtures/jpeg/README.md`: 0.28.3's answer beside the fixture.
- The pinned oracle of the drift alarms stays 0.27.22. No code, no tests.

## Next

Probes for the three changes the corpus does not cover, each a small
signed variant made in scratch with a test key: a SHA-1 imprint, an
Ed25519 certificate with parameters, ES256 under a P-384 key (the open
question of step 416).
