# c2patool's JSON under a TSA anchor (step 42a, for SPEC-017)

`c2patool <file> --settings ../../trust/<anchor>.settings.json`, c2patool
0.27.22 (`c2pa/0.90.22`), recorded 2026-09-22, unchanged.

The two anchors are public certificates cut out of the corpus tokens
themselves (`openssl ts -reply -token_out | openssl pkcs7 -print_certs`):

- `truepic-root` — `CN=RootCA, OU=Lens, O=Truepic, C=US`, self-signed,
  valid 2021-12-09 to 2036-12-05; the root of the Truepic TSA *and* of the
  Truepic camera signers.
- `digicert-trusted-root-g4` — `CN=DigiCert Trusted Root G4`, the
  cross-certificate issued by `DigiCert Assured ID Root CA`, valid
  2022-08-01 to 2031-11-09, as the DigiCert tokens carry it.

| file | fixture | settings | what c2patool says |
|---|---|---|---|
| `truepic-20230212-{camera,landscape,library}-truepic-root.json` | the three Truepic files | `truepic-root` | **`Trusted`** — `timeStamp.validated`, `timeStamp.trusted` ("timestamp cert trusted: Truepic Lens Time-Stamping Authority"), `signingCredential.trusted`; no `signingCredential.expired`: the signer's one-day certificate is judged at the stamp's time. Without settings the same files are `Valid` with `signingCredential.untrusted` (`../public-testfiles/`). |
| `C-digicert-g4.json`, `CACA-digicert-g4.json` | `../../c2pa-rs/C.jpg`, `CACA.jpg` | `digicert-trusted-root-g4` | `Valid`; `timeStamp.validated`, `timeStamp.trusted`, `signingCredential.untrusted` (the C2PA test signer reaches no DigiCert anchor). For `CACA.jpg` this is the first run where c2patool says `timeStamp.trusted` for the 2025 responder — it was `untrusted` without settings (`../c2pa-rs/CACA.json`). |
| `exp-test1-full-plus-digicert-g4.json` | `../../c2pa-rs/exp-test1.png` | `full-plus-digicert-g4` (the C2PA test anchors plus the cross-certificate) | `Invalid` — one of its six manifests is self-signed (`signingCredential.invalid`); the active manifest has `timeStamp.validated`, `timeStamp.trusted`, `signingCredential.untrusted` (its `cai-prod` signer reaches no test anchor) and no `expired`: its signer (`cai-prod`, valid 2022-03-01 to 2023-03-01) is judged at the stamp, 2022-04-20 |
| `C-truepic-root.json` | `C.jpg` | `truepic-root` | `Valid`; `timeStamp.trusted` still — c2patool's `trusted` for the DigiCert 2023 TSA does not depend on the anchor configured (step 40 §5). |

What this verifier makes of the same files under the same settings is
SPEC-017 AC6: equal state and failures for the Truepic three; for
`C.jpg`/`CACA.jpg` equal state, and `timeStamp.trusted` through the
cross-certificate as the anchor.
