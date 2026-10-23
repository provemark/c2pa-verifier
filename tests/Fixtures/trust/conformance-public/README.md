# The C2PA Conformance Program's trust lists (SPEC-077)

`C2PA-TRUST-LIST.pem` (32 certificates) and `C2PA-TSA-TRUST-LIST.pem`
(23 certificates) as published in
[`c2pa-org/conformance-public`](https://github.com/c2pa-org/conformance-public),
`trust-list/`, at commit `da71a72`, fetched on 2026-10-23 and not changed.

- `C2PA-TRUST-LIST.pem` SHA-256 `b3949271a07b007e92ef1d18050b55388f5e0d2686a16fd864176c48421af8ae`
- `C2PA-TSA-TRUST-LIST.pem` SHA-256 `7182cb227c02701be0eebd67ab635fca7f85431c32796fb53b267c657cd2c675`

Published by the C2PA under the Creative Commons Attribution 4.0
International licence (CC BY 4.0). They are here to test the
`--trust-list` and `--tsa-trust-list` inputs, not as a trust policy:
the lists change, and a verifier in use reads the current ones. Sixteen
certificates are in both lists.
