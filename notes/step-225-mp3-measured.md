# Step 225 — The signed MP3, and twenty-five ways to change its tag

*2026-10-05. `c2patool` 0.27.22 and 0.28.1, the c2pa-rs ES256 test
certificates. No verifier code, no spec yet.*

## What the specification says (read)

C2PA 2.4 §A.3.4, "Embedding manifests into ID3":

> The C2PA Manifest Store shall be embedded into a ID3v2-compatible,
> compressed audio file (e.g., MP3 or FLAC) file as the Encapsulated
> object data of a General Encapsulated Object (GEOB) as defined in
> https://id3.org/id3v2.3.0. The GEOB's MIME type field shall be present
> and shall use the value for the media type for JUMBF as described in
> Section 11.4, "External Manifests".

Two answers fall out of it. **FLAC through ID3 is what the specification
says**, not a `c2pa-rs` choice (step 203's open question). And the text
cites ID3v2.3, while `c2patool` writes ID3v2.4 (measured below).

## The fixture

`tests/Fixtures/fixture-unsigned.mp3` (4,940 bytes, SHA-256
`0a173e2700c907891675e94303e497a409e446f38a2ccbf97528c76131b88fff`; the
sister library's `fixture.mp3`, unchanged) already has an ID3v2.4 tag
written by ffmpeg: one `TSSE` frame and 10 bytes of padding.
`tests/Fixtures/fixture-signed.mp3` (18,444 bytes, SHA-256
`8cc01bf6d1c544284b7e6a62fd36fc5ec8503cd551d7b6d672fa73fed0f11e06`) was
signed with `c2patool` 0.27.22 and the WebP manifest definition with the
title changed (`fixture-signed-mp3.manifest.json`). `Valid` in both
versions, `Trusted` with `trust/full.settings.json`.

Its tag, parsed with a probe:

| offset | what |
|---|---|
| 0 | `ID3`, version 2.4, flags 0, syncsafe size 13,538 (the tag ends at 13,548) |
| 10 | `TSSE`, 13 bytes (`c2patool` rewrote it: it was 14) |
| 33 | `GEOB`, 13,505 bytes: encoding 3 (UTF-8), MIME `application/c2pa`, file `c2pa`, description `c2pa manifest store` |
| 86 | the store, 13,462 bytes (LBox 13,462) |
| 13,548 | the MPEG audio, as before |

`c2patool` rebuilt the whole tag: the ffmpeg padding is gone and `TSSE`
is a byte shorter. The data hash's exclusion is `[86, 13462]`: **the store
alone**. The ID3 header, both frame headers and the GEOB's text fields are
hashed.

## The variants, measured

`bin/make-mp3-variants.php` builds 25 variants; every answer of both
versions is in `tests/Fixtures/mp3/README.md`. Both versions agree on
every one.

1. **Read, then the data hash fails**: two C2PA GEOBs (the first is
   taken); another GEOB before it; text encodings 0, 1 and 2 (the
   two-byte UTF-16 terminators are honoured); ID3v2.3; the
   unsynchronisation flag; an extended header; a footer; padding; an ID3v1
   tag appended; **and a GEOB whose size runs 1,000 bytes past the tag**
   (the store is still read; LBox bounds it).
2. **No claim found**: a MIME type other than `application/c2pa`, upper
   case included; the frame's compression flag; an empty object; the tag
   size +1, or not syncsafe; 16 bytes before the tag; the store in a
   second tag at the end; the unsigned file with no tag, or with an
   ID3v1 tag.
3. **Errors**: a 4-byte object (*unexpected end of file*); a file cut
   inside the store (*invalid CBOR box*).
4. **`lbox-differs` is `Valid` in both**, as for WebP and WAV.

## What it means (reasoned)

- The reader needed is an ID3v2 tag walk (header, optional extended
  header, frames in v2.3 or v2.4 size encoding) and a GEOB body parser
  that honours the text encoding. The rest — signature, data hash, trust
  — is unchanged.
- The rule decided for RIFF fits here: **strict about the C2PA GEOB,
  lenient as `c2patool` about the rest.** Strict: two C2PA GEOBs, LBox
  against the object length, a C2PA GEOB that runs past the tag, an
  object too short. Lenient: other frames, padding, a footer, an ID3v1
  tag, bytes after the tag.
- **Open questions for the spec** (Maurice's):
  1. **Unsynchronisation.** The fixture's store holds no `FF 00` and no
     byte that unsynchronisation would change, so whether `c2patool`
     undoes it cannot be told from this file. Proposal: a C2PA GEOB in a
     tag (or frame) with the unsynchronisation flag is refused; a tag with
     the flag and no C2PA GEOB is no manifest.
  2. **The MIME match.** `c2patool` matches `application/c2pa` exactly;
     the specification names the JUMBF media type. Proposal: exactly,
     as `c2patool`.
  3. **The `format` value and detection.** A file opening with `ID3` may
     be MP3, FLAC or AAC. Proposal: `mp3` when MPEG frame sync follows
     the tag, and FLAC in its own later spec; and whether a plain MP3
     without a tag (`unsigned-no-tag`) is detected as `mp3` with no
     manifest or stays `unknown`.

## What this verifier says today

Every MP3 is `unknown` (`general.error`, *unsupported file type*).
