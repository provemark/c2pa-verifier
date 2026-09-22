# Trust material for M5 (step 30)

Public test certificates and settings, byte-identical to
`contentauth/c2patool`'s `sample/` via the sister repository. **No private
key is here or anywhere in this repository** (`git ls-files | grep '\.key$'`
is empty and stays so).

| file | what |
|---|---|
| `es256_certs.pem` | the EC signing chain the three `fixture-signed.*` carry: leaf `C2PA Signer` (EKU emailProtection) + `Intermediate CA`; the root is not in the file |
| `trust_anchors.pem` | two self-signed `Root CA`s: one P-256 (the EC hierarchy's), one RSA-PSS 4096 (the RSA hierarchy's, which `adobe-20220124-C.jpg` uses) |
| `allowed_list.pem` | three end-entity/intermediate certificates accepted without a chain: the EC leaf, the RSA-PSS leaf, the RSA-PSS intermediate |
| `store.cfg` | the EKU list (`trust_config`) as c2patool's sample ships it: emailProtection, documentSigning, timeStamping, OCSPSigning |
| `*.settings.json` | c2patool settings variants, each with the PEM/cfg *contents* as strings (the format the sister library and this verifier share): `full` (anchors + config), `anchors-no-config`, `anchors-wrong-eku` (config = documentSigning only), `allowed-only`, `full-plus-allowed`, `verify-off`, `ec-root-only`, `rsa-root-only`; added in step 31 (SPEC-014): `intermediate-anchor` (the EC intermediate as the only anchor) and `allowed-plus-wrong-root` (the allowed list next to the RSA root as the only anchor) |

What c2patool 0.27.22 said under each, on the PNG and Adobe fixtures, is
in `../c2patool/trusted/` and in `notes/step-30-trust-measured.md`.

Added 2026-09-22 (step 42a, for SPEC-017 — TSA anchors, public
certificates cut out of the corpus tokens):

| file | what |
|---|---|
| `truepic-root.pem`, `truepic-root.settings.json` | `CN=RootCA, OU=Lens, O=Truepic` (self-signed, 2021–2036), from the Truepic tokens; the settings hold it as the only anchor with `store.cfg` as `trust_config` |
| `digicert-trusted-root-g4.pem`, `digicert-trusted-root-g4.settings.json` | the `DigiCert Trusted Root G4` cross-certificate (issued by `DigiCert Assured ID Root CA`, 2022–2031) as the DigiCert tokens carry it; the only anchor in its settings |
| `full-plus-digicert-g4.settings.json` | `full`'s two test anchors plus the DigiCert cross-certificate — for the c2pa-rs corpus, whose signers reach the test root and whose DigiCert TSAs reach the cross-certificate |
| `google-c2pa-mobile-ica.pem`, `google-c2pa-pixel-tsa-ica.pem`, `google-pixel-intermediates.settings.json` | the two Google intermediates (`Google C2PA Mobile A 1P ICA G3 L3` from the Pixel file's `x5chain`; `Google C2PA Pixel Time-Stamping ICA G3` from its token), both issued by `Google C2PA Root CA G3`, which is nowhere in the file — an intermediate on the anchor list ends the walk (step 46) |
