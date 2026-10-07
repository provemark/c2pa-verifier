# Plain-text variants (step 265)

`../fixture-unsigned.txt` is two short lines written here, with accented
letters so that normalisation matters: `Café crème, naïve résumé.` and
`A second line of plain text.` (60 bytes, NFC). `../fixture-signed.txt` is
that text signed with `c2patool` 0.28.1 built with the experimental Cargo
feature `unstable_plain_text` (c2pa-rs 0.91.1, `plain_text_io.rs`), the
c2pa-rs ES256 test certificates and `../fixture-signed-txt.manifest.json`.

The signed file is the unsigned text, unchanged, followed by one
`C2PATextManifestWrapper` (C2PA 2.4 §A.8): `U+FEFF` at byte 60, then 4,030
variation selectors, one per byte: `C2PATXT\0`, version `01`, the length
`00 00 0D C6` (3,526), the store (one `jumb` box of 3,526 bytes) and 491
bytes of padding (489 × `00`, 2 × `10`). The wrapper is 14,165 UTF-8 bytes,
exactly c2pa-rs's deterministic target `3 + (13 + M) × 4 + 6`. The data hash
has one exclusion, the whole wrapper from the marker to the end:
`{start: 60, length: 14165}`.

`nfd-emoji-unsigned.txt` and `nfd-emoji-signed.txt` are a second pair, signed
the same way: the input is in NFD, with an emoji followed by `U+FE0F` and a
lone `U+FEFF` in the middle of a line. The signed file's text is the NFC form
of the input (51 bytes, not 53), the emoji's selector and the lone mark kept.

The variants are made by `bin/make-text-variants.php` from
`../fixture-signed.txt`; each changes the text, the wrapper or their order.
Answers recorded 2026-10-07 (state and sorted failure codes, or the error).
The stock `c2patool` 0.28.1 and 0.27.22 answer `Unsupported file type` to
every file here, the signed fixtures included; this verifier answers
`unknown`, `Invalid`, `general.error` to every one.

| file | what changed | `c2patool` 0.28.1 with `unstable_plain_text` |
|---|---|---|
| `../fixture-signed.txt` | nothing | `Valid`, `signingCredential.untrusted` |
| `../fixture-unsigned.txt` | the text before signing | Error: No claim found |
| `nfd-emoji-signed.txt` | signed from NFD input, with an emoji's `U+FE0F` and a lone `U+FEFF` | `Valid`, `signingCredential.untrusted` |
| `nfd-emoji-unsigned.txt` | its input | Error: No claim found |
| `crlf.txt` | the text's line ends made CRLF | `Invalid`, `assertion.dataHash.mismatch` ×2, `signingCredential.untrusted` |
| `bom-front.txt` | a UTF-8 BOM (`U+FEFF`) in front of the text | the same |
| `flip-text.txt` | the first letter changed (`C` → `K`) | `Invalid`, `assertion.dataHash.mismatch`, `signingCredential.untrusted` |
| `nfd-text.txt` | the text in NFD (`é` as `e` + `U+0301`): the same text, other bytes | `Invalid`, `assertion.dataHash.mismatch` ×2, `signingCredential.untrusted` |
| `no-wrapper.txt` | the wrapper removed | Error: No claim found |
| `no-marker.txt` | the `U+FEFF` before the selectors removed | Error: No claim found |
| `magic-other.txt` | `C2PATXU\0` | Error: No claim found |
| `version-2.txt` | version `02` | Error: No claim found |
| `length-too-long.txt` | the length field 3,527, one more than the store | **`Valid`**, `signingCredential.untrusted` (the store is read with one padding byte after it) |
| `cut-in-store.txt` | the wrapper cut halfway through the store | Error: No claim found |
| `no-padding.txt` | the padding removed | `Invalid`, `assertion.dataHash.mismatch` ×2, `signingCredential.untrusted` |
| `more-padding.txt` | three more padding bytes | the same |
| `letter-in-run.txt` | an `x` after the 100th selector | Error: No claim found |
| `flip-store.txt` | one bit flipped 40 bytes before the store's end (in the signature) | `Invalid`, `claimSignature.mismatch`, `signingCredential.untrusted` |
| `two-wrappers.txt` | the same wrapper twice | Error: No claim found |
| `bad-then-good.txt` | a wrapper with version `09` before the good one | `Invalid`, `assertion.dataHash.mismatch` ×2, `signingCredential.untrusted` |
| `text-after.txt` | a line of text after the wrapper | `Invalid`, `assertion.dataHash.mismatch`, `signingCredential.untrusted` |
| `wrapper-first.txt` | the wrapper before the text | `Invalid`, `assertion.dataHash.mismatch` ×2, `signingCredential.untrusted` |
| `only-wrapper.txt` | the wrapper without the text | the same |
| `utf16le.txt` | the whole file in UTF-16LE | Error: asset could not be parsed: text asset is not valid UTF-8 |
