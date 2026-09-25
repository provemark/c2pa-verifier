# SPEC-044: A certificate's validity is read from its own DER, in UTC

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

`Certificate` takes a certificate's validity window from OpenSSL:
`openssl_x509_parse()`'s `validFrom_time_t` and `validTo_time_t`. On every
native PHP measured so far those are UTC epochs. On PHP compiled to
WebAssembly they are not. There, the conversion uses the timezone of the
host that runs it, which in a browser is the visitor's timezone.

**Measured** (2026-09-25, `@php-wasm/node` 3.1.55, PHP 8.3.33, OpenSSL
1.1.1t; native PHP 8.5.8, OpenSSL 3.6.3), on the first certificate of
`tests/Fixtures/trust/es256_certs.pem`. `openssl x509 -noout -startdate`
gives `Jun 10 18:46:40 2022 GMT`:

| runtime, host timezone | `validFrom_time_t` as UTC |
|---|---|
| native, UTC or `Europe/Amsterdam` | 2022-06-10T18:46:40Z |
| php-wasm, `UTC` | 2022-06-10T18:46:40Z |
| php-wasm, `Europe/Amsterdam` | 2022-06-10T16:46:40Z (2 h early) |
| php-wasm, `America/New_York` | 2022-06-10T22:46:40Z (4 h late) |

PHP's own `date_default_timezone_get()` reports `UTC` in every row, so this
is not a PHP setting that a caller can correct.

Over 107 fixture runs of `bin/c2pa-verify`, php-wasm and native PHP gave
byte-identical reports except for two groups:
- the six Ed25519 files, which have no `sodium` and so fail closed with
  `algorithm.unsupported`;
- the three `public-testfiles/truepic-20230212-*` files. Their codes are
  the same, but the validity times in the `signingCredential.expired`
  explanation are one hour early.

The validity window decides four checks:
- the signer's validity (C2PA 2.4 §14.5, `CertificateProfileCheck`);
- an intermediate's validity (`ChainCheck`);
- the TSA signer's validity at `genTime` (C2PA 2.4 §14.6, `TimestampCheck`);
- the same for an OCSP responder.

A window shifted by up to fourteen hours makes an expired certificate valid
for those hours. That is a wrong `Valid`, the one failure this project
exists to prevent (README, *Fail closed*). In CI and on shared
hosting, the runtimes this verifier targets, the time is right today. It is
right by accident of the runtime, not by the verifier's own reading.

The verifier already reads DER time correctly. `Der::time()` (SPEC-016)
reads UTCTime and GeneralizedTime as RFC 5280 §4.1.2.5 defines them, and
converts with `gmmktime()`. The timestamp's `genTime` and every OCSP time
already go through it. **Measured**: `gmmktime()`, `gmdate()` and `time()`
in php-wasm give the same result under the host timezones `UTC`,
`Europe/Amsterdam`, `America/New_York` and `Asia/Tokyo`. `PHP_INT_SIZE` is
8, and `gmmktime()` returns 2040-01-01 and 9999-12-31 correctly.

This departs from ADR-0003, decision 1. That decision takes every X.509
field from `openssl_x509_parse()`, because "nothing must be" parsed by
hand. That held when it was measured on native PHP only. ADR-0004 has since
given the verifier its own DER reader for RFC 3161. This spec uses that
reader for one field of the certificate, the validity, because OpenSSL's
epoch for it depends on the runtime. Every other field, and every signature
check, stays with OpenSSL. ADR-0003 gets an amendment that says so.

## Scope

**In scope**

- `Certificate::$validFrom` and `$validTo` are read from the certificate's
  own `tbsCertificate.validity` (RFC 5280 §4.1.2.5) through `Der::time()`,
  not from `openssl_x509_parse()`.
- A validity that `Der::time()` refuses makes the certificate unreadable
  (`TrustException`), as an unparsable certificate is today.
- The `fromParsed()` test seam keeps working. The parse data it is given no
  longer decides validity.
- An amendment to ADR-0003, decision 1: validity is the one X.509 field
  read by the own DER reader (ADR-0004), with the measurement above as the
  reason.

**Out of scope** (each needs its own spec before it may be built)

- A php-wasm job in CI. The runtime comparison is measured by hand and
  recorded in the step note.
- The demo page that motivated this measurement.
- Ed25519 without `sodium` (it already fails closed).
- Enforcing RFC 5280's split between UTCTime (to 2049) and GeneralizedTime
  (from 2050). See the open questions.

## Behavior

