# Files from `contentauth/c2pa-rs` (step 39)

The 33 JPEG, PNG and WebP files of `sdk/tests/fixtures/` in the oracle's
own repository, copied unchanged from
https://github.com/contentauth/c2pa-rs at commit `58eac79` (2026-09-21).
c2pa-rs is licensed **Apache-2.0 OR MIT** (both texts alongside, as the
repository ships them); that licence attaches to these files, not to
this package's code (MIT). Attribution: © Adobe and the c2pa-rs
contributors. Two images are NASA photographs (`earth_apollo17.jpg`,
`mars.webp`, public domain by origin). 12 MB, of which `exp-test1.png`
is 5.6 MB.

Why they are here: they are the files the reference implementation
tests itself with — older c2pa-rs writers (claim v1, indefinite-length
CBOR, `contentauth:urn:uuid:` labels), timestamps on most of them, an
update manifest, a box-hash-named file that carries a data hash, cloud
(remote) manifests, a prerelease claim, and twelve files with no
manifest at all. c2patool 0.27.22's JSON for the 17 that yield one,
with the full test trust settings, is under `../c2patool/c2pa-rs/`.

| file | c2patool (full settings) | note |
|---|---|---|
| `C`, `CA`, `CA_ct`, `boxhash` | `Trusted`, 1 manifest | `boxhash` carries `c2pa.hash.data` despite its name |
| `CACA`, `CACAE-uri-CA` (3), `CIE-sig-CA`, `legacy_ingredient_hash`, `update_manifest` | `Trusted`, 2–3 manifests | `Invalid` here until M7 (SPEC-013 AC11); `update_manifest` holds a `c2um` box this verifier refuses (SPEC-005) |
| `C_with_CAWG_data`, `cloud`, `ocsp`, `ocsp_with_assertion` | `Valid` (`signingCredential.untrusted`) | `cloud`: c2patool fetched the manifest from the network — this verifier never does |
| `E-sig-CA`, `XCA`, `adobe-20220124-E-clm-CAICAI`, `exp-test1` | `Invalid` | `exp-test1`: 6 manifests, `signingCredential.invalid` + `.untrusted` |
| `no_alg`, `prerelease` | `Error: unknown algorithm`, `Error: Prerelease claim found` | both refused here too |
| `cloudx`, `libpng-test_with_url` | `Error: could not fetch the remote manifest` | remote; no manifest here |
| `IMG_0003`, `P1000827`, `earth_apollo17`, `no_manifest`, `thumbnail`, `libpng-test`, `sample1.png`, `mars`, `sample1.webp`, `test`, `test_lossless`, `test_xmp` | `Error: No claim found` | no manifest |

Timestamps: 13 of the 17 JSON files carry `timeStamp.validated` (and
mostly `timeStamp.trusted`) — the M6 oracle.
