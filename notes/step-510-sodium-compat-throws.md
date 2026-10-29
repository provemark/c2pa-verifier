# Step 510 — A bad Ed25519 signature is a mismatch under sodium_compat too

*2026-10-29.* Finding C2 of the security review before 0.10.0, a crash.
SPEC-009 amendment 6, approved before the test was written.

## The finding

C2PA allows EdDSA with Ed25519 (C2PA 2.4 §13.2). The verifier checks it
with `sodium_crypto_sign_verify_detached()`, else with OpenSSL (SPEC-009
AC8). On a host without ext-sodium, WordPress loads sodium_compat, a PHP
polyfill that defines that function — the shared hosting this verifier
is for. Where the extension returns `false` for a bad signature, the
polyfill throws `SodiumException` for some: a non-canonical S gives "S < L
- Invalid signature". `SignatureVerifier::ed25519()` did not catch it, nor
did the `Verifier`. Anyone with an Ed25519 certificate of their own can
make such a file. No wrong `Valid` — a throw is not a `true` — but no
report either.

## What was measured

- The reviewer's probe before the change, with sodium_compat 1.21.1 in
  place of the extension, an Ed25519 certificate and a signature of 64
  `ff` bytes or of random bytes: `verify()` throws `SodiumException: S <
  L - Invalid signature`.
- `tests/Support/sodium-throws-probe.php`, run through `PHP_BINARY` as
  SPEC-054's probe is, puts in place a sodium function that throws as the
  polyfill does (and the polyfill's class and constant where the
  extension is absent), and verifies AC8's EdDSA vector with 64 `ff`
  bytes as its signature. On `5219a5c`: `{"called":1,"thrown":
  "SodiumException",…}`. After: `{"called":1,"result":false}`. A process
  of its own, because the replacement would stand in for the extension in
  every later test of the process. The old `SignatureVerifier` against
  the final test once more: fails.
- The reviewer's probe after: `Invalid`, `claimSignature.mismatch`, for
  both signatures.
- SPEC-009's 18 tests pass. The corpus before (`5219a5c`, worktree,
  `vendor/` copied) and after: 2,168 runs, none differs.
- `composer check` exit 0 (1,691 passed, 1 skipped), `vendor/bin/pest
  --parallel` exit 0, `bin/package-check.php` classifies every path.

## What was done

A throw from the sodium function is a `false`, so
`claimSignature.mismatch`: what the extension answers for the same
signature, since libsodium refuses a non-canonical S too. The OpenSSL
branch below already read a throw as a refusal. Where ext-sodium is
loaded nothing moves: it does not throw on a signature of the right
length, which is checked before the call.
