# Files signed with the conformance programme's test certificate (SPEC-078)

Made by `bin/make-conformance-test-variants.php` with `c2patool` 0.28.2 on
2026-10-23. The signer is "C2PA Conformance Test
Signing" (`test-signing.pem`), issued by "C2PA Conformance Test Root"
(`test-root.pem`), both from `contentauth/c2pa-conformance-tool` `6d10ee0`
(Apache-2.0): the root embedded in `src/lib/CertificateManager.svelte`, the
leaf and its key in `public/test-certs/test-signing-bundle.zip`, published on
purpose for testing. The key was used in a scratch directory outside the
repository and deleted; **no key is here**.

`signed.*` are `fixture-unsigned.*` signed with one `c2pa.actions.v2`
(`c2pa.created`, `digitalCapture`, `allActionsIncluded`); `edited.*` have one
byte of the image data changed after signing (JPEG and PNG 40 bytes before
the end, WebP at offset 40, inside `VP8L`). No time-stamp: the bundle has no
time-stamp authority. Running the script again makes new bytes (an ECDSA
signature is randomised); the files here are the ones measured.

`root.settings.json` holds the root as `trust_anchors`; `root-store-cfg.settings.json`
adds `../trust/store.cfg` as `trust_config`. `c2patool`'s answers with each are in
`../c2patool/conformance-test/`.

| file | SHA-256 |
|---|---|
| `signed.jpg` | `1762fe8f1b8578b2416d0a7dd09a90b9b7ac97b606556460fcc720888d4a4db9` |
| `edited.jpg` | `760c8fd29dfac9a3c9e1a9ac3aa779011269cf89bb7878025697c1d259bbaa40` |
| `signed.png` | `de5b85913c20c1548d91a8b1e37b25e46be4d98ac65b7423afc48cca097a5683` |
| `edited.png` | `0b1c9080039f57ea0e361a51223e2b8061ad975d79a453fb4f8022b42ec99f7d` |
| `signed.webp` | `d59013daeb5f7b6b2e4b8ba80599b4640dc0818076303966865cf41979a1d96b` |
| `edited.webp` | `9ee6148142db324053ce7a66d8bd8990ddde5d531b3ed218669a2a8f46c6d393` |