- **AC1 — validity does not depend on OpenSSL's epoch**
  - Given the first certificate of `tests/Fixtures/trust/es256_certs.pem`,
    built through `Certificate::fromParsed()` with parse data whose
    `validFrom_time_t` and `validTo_time_t` are shifted by −7200 s (what
    php-wasm returns under `Europe/Amsterdam`)
  - When `validFrom` and `validTo` are read
  - Then they are 1654886800 (2022-06-10T18:46:40Z) and 1914000400
    (2030-08-26T18:46:40Z), what `openssl x509 -noout -startdate -enddate`
    prints. They are not the shifted values.

- **AC2 — the same values as before on native PHP**
  - Given every certificate in the repository's PEM fixtures and in the
    `x5chain` of every `matrix/` file
  - When each is built with `Certificate::fromDer()`
  - Then `validFrom` and `validTo` equal native `openssl_x509_parse()`'s
    `*_time_t`. This is the guard that moving the reading changed nothing
    where the old reading was right.

- **AC3 — both DER forms are read**
  - Given a certificate whose notBefore is a UTCTime and whose notAfter is
    a GeneralizedTime, e.g. `99991231235959Z`, which RFC 5280 §4.1.2.5
    reserves for "no well-defined expiration date"
  - When it is read
  - Then `validTo` is 253402300799.

- **AC4 — a validity that is not DER time is refused** *(error path)*
  - Given a certificate whose notBefore is a UTCTime without seconds
    (`2206101846Z`), valid BER that DER and RFC 5280 §4.1.2.5.1 forbid,
    re-signed so that OpenSSL reads it
  - When it is read
  - Then `Certificate` throws `TrustException`, and a file signed with it
    is `Invalid` with the code the chain or profile check gives an
    unreadable certificate today. It is never `Valid`.

- **AC5 — the report is the same in php-wasm** *(measured, not in CI)*
  - Given the three `public-testfiles/truepic-20230212-*` files and the
    harness from the step note, under host timezones `UTC`,
    `Europe/Amsterdam` and `America/New_York`
  - When `bin/c2pa-verify` runs in php-wasm and in native PHP
  - Then the reports are byte-identical. Over the 107 runs, only the six
    Ed25519 runs still differ.

## References

- Specification: RFC 5280 §4.1.2.5 (Validity), §4.1.2.5.1 (UTCTime),
  §4.1.2.5.2 (GeneralizedTime); C2PA 2.4 §14.5 (signer certificate),
  §14.6 (time-stamps).
- Oracle: native PHP 8.5.8 with OpenSSL 3.6.3 (`openssl_x509_parse()`),
  and `openssl x509 -noout -startdate -enddate` for AC1 to AC3. For AC5,
  `@php-wasm/node` 3.1.55 (PHP 8.3.33, OpenSSL 1.1.1t) against native.
- Reasoned: that the shift comes from Emscripten's time conversion in
  OpenSSL's `ASN1_TIME` → `time_t` path (PHP's `asn1_time_to_time_t()`
  calls `mktime()`, which follows the host). This was read, not traced. The
  measurement above does not depend on it.

## API sketch

Illustrative only.

```php
// namespace Provemark\C2paVerifier\Trust;
// final readonly class Certificate — unchanged public shape

// in the constructor, replacing the two *_time_t lines:
[$this->validFrom, $this->validTo] = self::validity($der);

/** tbsCertificate.validity (RFC 5280 §4.1.2.5) as UTC epochs, through Der::time(). */
private static function validity(string $der): array // {int, int}
{
    // Certificate ::= SEQUENCE { tbsCertificate, … }
    // tbsCertificate ::= SEQUENCE { [0] version OPTIONAL, serialNumber,
    //   signature, issuer, validity SEQUENCE { notBefore, notAfter }, … }
    // an Asn1Exception becomes a TrustException
}
```

The class docblock says that everything comes from OpenSSL and nothing is
parsed by hand. It gets one exception, named with its reason.

## Open questions

- **GeneralizedTime with fractions.** `Der::time()` accepts
  `…46.5Z` because RFC 3161 allows it. RFC 5280 §4.1.2.5.2 forbids it in a
  certificate. Should a certificate with a fraction be refused (stricter
  than OpenSSL)? Proposal: refuse, since this is a certificate and RFC 5280
  is clear. Measure `c2patool` first. Not a blocker.
- **UTCTime / GeneralizedTime split** (2049/2050). Enforcing it would be
  stricter than OpenSSL. Proposal: do not enforce. It protects against no
  wrong `Valid`, and ADR-0005 then says to follow `c2pa-rs`. Not a blocker.
- **AC4's expected code.** Which code `c2patool` gives for a signer
  certificate that fails to parse is to be measured in the test step. The
  AC fixes only that it is not `Valid`.

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
