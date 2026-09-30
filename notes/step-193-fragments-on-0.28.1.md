# Step 193 — the fragmented stream measured again, on `c2patool` 0.28.1

*2026-09-30. `c2patool` 0.28.1 (released 2026-09-28, still on `c2pa-rs`
0.91.0), and 0.27.22 beside it; this verifier at `d3ad6a4`.*

## Why

`c2patool` 0.28.1's only functional change is #351, "Report failed
fragments rather than dropping them". Every fragmented case this verifier
compares with was recorded on 0.27.22 and 0.28.0. Before a release that
touches the BMFF hash (SPEC-051, SPEC-053), the fragments had to be
measured on the newest oracle.

## The tool

`gh release download v0.28.1 --repo contentauth/c2patool`, asset
`v0.28.1-universal-apple-darwin.zip`, SHA-256
`4766e8ecfc9c9fe031a5b483879f803fa00d061f7da550a870161e084fb0cbc3`.
`gh attestation verify` finds no attestation for it (HTTP 404), so the
check is only that it is the asset of the tagged release. It is kept
outside this repository, next to 0.27.22.

## The cases

Each case is a directory holding `init.mp4` and `seg_*.m4s`, built from
`tests/Fixtures/bmff-fragmented/` as the stored answers were. Each
version was run as
`c2patool init.mp4 --settings trust/full.settings.json fragment --fragments_glob "seg_*.m4s"`.
This verifier ran `FragmentedVerifier` with the same files, sorted by
name, under the same settings.

| case | this verifier | 0.27.22 | 0.28.1 |
|---|---|---|---|
| whole | `Trusted` | `Trusted` | `Trusted` |
| seg-changed (`seg_3` one byte) | `Invalid`, `assertion.bmffHash.mismatch` | mismatch | mismatch |
| init-changed | `Invalid`, mismatch | mismatch | mismatch |
| foreign (`seg_3` from another stream) | `Invalid`, mismatch | mismatch | mismatch |
| location-5 (`seg_1` withheld) | `Invalid`, mismatch | mismatch | mismatch |
| location-minus-1 (`seg_5` withheld) | `Invalid`, mismatch | mismatch | mismatch |
| seg_3-tail (7 bytes appended) | `Invalid`, mismatch | mismatch | mismatch |
| swapped (`seg_2` ↔ `seg_3`, step 192) | `Trusted` | `Trusted` | `Trusted` |

The state and the failure code are the same everywhere.

## What 0.28.1 changed

- **The report of a failure.** 0.27.22 printed "Error validating
  segments: ValidationStatus { … }" and "0 Init manifests validated".
  0.28.1 prints "Failed to validate: init.mp4", a `failures:` list with the
  code, explanation and URL, and "0 validated and 1 failed validation".
  The code is the same. This is #351.
- **The explanation.** 0.28.1 names the reason: "Fragment not valid", or
  "BMFF inithash mismatch" for the changed init segment.
- **For the whole set,** 0.28.1 adds two informational statuses:
  - `assertion.bmffHash.additionalExclusionsPresent`, which this verifier
    already reports (SPEC-038);
  - `signingCredential.ocsp.skipped`, which is about fetching revocation
    over the network, which this verifier never does.

  It also adds `specVersion` and `trustListUri` to the JSON.

Nothing here asks for a change.

## Stored

- `tests/Fixtures/c2patool/bmff-fragmented/<case>--0.28.1.txt` for all
  eight cases;
- `swapped.txt` for 0.27.22.

The paths in the answers are relative (`init.mp4`) or masked (`<path>`).
The step-192 paragraph in `docs/comparison.md` now names 0.28.1 too.
