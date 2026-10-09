# The trust matrix (SPEC-061)

Built by `bin/make-trust-matrix.php <scratch> <c2patool-0.28.1>
<c2patool-0.27.22> chain-matrix <probe>…` on 2026-10-08 (step 288). One
valid chain (leaf ← intermediate ← anchor: P-256, SHA-256, the C2PA leaf
profile) and 41 variants (44 since step 302), each one property of one certificate away from it.
Each PNG is `../../fixture-signed.png` with its claim re-signed by the
probe's throw-away leaf; x5chain holds the leaf and the intermediate, the
anchor is left out. The keys lived in a scratch directory while the script
ran and were deleted. No private key is here. `<probe>.anchor.pem` is each
probe's anchor; `<probe>.settings.json` holds it as the legacy
`trust.trust_anchors` string with `store.cfg`.

Certificates without fixed dates were made valid for ten years from the day
they were built, so most probes stop being `Trusted` around 2036. The
validity variants use 2020–2021 and 2090–2100.

The answers of both `c2patool` versions are under
`../../c2patool/chain-matrix/<probe>--<version>.json`. OpenSSL's answer
(`openssl verify -x509_strict -partial_chain`, 3.6) is recorded here only,
as information: it does not apply the C2PA profile. A state is followed by
its failure codes.

