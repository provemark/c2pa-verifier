# Step 230 — MP3 reviewed: what was missing, and its tests, red

*2026-10-05. Maurice asked for MP3 to be checked thoroughly before going
further. Three checks ran: a code review of `cd8a954..HEAD`, a search for
MP3s from other writers, and real tags signed by `c2patool`. SPEC-056
amendment 2 was approved the same day.*

## Measured

1. **Real tags, signed by `c2patool`** (0.27.22 and 0.28.1, in a scratch
   directory): a rich v2.4 tag (UTF-8 title, UTF-16 artist and comment,
   `TXXX`, `PRIV`, a JPEG cover in `APIC`, padding), the same as v2.3, and
   a file with no tag. `c2patool` writes every one as v2.4 with the GEOB
   last. All six signed files verify here **code for code** as both
   versions say.
2. **A 300 MB MP3**: `Valid` in 0.84 s, 34 MB peak, also under
   `memory_limit=64M`; a byte flipped at 200 MB is
   `assertion.dataHash.mismatch`.
3. **Other writers.** The public C2PA repositories hold unsigned MP3s
   only (`c2pa-rs`, `c2pa-python`, `c2pa-ts`); every one is "no manifest"
   here and in both versions, the hostile
   `id3v23_compression_underflow.mp3` included. `c2pa-ts` 0.14.0, an
   implementation independent of `c2pa-rs`, can sign: its MP3
   (`tests/Fixtures/mp3-writers/c2pa-ts-signed.mp3`) uses the MIME type
   `application/x-c2pa-manifest-store` and an exclusion over the whole
   tag. `c2patool` 0.27.22: `Valid`; 0.28.1: `Invalid`; **here: no
   manifest**.
4. **The MIME types `c2patool` accepts**, on the fixture re-written:
   exactly `application/c2pa` and `application/x-c2pa-manifest-store`;
   not `application/jumbf`, not upper case, not with parameters.
5. **The review's findings**, reproduced: a signed MP3 with 16 zero
   bytes, or an empty second tag, after its tag is `Valid` in `c2patool`
   and `unknown` here; a v2.4 frame size written as a plain integer
   (iTunes) silently hides the store here; a GEOB frame header at the very
   end of the file raised a `ValueError`; a UTF-16 text file was detected
   as `mp3`. And, from the new variants: an invalid frame id is read past
   by `c2patool`; unsynchronisation with `FF 00` is *No claim found*; a
   grouped GEOB is *No claim found*; a long description is read.

No input gave a wrong `Valid`.

## Decided

SPEC-056 amendment 2 (Maurice van Loon): AC15–AC21 — the legacy MIME
type; detection past zero padding and further tags; two frame headers for
untagged MPEG audio; iTunes frame sizes read, invalid frame ids a fault;
unsynchronisation with `FF 00` a fault; text fields of any length, grouped
frames not C2PA, footers only in v2.4; and no error but
`ContainerException`. One shared tag-header parser.

## Red

New fixtures: ten variants in `bin/make-mp3-variants.php`, two files
signed by `c2patool` from its unsigned sources, `mp3-writers/` with the
c2pa-ts file, the hostile c2pa-rs file, the licences and the signing
script; `c2patool`'s JSON for the three signed ones.

```
vendor/bin/pest --group=SPEC-056   13 failed, 34 passed
vendor/bin/pest                    13 failed, 724 passed
```

They fail on the narrow MIME match, the detection (`unknown` where `mp3`
is expected, and `mp3` for the UTF-16 text), the frame-size reading, the
missing `FF 00` check, the 4,096-byte text limit and grouping, and the
`ValueError` (two cases). PHPStan, Pint and `bin/spec-check.php` clean.
