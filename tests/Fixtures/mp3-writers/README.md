# MP3 files from other writers (step 230)

| file | source | what it is | `c2patool` 0.27.22 / 0.28.1 |
|---|---|---|---|
| `c2pa-ts-signed.mp3` | made here from `../fixture-unsigned.mp3` with `sign-with-c2pa-ts.mjs` and `@trustnxt/c2pa-ts` 0.14.0 (Apache-2.0), the c2pa-rs ES256 test certificate, no timestamp | signed by an implementation independent of `c2pa-rs`: GEOB MIME `application/x-c2pa-manifest-store`, encoding 0, the GEOB first; its data-hash exclusion is the whole tag `[0, 27202]` | `Valid` / `Invalid` (`assertion.dataHash.mismatch`: the exclusion is not the store's place); this verifier since step 231: `Invalid`, `assertion.dataHash.mismatch`, as 0.28.1 |
| `c2pa-rs-id3v23_compression_underflow.mp3` | `contentauth/c2pa-rs` `sdk/tests/fixtures/` at `e4f63a2` (Apache-2.0 OR MIT) | hostile: an ID3v2.3 compressed frame that underflows | *No claim found* in both; this verifier: `mp3`, no manifest |

The script is run as
`node sign-with-c2pa-ts.mjs <source> <target> <certificate chain PEM> <PKCS#8 key> SHA-256`
with `@trustnxt/c2pa-ts@0.14.0`, `@peculiar/x509` and `reflect-metadata`
installed; the key is the c2pa-rs public test key and is not in this
repository. `c2patool`'s JSON for the signed file is under
`../c2patool/mp3-writers/`. The licence texts are alongside; they attach to
these files, not to this package's code.

No other signed MP3 was found in the public C2PA repositories on
2026-10-05: `c2pa-rs`, `c2pa-python` and `c2pa-ts` hold unsigned MP3s only.
