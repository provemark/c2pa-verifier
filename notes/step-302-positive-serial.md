# Step 302 — A certificate serial number is a positive integer (SPEC-015 amendment 7)

*2026-10-09.*

Step 299's fuzzing found a negative certificate serial, and step 301
measured what it does. Both `c2patool` versions and OpenSSL accept a
negative or zero serial (`Trusted`). Here the serial lost its sign, with a
PHP deprecation. Worse, `Der::integer()` refuses a negative INTEGER, so a
stapled OCSP response about such a certificate could not be read and a
revocation was skipped. Maurice van Loon chose to refuse such serials
(choice A), as RFC 5280 §4.1.2.2 asks ("MUST be a positive integer"),
rather than read them signed.

## What changed

- **`Bytes::hexToDecimal()`** keeps a leading minus sign, and
  **`Bytes::decimalOctets()`** ignores it.
- **`Certificate::$serialPositive`** is new.
- **`CertificateProfileCheck::checkLeaf()`:** a leaf whose serial is not
  positive is `signingCredential.invalid`, naming the serial. This holds
  for a version 2 claim's TSA leaf too, through SPEC-017 amendment 8.
- **`ChainCheck::pathFault()`:** any other certificate of a path that
  reached an anchor with such a serial makes the chain untrusted, beside
  SPEC-048 and SPEC-049.
- **Specs:** SPEC-015 amendment 7 and AC12; SPEC-061 amendment 2.
- **The trust matrix:** `bin/make-trust-matrix.php` gives each certificate
  a serial (random unless set) and has three probes: `leaf-serial-negative`,
  `leaf-serial-zero` and `int-serial-negative`. They were built as
  fixtures in the set `chain-matrix`, with both `c2patool` versions'
  answers, and added to `SPEC061_STRICTER`. The matrix holds 45 probes.
- `docs/comparison.md` (a new stricter case), the folder's README, and the
  CHANGELOG under *Unreleased*.

## Measured

- **Tests first.** `tests/Unit/Trust/SerialNumberTest.php` (AC12, with
  every PHP notice turned into a failure) and the trust-matrix test with
  the three probes named stricter: 4 failed (three `ErrorException`s from
  the deprecation; the stricter list did not match), 3 passed. After the
  change: 8 passed. One expected sentence was corrected to the message's
  own wording ("has serial number …, which is not").
- **The corpus.** 832 files under no settings and 144 settings files,
  before (a worktree of `0ed5f45` with its own `vendor/` and the three new
  fixtures) and after. Of 120,640 runs, 291 moved, all of them the three
  probes. The two leaves went from `Valid` to `Invalid` under every
  settings file, and from `Trusted` to `Invalid` under their own. The
  intermediate went from `Trusted` to `Valid` under its own. No real file
  moved. The old code raised 435 deprecations over the run; the new code
  raised none.
- **The fuzzer.** Seed 20261005 × 60 now has 0 faults and exit 0 (step
  300: 1 fault, exit 1), with the same 238 suspects. With `--trust`, seeds
  20261005 × 60 and 20261009 × 200 (143 pairs, three of them the new
  probes) have 0 faults and 0 raised, with the same suspects by name.
- `composer check`: 949 passed. PHPStan in Docker `php:8.3-cli`: no
  errors.

Not measured: `c2patool`'s answer to a full signed file with a stapled
`revoked` response for a negative-serial leaf. With this change such a
leaf is refused before its revocation matters.
