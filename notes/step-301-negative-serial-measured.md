# Step 301 — Negative and zero certificate serial numbers, measured

*2026-10-09. Measurement only; no change to `src/` or to the generators.*

Step 299's fuzzing found that a negative certificate serial number is
converted wrongly. RFC 5280 §4.1.2.2 says the serial "MUST be a positive
integer". This step measures what each judge does with a certificate that
breaks that rule.

## The trust matrix

`bin/make-trust-matrix.php` was given a serial per certificate and three
probes, run in its scratch mode: a leaf with serial `-0x0FDB19DB89FA0E`,
a leaf with serial `0`, and an intermediate with serial
`-0x0FDB19DB89FA0F`. OpenSSL 3.6 issues all three
(`openssl x509 -req -set_serial`). The change to the generator is not
committed in this step, because SPEC-061 AC6 asks a fixture for every
probe the generator defines. It comes with step 302, with the fixtures and
an amendment.

| probe | `c2patool` 0.27.22 | 0.28.1 | `openssl verify` | this verifier |
|---|---|---|---|---|
| control | `Trusted` | `Trusted` | OK | `Trusted` |
| leaf serial negative | `Trusted` | `Trusted` | OK | `Trusted`, with a PHP deprecation |
| leaf serial zero | `Trusted` | `Trusted` | OK | `Trusted` |
| intermediate serial negative | `Trusted` | `Trusted` | OK | `Trusted`, with a PHP deprecation |

No state differs. What differs:

- **`cert_serial_number`** of the negative leaf: here `4463028754577934`
  (the magnitude; the sign lost). `c2patool` 0.28.1 says
  `67594565283350002`, the content octets `F0 24 E6 24 76 05 F2` read as
  unsigned. The value is `-4463028754577934`; `openssl_x509_parse()`
  gives it as `serialNumber`.
- **The deprecation** goes to stdout when `display_errors` is on, and then
  the CLI's JSON cannot be parsed. The matrix's `error` cell for this
  verifier came from that.

## A stapled OCSP response

`bin/make-ocsp-variants.php` builds its responses with `openssl ocsp` and
a throw-away CA. In a scratch directory the same was done for a leaf with
serial `1` and a leaf with serial `-0x0FDB19DB89FA0E`, each with a
`revoked` (keyCompromise) response, and `OcspCheck::check()` was called
at its seam, as SPEC-030's tests do:

- serial 1: `signingCredential.ocsp.revoked`;
- negative serial: `signingCredential.ocsp.skipped`, "response 0 could
  not be read: INTEGER at offset 107 is negative".

`Der::integer()` refuses a negative INTEGER unless asked for a signed one
(serials, versions and counts are never negative; the RFC 3161 nonce is
read signed, SPEC-016 amendment 3). So the response is set aside as
unreadable, before the serials are compared. A certificate that broke RFC
5280 §4.1.2.2 and was then revoked would be reported `Trusted` here, with
the revocation skipped. No full signed file with such a stapled response
was built, so `c2patool`'s answer to it is not measured.

## Checked

No code changed. `composer check`: 945 passed.
