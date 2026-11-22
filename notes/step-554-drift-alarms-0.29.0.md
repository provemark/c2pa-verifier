# Step 554 — The drift alarms compare with `c2patool` 0.29.0

*2026-11-22.* Maurice: "werk de drift-alarmen uit", then "ja akkoord".
On 2026-11-10 he decided to move the drift alarms from 0.27.22 to the next
stable `c2patool` in one step, skipping 0.28.x. 0.29.0 is that version.

## What the drift alarms are

Five criteria compare this verifier's verdict with a recorded `c2patool`
answer on every `composer check`, and a sixth reruns the first corpus
with trust settings:

| criterion | corpus | settings |
|---|---|---|
| SPEC-013 AC10 | 22 own fixtures and variants | none |
| SPEC-013 AC11 | 24 `c2pa-org/public-testfiles` | `trust/full.settings.json` |
| SPEC-013 AC12 | 17 `c2pa-rs` fixtures | `trust/full.settings.json` |
| SPEC-013 AC13 | 7 files from other writers | none |
| SPEC-013 AC17 | 23 matrix files, twice | none, and `matrix/test-roots.settings.json` |
| SPEC-014 AC10 | AC10's 22 | `trust/full.settings.json` (the oracle's `Valid` read as `Trusted`) |

That makes 116 recorded answers, all from `c2patool` 0.27.22 until this
step. The rule is never more lenient than the oracle, except where
`tests/Pest.php` names the file.

## Measured

`c2patool` 0.29.0 (`c2pa` 0.92.0, the binary of step 535) on the 116 files,
each with its criterion's settings. Compared with the recorded 0.27.22
answers on state and on the set of failure codes:

- **111 equal**, including all of AC10 and all 46 matrix answers.
- **`public-testfiles/truepic-20230212-{camera,landscape,library}`:**
  - `Valid` → `Invalid`, with `assertion.dataHash.mismatch`;
  - this is the exclusion that holds the store and other bytes, which
    `c2patool` 0.28.0 started to refuse and this verifier refuses since
    SPEC-012 amendment 7;
  - here they were `Invalid` already: `assertion.dataHash.mismatch`,
    `signingCredential.expired`, `signingCredential.untrusted`.
- **`writers/adobe-20260304-photoshop-remote-manifest`:**
  - `Valid` → `Invalid`, with `assertion.dataHash.mismatch`;
  - `c2patool` fetches the manifest from its URL, so this answer depends
    on that server;
  - nothing is fetched here, and AC13 already expects `Invalid` (REMOTE).
- **`c2pa-rs/C_with_CAWG_data`:**
  - `cawg.x509.credential.untrusted` where 0.27.22 had
    `signingCredential.untrusted`;
  - the state is unchanged.

No file is more lenient in 0.29.0, so no named exception is lifted and
none is added.

## Built

- **The answers.** `tests/Fixtures/c2patool/0.29.0/`, with the same paths
  as the 0.27.22 answers (`variants/`, `public-testfiles/`, `c2pa-rs/`,
  `writers/`, `matrix/`). A separate tree, because SPEC-017 AC1 globs
  `c2patool/public-testfiles/*.json` and `c2patool/c2pa-rs/*.json`. The
  0.27.22 answers stay, because some twenty other tests read them for
  what they recorded.
- **`DRIFT_ORACLE`** in `tests/Pest.php` names the version, and these
  read the answers through it:
  - `spec013DriftOracle()` for AC10–AC13;
  - `spec013MatrixOracle()` for AC17;
  - SPEC-014 AC10.
- **Two assertions that say why.**
  - AC11 asserts the oracle's `Invalid` and its
    `assertion.dataHash.mismatch` for the three Truepic files.
  - AC13 asserts the oracle's `Invalid` for the remote manifest.
- **SPEC-013 amendment 25** and **SPEC-014 amendment 10.**
- **Docs.**
  - `docs/comparison.md`: the header and the three sentences that said
    the alarms compare with 0.27.22.
  - The READMEs of `public-testfiles/`, `c2pa-rs/`, `writers/` and
    `c2patool/matrix/`.
  - The comment on `SPEC013_PUBLIC_TSA_NOT_CONFIGURED`.

## Seen red

- **The two new assertions**, before the switch, against the 0.27.22
  answers: AC11 failed on `truepic-20230212-camera` and AC13 on
  `adobe-20260304-photoshop-remote-manifest`. After the switch, both
  pass.
- **Each alarm reads the new tree.** In each, one 0.29.0 answer's state
  was changed by hand, the test run, and the file restored:

| alarm | answer changed | result |
|---|---|---|
| AC10 | `variants/pixel-changed` → `Valid` | 1 failed |
| AC11 | `public-testfiles/adobe-20220124-C` → `Valid` | 1 failed |
| AC12 | `c2pa-rs/C` → `Valid` | 1 failed |
| AC13 | `writers/openai-20260826-c2pa_2x` → `Trusted` | 1 failed |
| AC17 | `matrix/es256.png` → `Trusted` | 1 failed |
| SPEC-014 AC10 | `jpg` → `Invalid` | 1 failed |

## Checks

`composer check` and `composer test:parallel` exit 0.

## Left

- **Regenerating the matrix** (`bin/make-matrix-fixtures.php`) writes new
  certificates and 0.27.22-shaped answers. The 0.29.0 answers would then
  have to be recorded again, or AC17 goes red on the serial numbers.
- **The status-code moves**, one by one, each on Maurice's word.
