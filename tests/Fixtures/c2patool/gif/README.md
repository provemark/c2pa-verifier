# c2patool's JSON on the signed GIF (step 256)

Four recordings of `../../fixture-signed.gif`:

| file | tool | settings | state |
|---|---|---|---|
| `fixture-signed.json` | `c2patool` 0.27.22 | none | `Valid`, `signingCredential.untrusted` |
| `fixture-signed.trusted.json` | `c2patool` 0.27.22 | `--settings ../../trust/full.settings.json` | `Trusted` |
| `fixture-signed.0.28.1.json` | `c2patool` 0.28.1 | none | `Valid`, `signingCredential.untrusted` |
| `fixture-signed.0.28.1.trusted.json` | `c2patool` 0.28.1 | `--settings ../../trust/full.settings.json` | `Trusted` |

Recorded 2026-10-06 with `c2patool <file> [--settings …] > <name>.json`.
The answers to the variants under `../../gif/` are in that directory's
README.
