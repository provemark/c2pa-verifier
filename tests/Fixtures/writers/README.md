# Files from other writers (step 43)

Signed files from writers the three older corpora do not have — OpenAI,
Amazon Bedrock, TrustNXT's `c2pa-ts`, Adobe Photoshop 2026 — plus one
two-manifest CAWG file, taken from the test data of other open-source
C2PA implementations on 2026-09-22. Their licences are alongside; the
files are unchanged. No private key is here (`grep -l 'PRIVATE KEY'`
over this directory is empty).

| file | origin (commit) | licence | writer / signer | what it brings |
|---|---|---|---|---|
| `openai-20260826-c2pa_2x.png` | `richardwooding/c2pa` `testdata/c2pa_2x_openai.png` (3ad7258) | MIT | OpenAI Media Service API, c2pa-rs 0.79.2; PS256, claim v2, `sigTst2`; a private "OpenAI TSA" | a production writer; a `genTime` with microseconds (`…55.837381Z`) |
| `amazon-20240925-titan-g1.png` | `TrustNXT/c2pa-ts` `tests/fixtures/amazon-titan-g1.png` (14f8ad7) | Apache-2.0 | Amazon Bedrock (Titan), c2pa-rs 0.32.7; **ES384**, claim v1, `sigTst`; DigiCert 2023 TSA, sha384 imprint | the first ES384 signer; a **negative nonce** in the TSTInfo (`0x-335F9549` as OpenSSL prints it) |
| `trustnxt-20260113-icon-signed-timestamp.jpg` | `TrustNXT/c2pa-ts` `tests/fixtures/trustnxt-icon-signed-timestamp.jpg` (14f8ad7) | Apache-2.0 | `c2pa-ts` (a TypeScript writer, not c2pa-rs); ES256 with the C2PA test certificate, **claim v1 with `sigTst2`**; a TSA signed by the same test certificate | a non-c2pa-rs writer; `genTime` with milliseconds (`…40.669Z`); a negative nonce; the v1/`sigTst2` pairing |
| `adobe-20260304-photoshop-remote-manifest.jpg` | `contentauth/c2pa-js` `packages/c2pa-web/test/assets/PirateShip_save_credentials_to_cloud.jpg` (b2f23fc) | MIT | Adobe Photoshop 27.4.0, c2pa-rs 0.72.0; PS256, claim v2 | **no manifest in the file**: XMP `dcterms:provenance` points to `https://cai-manifests.adobe.com/manifests/…`; c2patool fetches it, this verifier never will |
| `c2pa-rs-cawg_ica.jpg` | `richardwooding/c2pa` `testdata/cawg_ica.jpg` (3ad7258) | MIT | `c2pa_test/1.0.0`; two manifests, a CAWG identity-claims-aggregation assertion | M7 material: an ingredient manifest plus a CAWG ICA credential |

Left out on purpose: `richardwooding/c2pa`'s `c2pa_signed.jpg` and
`cawg_x509.jpg` (byte-identical to `../c2pa-rs/CA.jpg` and
`C_with_CAWG_data.jpg`, md5 checked); `TrustNXT`'s unsigned
`trustnxt-icon.{jpg,png}`; `faceless2/c2pa`'s
`adobe-20221004-ukraine_building.jpeg` (7.7 MB for an Adobe Stock /
c2pa-rs 0.4.2 file whose only new fact — a 2022 production certificate,
expired at now, judged at its DigiCert 2022 stamp — the Truepic files
already show).

c2patool 0.27.22's JSON for each, without settings, is in
`../c2patool/writers/`.
