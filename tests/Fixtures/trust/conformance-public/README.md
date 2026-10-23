# The C2PA Conformance Program's trust lists (SPEC-077)

`C2PA-TRUST-LIST.pem` (32 certificates) and `C2PA-TSA-TRUST-LIST.pem`
(23 certificates), and the same two lists as JSON in ETSI TS 119 602's
List of Trusted Entities form (SPEC-079), as published in
[`c2pa-org/conformance-public`](https://github.com/c2pa-org/conformance-public),
`trust-list/`, at commit `da71a72`, fetched on 2026-10-23 and not changed.

- `C2PA-TRUST-LIST.pem` SHA-256 `b3949271a07b007e92ef1d18050b55388f5e0d2686a16fd864176c48421af8ae`
- `C2PA-TSA-TRUST-LIST.pem` SHA-256 `7182cb227c02701be0eebd67ab635fca7f85431c32796fb53b267c657cd2c675`
- `C2PA-TRUST-LIST.json` SHA-256 `f023f31dc3eb534d0afaab97b95cc8744ee2d88da6daebe5abf08770c3841d8c`
- `C2PA-TSA-TRUST-LIST.json` SHA-256 `7ac0b1c96e4b5ad346c7f40794c54ce8510776e4a772d62c6ec33556a011a503`

Published by the C2PA under the Creative Commons Attribution 4.0
International licence (CC BY 4.0). They are here to test the
`--trust-list` and `--tsa-trust-list` inputs, not as a trust policy:
the lists change, and a verifier in use reads the current ones. Sixteen
certificates are in both lists.

In the JSON form three NETLOCK services have an empty `ServiceStatus` and
`StatusStartingTime` (two in the signer list, one in the TSA list), which
the programme's schema extension does not allow; SPEC-079 does not use
them. Castlabs C2PA ECC P-384 Root CA is listed from 2026-08-10, a month
after its certificate became valid (step 383).
