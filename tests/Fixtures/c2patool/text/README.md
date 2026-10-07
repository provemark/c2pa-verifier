# c2patool's JSON on the signed texts (step 267)

Four recordings, made with `c2patool` 0.28.1 built with the experimental
Cargo feature `unstable_plain_text` (c2pa-rs 0.91.1); a stock `c2patool`
does not read text (step 265):

| file | of | settings | state |
|---|---|---|---|
| `fixture-signed.json` | `../../fixture-signed.txt` | none | `Valid`, `signingCredential.untrusted` |
| `fixture-signed.trusted.json` | `../../fixture-signed.txt` | `--settings ../../trust/full.settings.json` | `Trusted` |
| `nfd-emoji-signed.json` | `../../text/nfd-emoji-signed.txt` | none | `Valid`, `signingCredential.untrusted` |
| `nfd-emoji-signed.trusted.json` | `../../text/nfd-emoji-signed.txt` | `--settings ../../trust/full.settings.json` | `Trusted` |

Recorded 2026-10-07 with `c2patool <file> [--settings …] > <name>.json`.
The answers to the variants under `../../text/` are in that directory's
README.
