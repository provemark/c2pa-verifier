# Step 204 — The signed WAV fixture, and twenty-one ways to break it

*2026-10-05. `c2patool` 0.27.22 and 0.28.1, the c2pa-rs ES256 test
certificates. No verifier code and no spec yet: the WAV spec is written
from this note, as SPEC-003 was written from step 06.*

## Why

Step 203 showed that a signed WAV carries its store in a RIFF chunk
`C2PA` and binds it with `c2pa.hash.data`, as WebP does. A WAV reader can
therefore follow the WebP reader (SPEC-003). It may only do that where the
two formats behave the same. This step measures the cases a reader must
accept or refuse, against both `c2patool` versions, and reads what the
specification says.

## The fixture

`tests/Fixtures/fixture-signed.wav` (29,550 bytes, SHA-256
`75dac0a87218c30ad5fe6f59305171b3424286c8e189ada5b0ce6b619f4fe747`) was
made from `tests/Fixtures/fixture-unsigned.wav` (16,078 bytes, SHA-256
`a5a5deccfcdaa602d674e8ea2ba799c48257144e2e45a1160a427527972141f0`; the
sister library's `fixture.wav`, unchanged) with:

```
c2patool fixture-unsigned.wav -m fixture-signed-wav.manifest.json -o fixture-signed.wav -f
```

It was signed with `c2patool` 0.27.22, like every other `fixture-signed.*`.
The manifest definition is the WebP one with only the title changed
(`tests/Fixtures/fixture-signed-wav.manifest.json`; key and certificate
paths are placeholders, and the private key is not in this repository).

| command | 0.27.22 | 0.28.1 |
|---|---|---|
| `c2patool fixture-signed.wav` | `Valid` (`claimSignature.validated`, `assertion.dataHash.match`) | `Valid`, the same codes |
| … `--settings c2pa-trust.settings.json` | `Trusted` | `Trusted` |
| `c2patool fixture-unsigned.wav` | `Error: No claim found` | — |

Its chunks, walked with a throw-away probe:

| offset | chunk | length |
|---|---|---|
| 0 | `RIFF` header, size 29,542, form `WAVE` | — |
| 12 | `fmt ` | 16 |
| 36 | `LIST` | 26 |
| 70 | `data` | 16,000 |
| 16,078 | `C2PA` | 13,463 (odd, so a pad byte follows at 29,549) |

## What the specification says (read)

C2PA 2.4 §A.3.7, "Embedding manifests into RIFF-based assets", covers WAV,
BWF, AVI and WebP together:

> The C2PA Manifest Store shall be embedded into a RIFF-compatible file
> (i.e., WAV, AVI or WebP) as the data of a chunk with an identifier of
> C2PA. For compatibility reasons, this C2PA chunk shall appear as the last
> sub-chunk of the first RIFF header chunk.

So the chunk is at the top level ("sub-chunk of the first RIFF header
chunk"), and it is the last one. §18.7.3.5 describes the RIFF tree for the
general box hash (`LIST` chunks can nest), which this verifier does not
use for RIFF (step 203: `c2pa.hash.data` only). (Step 06 cited this text
as §A.3; in 2.4 it is §A.3.7.)

## The variants, measured

`bin/make-wav-variants.php` builds 21 variants. The first eighteen are
SPEC-003's WebP cases, one by one, and the last three are WAV's own.
Every answer of both versions is in `tests/Fixtures/wav/README.md`. In
short:

1. **Refused by both versions**, with an error and no verdict: truncation
   (two variants), a chunk that overruns the file, `C2PA` too short or
   empty, a RIFF size that leaves `C2PA` out, `C2PA` nested in the `LIST`
   chunk (*No claim found*: the chunk is looked for only at the top level),
   and **RF64** (*expected "RIFF", got "RF64"*).
2. **Read, then `Invalid` with `assertion.dataHash.mismatch` in both
   versions**: another form type, two `C2PA` chunks, `C2PA` not last
   (three variants), a wrong RIFF size, a missing or non-zero pad byte, an
   odd chunk before `C2PA`, bytes after the RIFF chunk, and a second RIFF
   chunk. In every case the signature is validated first. The data hash
   is what catches the change.
3. **`Valid` where it should not be**:
   - `lbox-differs.wav` (the box's own length differs from the chunk
     length) is `Valid` in both versions. The WebP twin is too, and
     SPEC-003 AC9 refuses it.
   - `length-differs.wav` (chunk length +1, so the pad byte becomes data)
     is `Valid` in 0.27.22 and **`Invalid` in 0.28.1**, which reports
     `assertion.dataHash.match` *and* a `mismatch`: *data hash exclusion
     does not match the manifest location in the asset*.

## What it means (reasoned)

- **WAV behaves like WebP in all eighteen shared cases**, in both
  versions. A WAV reader can follow SPEC-003 criterion by criterion. This
  makes a shared RIFF reader for WebP and WAV (and later AVI) a natural
  refactor. It is its own step, with no change in behaviour.
- **0.28.1 has learned the check SPEC-012 already makes**: the store's
  exclusion must equal the store's place in the file. The same variant on
  WebP (`tests/Fixtures/webp/length-differs.webp`) is also `Valid` in
  0.27.22 and `Invalid` in 0.28.1, measured today. The WebP README and
  `docs/comparison.md` still call this case "stricter than the oracle".
  That is now true only against 0.27.22. Correcting that text is a
  separate small step; nothing is built for it.
- **Where the chunk sits is the open question for the spec.** §A.3.7 says
  *shall be last*. Neither `c2patool` version checks that when it reads.
  Every moved chunk we can build is caught by the data hash, because
  moving it changes the hashed bytes. What we cannot build is a file whose
  signer put `C2PA` somewhere else and excluded it correctly. Such a file
  would need a key, and this project signs nothing. SPEC-003 AC8 chose to
  extract and let the hash judge. The WAV spec must either follow that or
  refuse a `C2PA` that is not last, as the specification says. That is a
  choice for Maurice when the spec is drafted.
- **RF64 is refused** by both versions. A WAV reader refuses it too, by
  name. That covers WAV files over 4 GB.
- **The pad byte is hashed** (step 203, confirmed here). A non-zero pad byte
  gives `mismatch` in both versions. SPEC-003 AC12 refuses it already in
  the reader.
- **0.28.1 sometimes reports two `mismatch` entries** for one moved chunk
  (location and hash). This verifier reports what SPEC-012 finds. Whether
  that matches 0.28.1's list entry for entry belongs to the WAV spec's
  corpus comparison, not to the reader.

## What this verifier says today

All 21 variants and the fixture are `Invalid` with `general.error`
(*unsupported file type*): the format is not recognised. `composer check`
is green with the new files in place (621 tests): nothing reads them yet.

## Next

The WAV spec as `draft`, with the open question on the chunk's position
spelled out for Maurice.
