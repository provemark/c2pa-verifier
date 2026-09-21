# Notes

The record of what was built, step by step, written for someone outside the
project. One file per step under `notes/`; this page is the index. Each note
keeps *measured* (a command was run, and which) apart from *reasoned* (a
conclusion from reading). The plan these steps follow is
[`docs/milestones.md`](docs/milestones.md); who did what, per session, is
[`AI-LOG.md`](AI-LOG.md).

| Step | Date | What | Note |
|---|---|---|---|
| 01 | 2026-09-19 | M0: the skeleton — package, tool chain, SPEC-000, CI, documents | [`notes/step-01-m0-skeleton.md`](notes/step-01-m0-skeleton.md) |
| 02 | 2026-09-19 | The signed JPEG fixture; what a JPEG is; APP11 pieces measured; how c2patool treats gaps and swaps | [`notes/step-02-jpeg-fixture.md`](notes/step-02-jpeg-fixture.md) |
| 03 | 2026-09-19 | The first code: the JPEG extractor; a wrong fixture found and fixed; c2patool ignores LBox in continuation pieces, AC7 kept stricter; amendment 1: two fixtures for the marker table and the end-of-file probe, a wrong claim caught by a mutation test | [`notes/step-03-jpeg-extractor.md`](notes/step-03-jpeg-extractor.md) |
| 04 | 2026-09-19 | The signed PNG fixture; what a PNG is; the `caBX` chunk measured; ten variants through c2patool; the CRC and LBox are not checked by c2pa-rs | [`notes/step-04-png-fixture.md`](notes/step-04-png-fixture.md) |
| 05 | 2026-09-20 | The PNG extractor; AC14 found the end-of-file probe naming the wrong chunk; CRC, LBox and empty chunk stricter than c2patool | [`notes/step-05-png-extractor.md`](notes/step-05-png-extractor.md) |
| 06 | 2026-09-20 | The signed WebP fixture; what a RIFF file is; the `C2PA` chunk and its pad byte measured; fifteen variants through c2patool; c2pa-rs takes the first `C2PA`, ignores the form type, honours the RIFF size | [`notes/step-06-webp-fixture.md`](notes/step-06-webp-fixture.md) |
| 07 | 2026-09-20 | The WebP extractor; the RIFF size checked against the file first; seven variants stricter than c2patool; **M1 complete** | [`notes/step-07-webp-extractor.md`](notes/step-07-webp-extractor.md) |
| 08 | 2026-09-20 | One `StreamReader` for the three extractors; the JPEG probe replaced by the file-end look-up (SPEC-001 AC16); 60 lines fewer | [`notes/step-08-stream-reader.md`](notes/step-08-stream-reader.md) |
| 09 | 2026-09-20 | The manifest store from the inside: the JUMBF tree, the CBOR inventory (the subset is small — measured), the detached COSE payload, `claim_version` from the label; a 2022 Adobe file (claim v1, JSON box, timestamp) through our extractor | [`notes/step-09-manifest-store-inside.md`](notes/step-09-manifest-store-inside.md) |
| 10 | 2026-09-21 | Twenty-three malformed manifest stores through c2patool before SPEC-005's approval; three `Valid`s where SPEC-005 errs (root label, root LBox, toggle bit); `unknown-uuid` is the claim's error, not the box's | [`notes/step-10-jumbf-variants.md`](notes/step-10-jumbf-variants.md) |
| 11 | 2026-09-21 | The JUMBF parser: the store as a tree of boxes, `payload()` hashing to the claim's values, unknown boxes kept, compressed manifests refused; the `Support` layer for `Bytes::hex()` | [`notes/step-11-jumbf-parser.md`](notes/step-11-jumbf-parser.md) |
| 12 | 2026-09-21 | Sixteen CBOR values recorded as data; four faults in a signed claim through c2patool — indefinite lengths, duplicate keys and non-shortest ints are all *read* by c2pa-rs | [`notes/step-12-cbor-vectors.md`](notes/step-12-cbor-vectors.md) |
| 13 | 2026-09-21 | The CBOR decoder: the measured subset, every RFC 8949 Appendix A/F vector the spec lists, sixteen recorded values reproduced; three test-file mistakes caught on the first run | [`notes/step-13-cbor-decoder.md`](notes/step-13-cbor-decoder.md) |
