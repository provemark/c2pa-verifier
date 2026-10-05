# Step 210 — WAV files from other writers

*2026-10-05. `c2patool` 0.27.22 and 0.28.1.*

## Why

Every WAV this verifier was tested with in steps 204–208 was signed by
`c2patool` or derived from such a file: one writer. For JPEG and PNG the
corpus of other writers is where the surprises were (Bing's Z = 0, the
Pixel's chains). Before WAV is released, the question is whether any WAV
from another writer exists to measure against.

## Where was looked (measured)

The full file trees of nine public repositories, through the GitHub API,
for `.wav` and `.bwf`. None of the trees was truncated.

| repository | WAV files |
|---|---|
| `contentauth/c2pa-rs` | 3 in `sdk/tests/fixtures/` |
| `contentauth/c2pa-python` | 1 in `tests/fixtures/files-for-reading-tests/` |
| `c2pa-org/public-testfiles` (272 entries; audio is m4a only) | 0 |
| `c2pa-org/conformance-public` | 0 |
| `encypherai/c2pa-conformance-suite` | 0 |
| `richardwooding/c2pa`, `TrustNXT/c2pa-ts`, `contentauth/c2pa-js`, `contentauth/c2pa-node` | 0 |

The four files were copied at pinned commits (`c2pa-rs` `e4f63a2`,
`c2pa-python` `192023c`) into `tests/Fixtures/wav-writers/`, with their
licences.

## Measured

| file | `c2patool` 0.27.22 / 0.28.1 | this verifier (step 208) |
|---|---|---|
| `c2pa-python-sample1_signed.wav` | `Valid`; `Trusted` with the test roots | `Valid` and `Trusted`; state, success codes and failure codes **equal** in all four comparisons |
| `c2pa-rs-sample1.wav` (unsigned) | *No claim found* | no manifest |
| `c2pa-rs-sample3.invalid.wav` (RIFF size 1,000,000 too large) | *Invalid RIFF format* | `Invalid`, `general.error`: *RIFF size 1441174 in the header, 441172 bytes in the file after it* |
| `c2pa-rs-riff_bomb_1000.wav` (1,000 nested `LIST` chunks) | *No claim found* | no manifest, 0.05 s, 33 MB peak |

The signed file has a layout the own fixture does not: a `LIST` and an
`id3 ` chunk (lower-case, with a trailing space) between `data` and
`C2PA`, and no `JUNK`. The walk skips both.

## What it means (reasoned)

- **Every verdict is equal.** Nothing new for SPEC-055.
- **"Another writer" is only partly true.** The signed file says
  `c2pa-c test 0.2`: the C binding of c2pa-rs. That is another route
  into the same Rust core, not an independent implementation. No WAV
  from an independent writer was found in public. That stays an open
  gap, and the README and comparison should not claim more.
- **The bomb is harmless here by construction**: SPEC-055 reads only the
  top level (AC15). It is worth keeping as a guard, so that a later change
  that descends into `LIST` meets it.

## Not done

- No test holds these files yet. SPEC-055 has no criterion for them; that
  would be an amendment (a proposed AC18). It is not made in this step.
- The Go verifier (`richardwooding/c2pa`) as a second oracle on WAV: not
  run.

## Next

Step 211: a large WAV, memory and time.
