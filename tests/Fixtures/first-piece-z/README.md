# JPEG variants for SPEC-041

Every file here is derived by `bin/make-spec041-variants.php`, which
rewrites the packet sequence number Z of each APP11 piece and nothing
else. The Z field lies inside the data hash's exclusion, so no file needed
re-signing. Both `c2patool` versions' answers are in
`../c2patool/first-piece-z/`, measured on 2026-09-25 without settings.

| file | source | Z of the pieces | 0.27.22 | 0.28.0 | SPEC-041 |
|---|---|---|---|---|---|
| `zero-two.jpg` | `../fixture-signed.jpg` | 0, 2 | `Valid` | `Valid` | AC2: read, `Valid` |
| `zero-one.jpg` | `../fixture-signed.jpg` | 0, 1 | `Error: invalid embedded file box` | the same | AC3: refused at piece 2 |
| `seven-eight.jpg` | `../fixture-signed.jpg` | 7, 8 | `Valid` | `Valid` | AC4: refused (a known difference) |
| `one-piece-seven.jpg` | `../public-testfiles/adobe-20220124-C.jpg` (CC BY-SA 4.0, see that README) | 7 | `Valid` | `Valid` | AC4: refused (a known difference) |

The real file behind the spec, a Bing Image Creator image whose single
piece carries Z = 0, is `../writers/microsoft-20260609-bing-fast-heartbeat.jpg`.