| probe | c2patool 0.27.22 | c2patool 0.28.1 | OpenSSL | this verifier |
|---|---|---|---|---|
| `control` | Trusted | Trusted | OK | Trusted |
| `leaf-expired` | Invalid, signingCredential.expired | Invalid, signingCredential.untrusted,signingCredential.expired | refused: certificate has expired | Invalid, signingCredential.expired |
| `leaf-not-yet-valid` | Invalid, signingCredential.expired | Invalid, signingCredential.untrusted,signingCredential.expired | refused: certificate is not yet valid or the system clock is incorrect | Invalid, signingCredential.expired |
| `leaf-ca-true` | Invalid, signingCredential.invalid | Invalid, signingCredential.invalid | OK | Invalid, signingCredential.invalid |
| `leaf-no-basic-constraints` | Trusted | Trusted | OK | Trusted |
| `leaf-no-key-usage` | Invalid, signingCredential.invalid | Invalid, signingCredential.invalid | OK | Invalid, signingCredential.invalid |
| `leaf-ku-key-encipherment` | Invalid, signingCredential.invalid | Invalid, signingCredential.invalid | OK | Invalid, signingCredential.invalid |
| `leaf-ku-cert-sign` | Invalid, signingCredential.invalid,signingCredential.untrusted | Invalid, signingCredential.untrusted,signingCredential.invalid | refused: Key usage keyCertSign invalid for non-CA cert | Invalid, signingCredential.invalid |
| `leaf-no-eku` | Invalid, signingCredential.invalid | Invalid, signingCredential.invalid | OK | Invalid, signingCredential.invalid |
| `leaf-eku-any` | Invalid, signingCredential.invalid | Invalid, signingCredential.invalid | OK | Invalid, signingCredential.invalid |
| `leaf-eku-time-stamping` | Trusted | Trusted | OK | Valid, signingCredential.untrusted |
| `leaf-eku-server-auth` | Invalid, signingCredential.invalid | Invalid, signingCredential.invalid | OK | Invalid, signingCredential.invalid |
| `leaf-eku-unknown-oid` | Invalid, signingCredential.invalid | Invalid, signingCredential.invalid | OK | Invalid, signingCredential.invalid |
| `leaf-critical-unknown-ext` | Invalid, signingCredential.invalid,signingCredential.untrusted | Invalid, signingCredential.untrusted,signingCredential.invalid | refused: unhandled critical extension | Invalid, signingCredential.invalid,signingCredential.untrusted |
| `leaf-sha1` | Invalid, signingCredential.invalid | Invalid, signingCredential.invalid | OK | Invalid, signingCredential.invalid |
| `leaf-p384` | Trusted | Trusted | OK | Trusted |
| `leaf-rsa2048` | Trusted | Trusted | OK | Trusted |
| `leaf-rsa1024` | Invalid, signingCredential.invalid | Invalid, signingCredential.invalid | OK | Invalid, signingCredential.invalid |
| `leaf-ed25519` | Trusted | Trusted | OK | Trusted |
| `int-expired` | Trusted | Valid, signingCredential.untrusted | refused: certificate has expired | Valid, signingCredential.untrusted |
| `int-not-yet-valid` | Trusted | Valid, signingCredential.untrusted | refused: certificate is not yet valid or the system clock is incorrect | Valid, signingCredential.untrusted |
| `int-ca-false` | Valid, signingCredential.untrusted | Valid, signingCredential.untrusted | refused: invalid CA certificate CN=Matrix Intermediate (int-ca-false), O=c2pa-v | Valid, signingCredential.untrusted |
| `int-no-basic-constraints` | Valid, signingCredential.untrusted | Valid, signingCredential.untrusted | refused: invalid CA certificate CN=Matrix Intermediate (int-no-basic-constraint | Valid, signingCredential.untrusted |
| `int-no-key-usage` | Valid, signingCredential.untrusted | Valid, signingCredential.untrusted | refused: CA cert does not include key usage extension | Valid, signingCredential.untrusted |
| `int-ku-no-cert-sign` | Valid, signingCredential.untrusted | Valid, signingCredential.untrusted | refused: invalid CA certificate CN=Matrix Intermediate (int-ku-no-cert-sign), O | Valid, signingCredential.untrusted |
| `int-pathlen-0` | Trusted | Trusted | OK | Trusted |
| `int-eku-time-stamping` | Trusted | Trusted | OK | Trusted |
| `int-eku-email` | Trusted | Trusted | OK | Trusted |
| `int-critical-unknown-ext` | Valid, signingCredential.untrusted | Valid, signingCredential.untrusted | refused: unhandled critical extension | Valid, signingCredential.untrusted |
| `int-sha1` | Trusted | Trusted | OK | Valid, signingCredential.untrusted |
| `int-rsa1024` | Trusted | Trusted | OK | Trusted |
| `int-rsa2048` | Trusted | Trusted | OK | Trusted |
| `anchor-expired` | Trusted | Valid, signingCredential.untrusted | refused: certificate has expired | Valid, signingCredential.untrusted |
| `anchor-not-yet-valid` | Trusted | Valid, signingCredential.untrusted | refused: certificate is not yet valid or the system clock is incorrect | Valid, signingCredential.untrusted |
| `anchor-ca-false` | Valid, signingCredential.untrusted | Valid, signingCredential.untrusted | refused: invalid CA certificate CN=Matrix Anchor (anchor-ca-false), O=c2pa-veri | Valid, signingCredential.untrusted |
| `anchor-no-basic-constraints` | Valid, signingCredential.untrusted | Valid, signingCredential.untrusted | refused: invalid CA certificate CN=Matrix Anchor (anchor-no-basic-constraints), | Valid, signingCredential.untrusted |
| `anchor-no-key-usage` | Valid, signingCredential.untrusted | Valid, signingCredential.untrusted | refused: CA cert does not include key usage extension | Valid, signingCredential.untrusted |
| `anchor-ku-no-cert-sign` | Valid, signingCredential.untrusted | Valid, signingCredential.untrusted | refused: invalid CA certificate CN=Matrix Anchor (anchor-ku-no-cert-sign), O=c2 | Valid, signingCredential.untrusted |
| `anchor-pathlen-0` | Valid, signingCredential.untrusted | Valid, signingCredential.untrusted | refused: path length constraint exceeded | Valid, signingCredential.untrusted |
| `anchor-critical-unknown-ext` | Valid, signingCredential.untrusted | Valid, signingCredential.untrusted | refused: unhandled critical extension | Valid, signingCredential.untrusted |
| `anchor-sha1-self-signed` | Trusted | Trusted | OK | Trusted |
| `anchor-rsa1024` | Trusted | Trusted | OK | Trusted |
| `leaf-serial-negative` | Trusted | Trusted | OK | Invalid, signingCredential.invalid |
| `leaf-serial-zero` | Trusted | Trusted | OK | Invalid, signingCredential.invalid |
| `int-serial-negative` | Trusted | Trusted | OK | Valid, signingCredential.untrusted |

The last three were added on 2026-10-09 (step 302, SPEC-061 amendment 2)
with the same command and these probe names. Their serials are set, not
random: `-0x0FDB19DB89FA0E`, `0`, and `-0x0FDB19DB89FA0F` on the
intermediate. This verifier refuses them by SPEC-015 amendment 7 (RFC 5280
§4.1.2.2).
