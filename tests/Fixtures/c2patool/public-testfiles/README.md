# c2patool's JSON for the official test files (step 36)

`c2patool ../../public-testfiles/<name>.jpg --settings ../../trust/full.settings.json`,
c2patool 0.27.22, recorded 2026-09-21, unchanged. 24 files; `adobe-20220124-A`
and `-I` have no manifest (`Error: No claim found`, no JSON).

| state | files |
|---|---|
| `Trusted` | the thirteen Adobe files without a deliberate fault — `C`, `CA`, `CACA`, `CACAICAICICA`, `CAI`, `CAIAIIICAICIICAIICICA`, `CAICA`, `CAICAI`, `CI`, `CICA`, `CICACACA`, `CIE-sig-CA` (the invalid signature is the ingredient's, reported inside the ingredient assertion), `CII` |
| `Invalid` | `E-clm-CAICAI` (`assertion.hashedURI.mismatch`, `ingredient.manifest.missing`), `E-dat-CA` and `XCA`, `XCI` (`assertion.dataHash.mismatch`), `E-sig-CA` (`claimSignature.mismatch`), `E-uri-CA` (`assertion.hashedURI.mismatch`), `E-uri-CIE-sig-CA` (`assertion.hashedURI.mismatch` — in the ingredient manifest), `nikon-20221019-building` (`signingCredential.expired`, `.untrusted`) |
| `Valid` | the three Truepic files (`signingCredential.untrusted` — their CA is not in the test anchors) |

`manifests` per file: 1 for `C`, `CA`, `CAI`, `CI`, `CII`, the `E-*-CA`, `X*`, Nikon and Truepic files; 2 for `CACA`, `CAICA`, `CAICAI`, `CICA`, `CICACACA`, `CIE-sig-CA`, `E-clm-CAICAI`, `E-uri-CIE-sig-CA`; 4 for `CACAICAICICA`; 6 for `CAIAIIICAICIICAIICICA`.
