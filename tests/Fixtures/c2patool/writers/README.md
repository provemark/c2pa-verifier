# c2patool's JSON on the writers corpus (step 43)

`c2patool <file>` without settings, c2patool 0.27.22 (`c2pa/0.90.22`),
recorded 2026-09-22, unchanged. All five are `Valid` with
`signingCredential.untrusted` (no anchors); the timestamp entries and
`signature_info.time` are what SPEC-016/017's amendments are measured
against:

| file | c2patool |
|---|---|
| `openai-20260826-c2pa_2x` | `timeStamp.validated`, `timeStamp.untrusted` ("OpenAI TSA Leaf" — a private TSA c2patool does not trust either); `time` **`2026-08-26T10:48:55.837381+00:00`** — the token's microseconds kept |
| `amazon-20240925-titan-g1` | `timeStamp.validated`, `timeStamp.trusted` (DigiCert Timestamp 2023); `time` `2024-09-25T08:29:07+00:00`; ES384 signature validated |
| `trustnxt-20260113-icon-signed-timestamp` | `timeStamp.validated`, `timeStamp.trusted` ("C2PA Signer" — the test certificate as TSA); `time` **`2026-01-13T12:35:40.669+00:00`** — milliseconds kept; a v1 claim with `sigTst2` accepted |
| `adobe-20260304-photoshop-remote-manifest` | one manifest, fetched from `cai-manifests.adobe.com` over the network; `timeStamp.validated` ("Adobe SHA256 ECC256 Timestamp Responder 2025 1"); `time` `2026-03-04T18:13:46+00:00` |
| `c2pa-rs-cawg_ica` | two manifests, both `Valid`; no timestamp; the CAWG ICA assertion read and reported |
| `google-20250919-pixel10-npld-picnic-table` | `Invalid`; `signingCredential.expired` and `.untrusted`; `timeStamp.validated`, `timeStamp.untrusted` ("Google Pixel Time Stamping Authority" — a private TSA c2patool does not trust either); `time` `2025-09-19T21:57:51+00:00`; `assertion.dataHash.match` with `additionalExclusionsPresent` (the gain-map regions) |
| `adobe-20260425-lightroom-classic-church` | `Valid`; `signingCredential.untrusted`; `timeStamp.validated`, `timeStamp.trusted` (DigiCert); `time` `2026-04-25T09:46:07+00:00` |

Under `../../trust/google-pixel-intermediates.settings.json` (the two
Google intermediates cut from the file and its token, step 46):
`../timestamp/google-20250919-pixel10-npld-picnic-table-google-intermediates.json`
— **`Trusted`**, `timeStamp.trusted`, `signingCredential.trusted`, no
`expired`.

