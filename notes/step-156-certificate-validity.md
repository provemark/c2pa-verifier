# Step 156 — A certificate's validity is read from its own DER (SPEC-044)

*2026-09-25. Found while measuring whether the verifier runs in PHP
compiled to WebAssembly, for a demo page. The measurement found one fault
that only php-wasm has, and one that every PHP has. The second made an
expired certificate `Trusted` in 0.1.0 to 0.2.2.*

## Where the time came from

`Certificate` took a certificate's validity window from
`openssl_x509_parse()`: the fields `validFrom_time_t` and `validTo_time_t`.
PHP fills them in C, in `php_openssl_asn1_time_to_time_t()`
(`ext/openssl/openssl_backend_common.c`). The time string OpenSSL passes
through (`validFrom`, `validTo`) is right; the number PHP makes of it is
not always.

## 156a — php-wasm

Measured with `@php-wasm/node` 3.1.55 (PHP 8.3.33, OpenSSL 1.1.1t). It
has `openssl` and `mbstring`, not `sodium`. `bin/c2pa-verify` was run
over 107 fixture runs (`matrix/` with and without its trust settings,
`binding/`, `public-testfiles/`, `bmff/`) and compared with native PHP
8.5.8, byte for byte:

- 98 reports were identical;
- the six Ed25519 runs are `algorithm.unsupported`, because there is no
  `sodium`. That fails closed and is not a fault;
- the three `truepic-20230212-*` runs had the same codes, but the
  validity times in the explanation were an hour early.

The first certificate of `trust/es256_certs.pem` starts at
`Jun 10 18:46:40 2022 GMT` (`openssl x509 -noout -startdate`). php-wasm's
`validFrom_time_t` for it:

| host timezone (`TZ=`) | as UTC |
|---|---|
| `UTC` | 18:46:40 |
| `Europe/Amsterdam` | 16:46:40 |
| `America/New_York` | 22:46:40 |

PHP reports `UTC` as its own timezone in every row. `gmmktime()`,
`gmdate()` and `time()` are right under all four timezones tried, and
`PHP_INT_SIZE` is 8. So only the two fields were wrong, and
`Der::time()`, which already reads the timestamp's and OCSP's times, can
read the validity instead.

## 156b — the variants, and the fault every PHP has

`bin/make-spec044-variants.php` makes a throw-away root and six leaves
whose validity is written by hand, re-signs the PNG fixture with each,
and records both `c2patool` versions' answers
(`tests/Fixtures/validity/README.md` has the table). The control,
`resigned`, is `Trusted` in both versions, so the rewriting is right.

`expired-fraction` has notAfter `20250101000000.5Z`. RFC 5280 §4.1.2.5.2
forbids the fraction. Both `c2patool` versions call it
`signingCredential.expired`. This verifier, 0.2.2, called it `Trusted`.
`openssl_x509_parse()` on native PHP 8.5.8:

| notAfter | `validTo_time_t` as UTC |
|---|---|
| `20250101000000Z` | 2025-01-01 00:00:00 |
| `20250101000000.5Z` | 2500-12-31 00:00:00 |
| `20240101000000.99Z` | 4010-09-30 00:01:39 |
| `20251231235959.999Z` | 1969-12-31 23:59:59 |

php-wasm gives the same 2500-12-31. PHP's source explains it (read, not
traced): the function reads the fields at fixed places counted back from
the end of the string, as if every time ended in `…SSZ`, so a fraction
shifts every field. It then converts with `mktime()` and corrects by
`tm_gmtoff`, which is where php-wasm's host timezone can leak in.
php/php-src#21545 (open) reports a different wrong `validTo_time_t`, for
far-future dates.

To exploit it, a CA must issue a certificate that breaks RFC 5280. That is
unlikely, but the verdict was wrong, and this project's rule is that a
wrong `Trusted` is the one failure that counts.

Two more measurements changed the spec (amendment 1, confirmed by Maurice
van Loon: drop the fraction):

- `no-seconds` (notBefore `2401010000Z`) is refused by OpenSSL 3.6
  ("utctime is too short"), so AC4 was green before the change. OpenSSL
  1.1.1 in php-wasm reads it; `Der::time()` then refuses it. Both
  `c2patool` versions read it: `Valid`, `untrusted`.
- `fraction` (notAfter `20500101000000.5Z`, not expired): 0.27.22 says
  `Trusted`, 0.28.0 does not trust it. The fraction is dropped here, as
  0.27.22 does and as ADR-0005 asks.

## 156c — the change

`Certificate::validity()` reads `tbsCertificate.validity` with
`DerReader` and `Der::time()`; an `Asn1Exception` becomes a
`TrustException`. ADR-0003 has amendment 1 for it. Nothing else in
`Certificate` changed.

`vendor/bin/pest --group=SPEC-044` before the change: AC1 red
(1654879600, not 1654886800) and AC6 red (16756675200, not 1735689600);
AC2, AC3 and AC4 green. After it: 5 passed. AC2 compares 73 certificates
(every PEM fixture and every `matrix/` `x5chain`) with native OpenSSL and
finds them equal. `composer check`: exit 0, 530 tests.

**AC5, by hand.** The 107 runs again, php-wasm against native, under
`TZ=UTC`, `Europe/Amsterdam` and `America/New_York`: 101 identical in
each, and the six that differ are the Ed25519 runs. In php-wasm the six
variants give `Trusted`, `Trusted`, `Invalid` (`signingCredential.invalid`),
`Trusted`, `Invalid` (`expired`), `Invalid` (`expired`) — the same as
native.

## What is not done

- The fault is not reported to PHP or to WordPress Playground (php-wasm).
  That is the maintainer's decision, after a release.
- php-wasm is not in CI. The harness lives outside the repository.
