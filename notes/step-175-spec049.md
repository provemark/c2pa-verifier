# Step 175 — SPEC-049 built: an RSA key needs a real public exponent

*2026-09-28. SPEC-049, approved the same day with open question 1
decided as B (leaf and path) and three amendments confirmed, built
tests-first.*

## What the tests found before the code

The first version of the leaf tests altered `['rsa']['e']` in the key
details PHP reports. It failed before reaching the rule: the corpus's
PS256 leaf (`matrix/ps256.jpg`) has an `id-RSASSA-PSS` key, and for
such a key `openssl_pkey_get_details()` returns no `['rsa']` details at
all (PHP 8.5.8, OpenSSL 3.6.3: `type` −1, only `bits`, `key` and
`type`). A count over the corpus's JPEG, PNG and WebP leaves: 45
`id-RSASSA-PSS` keys, 7 `rsaEncryption`. The spec as approved would have
refused all 45 as having an unreadable exponent, and would never have
seen the exponent of the key type C2PA signers use most. Amendment 3 moved
the reading to the key's own subjectPublicKeyInfo.

## What was built

- `src/Trust/RsaExponent.php` (`@internal`): `fromSubjectPublicKeyInfo()`
  reads `RSAPublicKey ::= SEQUENCE { modulus, publicExponent }` from the
  BIT STRING with the project's own DER reader (SPEC-016), the same for
  `rsaEncryption` and `id-RSASSA-PSS`; `fault()` refuses an absent or empty
  exponent, a negative one, a value below 3 and an even value, and says
  which (RFC 8017 §3.1).
- `Certificate::$rsaExponent`, read from the details' `key` for an RSA key.
- `CertificateProfileCheck::keyFaults()`: the leaf's exponent beside its
  modulus, `signingCredential.invalid`.
- `ChainCheck::pathFault()`: every certificate between the anchor and the
  leaf, `signingCredential.untrusted` naming the certificate and the
  exponent. The timestamp authority's chain uses the same walk.

## Measured

- `vendor/bin/pest --group=SPEC-049` before the code: 14 failed, 2 passed.
  The rule's tests failed because the class did not exist; the leaf tests
  because no explanation named an exponent; AC6 because a chain whose
  intermediate reports `e = 1` or `e = 2` came out
  `signingCredential.trusted`. The two guards (`e = 65537` trusted; three
  real RSA files keep their verdicts) passed.
- After: 16 passed. `composer check`: exit 0, 569 passed; PHPStan level max
  and Deptrac clean.
- **23,352 runs, before and after**: every media fixture (417) with no
  settings and with each of the 55 readable settings files, the verdict and
  every status code with its URL. No line moved, no exception. No
  certificate in the corpus, TSA chains included, has an exponent below 3
  or an even one.

## What no test covers

A real certificate with `e = 1` refused end to end. The call-site tests
alter one field of a real certificate's reported key while the real key
verifies every signature (amendment 2 and 3); that the leaf or intermediate
would otherwise reach `Trusted` is step 174's measurement. This is how
c2pa-rs 0.91.1 tests its own rule, on the function alone.

## Disclosure

A wrong `Trusted` closed in every release up to 0.2.5 (step 174). Local
with step 174 until the release that carries it.
