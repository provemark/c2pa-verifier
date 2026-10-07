# Step 265 — plain text measured

*2026-10-07. The measurement step 183 left for later, now that an oracle
exists. Measurement only; no spec, no code in `src/`.*

## The oracle

Neither stock `c2patool` reads text: 0.27.22 and 0.28.1 answer
`Unsupported file type` to every file of this step, signed or not
(measured). The oracle is `c2patool` 0.28.1 built with the experimental
Cargo feature `unstable_plain_text`, over c2pa-rs 0.91.1, whose handler is
`src/asset_handlers/plain_text_io.rs`. What follows under *read* comes from
that file; what comes under *measured* comes from running that build.

## What C2PA 2.4 says (§A.8, read)

The store goes after the visible text as a `C2PATextManifestWrapper`:
`U+FEFF`, then one variation selector per byte — 0–15 as `U+FE00`–`U+FE0F`,
16–255 as `U+E0100`–`U+E01EF` — of `C2PATXT\0`, a version byte (1), a
big-endian 32-bit length, the store and optional padding. The hard binding
is a `c2pa.hash.data` over the text without the wrapper, after NFC
normalisation and as UTF-8.

## What c2pa-rs does (read)

- **Reading.** The file must be UTF-8 as a whole, or it is an error. Every
  `U+FEFF` followed by a run of selectors is a candidate; a candidate counts
  when its run decodes to the magic, version 1 and at least as many bytes
  as its length field declares. Candidates that do not decode are skipped.
  **Exactly one** valid wrapper is a manifest; none or two or more is "No
  claim found". The store is the declared number of bytes after the
  header; the rest of the run is padding, not checked.
- **Where.** The wrapper may stand anywhere in the text; the reader scans
  the whole text. What binds the place is the data hash's exclusion.
- **Writing.** The text is normalised to NFC and every existing wrapper is
  stripped before the new one is appended, so a signed file is always NFC.
  The wrapper is padded to `3 + (13 + M) × 4 + 6` UTF-8 bytes (`M` the store's
  length), with `00` bytes and up to two `10` bytes.
- **NFC on verification: none.** The file's comment says it plainly:
  verification hashes the raw bytes outside the exclusion, like every other
  format, because the handler interface has no hook for a transform. A text
  another writer signed over the NFC form of non-NFC bytes would fail here.
  c2pa-rs PR #2732 (open) would change that.

## What it writes (measured)

`fixture-signed.txt` is `fixture-unsigned.txt` (60 bytes, already NFC)
unchanged, then the wrapper: 4,030 selectors, the store 3,526 bytes (one
`jumb` box), 491 bytes of padding, 14,165 UTF-8 bytes — the formula's value.
The data hash has one exclusion, `{start: 60, length: 14165}`: from the
marker to the end of the file. Both signed fixtures read `Valid`,
`signingCredential.untrusted`.

Signed from NFD input, the text came out in NFC (51 bytes from 53), with an
emoji's `U+FE0F` and a lone `U+FEFF` in the text kept: a lone mark and a
selector that belongs to an emoji are not mistaken for a wrapper.

## The variants (measured)

Twenty, by `bin/make-text-variants.php`; every answer is in
`tests/Fixtures/text/README.md`.

- **Judged by the hash** (`Invalid`, `assertion.dataHash.mismatch`): CRLF
  line ends, a BOM in front, one letter changed, the text in NFD (the same
  text, other bytes), the padding removed or lengthened, a line after the
  wrapper, the wrapper first, the wrapper alone, a bad wrapper before the
  good one (read, but it shifts the good one's place).
- **A store byte in the signature flipped**: `claimSignature.mismatch`.
- **No claim found**: the wrapper removed, the marker removed, another
  magic, version 2, the store cut, a letter inside the run, two identical
  wrappers.
- **An error**: the file in UTF-16LE ("text asset is not valid UTF-8").
- **`Valid`, and worth a decision:** the length field one larger than the
  store. The store is then read with one padding byte after the `jumb` box,
  and c2pa-rs's JUMBF reader does not mind a byte after the box. The text
  and the store are untouched, so this is not a wrong `Valid` of the kind
  that matters most; it is lenience about the frame.

This verifier today: `unknown`, `Invalid`, `general.error` on every file,
the signed fixtures included. It fails closed; it does not read text.

## What it means for a spec (reasoned)

- Text is a new container (like GIF) with the existing data hash; no new
  cryptography. A reader decodes one wrapper and gives its byte range; the
  `c2pa.hash.data` check is the one that exists.
- **Raw bytes, no NFC.** Normalising before hashing would say `Valid` where
  the oracle says `Invalid` (`nfd-text.txt` under a writer that hashed the
  NFC form). That is the dangerous direction, and NFC would also need
  `ext-intl`, which this project does not allow. Follow c2pa-rs; revisit if
  PR #2732 lands.
- **Exactly one valid wrapper**, others skipped, as c2pa-rs. Two valid ones
  are no manifest; two are caught by the hash anyway.
- Open questions for the spec, for Maurice:
  1. Opt-in (as c2pa-rs's feature flag) or on by default? The feature is
     experimental upstream, and Unicode's L2/26-042 objects to the scheme.
  2. A length field that does not match the store (`length-too-long.txt`):
     accept, as c2pa-rs, or refuse as malformed (stricter, by name)?
  3. Padding: accept any bytes, as c2pa-rs, or only `00` and `10`?
  4. Bounds: a text is read whole by c2pa-rs; here it should be read in
     pieces, with a ceiling on the wrapper (the store's own limit, × 4 in
     UTF-8).
- Not looked at in this step: §A.9 (structured text), Encypher's
  `c2pa-text` and the Go implementation as second oracles.
