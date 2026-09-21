# c2patool's JSON with trust settings (step 30, for M5)

`c2patool <file> --settings ../../trust/<variant>.settings.json`, c2patool
0.27.22, recorded 2026-09-21, unchanged.

| file | fixture | settings | what c2patool says |
|---|---|---|---|
| `png.json`, `jpg.json`, `webp.json`, `adobe-20220124-C.json` | the four fixtures | `full` | `validation_state` **`Trusted`**; success `signingCredential.trusted`, url `…/c2pa.signature`, explanation `signing certificate trusted, found in System trust anchors`; **no `validation_status` key** (no failures) |
| `png-allowed-only.json` | PNG | `allowed-only` | `Trusted`; explanation `… found in EndEntity trust anchors` |
| `png-anchors-wrong-eku.json` | PNG | `anchors-wrong-eku` | `Trusted` — `trust_config` adds EKUs, it cannot remove emailProtection (c2pa-rs `has_allowed_eku()`) |
| `png-verify-off.json` | PNG | `verify-off` | `Valid`; no `signingCredential.*` status at all |
| `png-untrusted-rsa-root-only.json` | PNG | `rsa-root-only` | `Valid`; failure `signingCredential.untrusted` — the EC chain reaches no RSA anchor |
| `adobe-untrusted-ec-root-only.json` | Adobe | `ec-root-only` | `Valid`; failure `signingCredential.untrusted` |

Added 2026-09-21 (step 31, for SPEC-014):

| file | fixture | settings | what c2patool says |
|---|---|---|---|
| `x5chain-leaf-only.json` | `../../binding/x5chain-leaf-only.png` | `full` | `Invalid`; failures `signingCredential.untrusted` and `claimSignature.mismatch` — the chain does not carry the intermediate, no anchor signs the leaf; the same without settings |
| `png-intermediate-anchor.json` | PNG | `intermediate-anchor` | `Trusted`, "found in System trust anchors" — an intermediate on the anchor list ends the walk (`PARTIAL_CHAIN`) |
| `png-allowed-plus-wrong-root.json` | PNG | `allowed-plus-wrong-root` | `Trusted`, "found in EndEntity trust anchors" — the allowed list wins before the anchors are tried |
| `pixel-changed.json` | `../../binding/pixel-changed.png` | `full` | `Invalid`; success `signingCredential.trusted`, failure `assertion.dataHash.mismatch` — trust does not rescue a tampered file |

Also in every file: `manifests.<label>.signature_info` = `{alg, issuer,
common_name, cert_serial_number}` — `alg` as `Es256`/`Ps256`, `issuer` the
leaf's *O*, the serial in decimal.
