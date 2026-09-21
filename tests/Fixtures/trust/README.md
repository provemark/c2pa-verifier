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
