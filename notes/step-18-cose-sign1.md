# Step 18 — CoseSign1 (SPEC-008 implemented): the structure, and the one encoder

*2026-09-21. Oracle: the step-16 vectors, the step-17 variants.*

## What was built

`src/Cose/`, the fifth layer, seeing `Cbor` and `Support`:

- `CoseSign1::fromBytes(string, limits…)` — SPEC-006 decodes the box;
  then, in order: tag 18; a list of four; the protected header a byte
  string within its limit; the unprotected header a map; the payload
  `null` — an empty byte string is refused with C2PA 2.4 §13.2.3 in the
  message; the signature a byte string; the protected header decoded to a
  map (an empty byte string is an empty map, RFC 8152 §3); `alg` under 1,
  with the string label `"alg"` named as the fault when that is what was
  found; the chain looked up protected 33, protected `"x5chain"`,
  unprotected 33, unprotected `"x5chain"`, its count and each
  certificate's size checked before the leaf is parsed with
  `openssl_x509_read`; the timestamp taken as `sigTst2` or `sigTst`;
  `otherHeaders` everything but labels 1 and 33.
- `sigStructure(string $claimBytes)` — `84`, the text `Signature1`, the
  protected bytes as stored, an empty byte string, the claim bytes; every
  head in shortest form through one `head()` of six lines. The only CBOR
  this verifier encodes.
- `CoseException`.

## Measured

- Red: 12 tests on the missing class (`edad933`).
- First run: 8 passed, 3 failed, 1 warning — all three failures on the
  test side or on a reading of the spec: AC5's expected prefix is 18
  bytes, the test cut 20; AC4/AC11 read together fix what `otherHeaders`
  is (everything but labels 1 and 33; the deprecated `"x5chain"` stays
  visible, used or not) — the first implementation had excluded the label
  that was *used*; AC11's test had forgotten the unprotected `pad`.
- The warning: `openssl_x509_read()` emits an E_WARNING on the broken
  leaf. That warning *is* the answer; `isX509()` takes it through a
  scoped error handler and drains OpenSSL's error queue so a later call
  does not report it — no `@`.
- PHPStan: six `chr()` findings (an unbounded int); the heads now use
  `pack('C', …)`. Then `composer check` → exit 0: spec-check `OK: 9
  spec(s), 9 test file(s)`, Pint passed, PHPStan `No errors`, Deptrac 0
  violations, Pest **132 passed (730 assertions)**.

## What the layer now proves

For all four fixtures the `Sig_structure` this code builds is the one
step 16 verified against the real signatures with `ext-openssl` — same
length, same first bytes, same SHA-256 — and it ends with the claim bytes
SPEC-007 hands over. SPEC-009 will close the loop by verifying the
signatures over exactly these bytes in `src/`; nothing in this layer
touches a key.

## Reasoned, not measured

- The 4-GiB and 8-byte branches of `head()`: never reached by a claim; a
  claim above 64 MiB is refused by the containers long before.
- That draining `openssl_error_string()` after a failed `x509_read` is
  needed: OpenSSL keeps a thread-local error queue; a stale entry would
  surface on the next unrelated `openssl_*` failure. Not observed, done on
  principle.
