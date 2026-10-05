# c2patool's JSON on the signed WAV (step 207)

Four recordings of `../../fixture-signed.wav`, held by SPEC-055 AC16:

| file | tool | settings | state |
|---|---|---|---|
| `fixture-signed.json` | `c2patool` 0.27.22 | none | `Valid`, `signingCredential.untrusted` |
| `fixture-signed.trusted.json` | `c2patool` 0.27.22 | `--settings ../../trust/full.settings.json` | `Trusted` |
| `fixture-signed.0.28.1.json` | `c2patool` 0.28.1 | none | `Valid`, `signingCredential.untrusted` |
| `fixture-signed.0.28.1.trusted.json` | `c2patool` 0.28.1 | `--settings ../../trust/full.settings.json` | `Trusted` |

Recorded 2026-10-05 with `c2patool <file> [--settings …] > <name>.json`.
The two versions give the same codes; only the order differs from this
verifier's, so AC16 compares sorted lists.
