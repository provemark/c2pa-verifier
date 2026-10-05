# Step 247 — A review of the whole of `src/` before 0.3.0; the fixes' tests, red

*2026-10-05. The release commit for 0.3.0 was pushed and green (step 246);
before the tag, Maurice asked for one more review, of all the code rather
than the diff since 0.2.9.*

## What the review found, and what was measured here

A code review of `src/` as a whole kept nine findings. Each was checked
here before anything was decided; one was refuted by the specification.

| # | finding | checked | result |
|---|---|---|---|
| 1 | a NaN or infinity in any assertion made the report throw | **measured**: `fixture-signed.jpg`, `c2pa.created` replaced by four half-floats; `bin/c2pa-verify` exit 255, PHP's fatal error and a stack trace with absolute paths on stderr; `c2patool` 0.27.22 and 0.28.1: a decode error | real |
| 2 | ES256 under a P-384 key is accepted | **read**: C2PA 2.4 §13.2.1, *"Implementations shall accept keys on any of these curves for all ECDSA algorithm choices"* | **refuted**; SPEC-009 AC6 already says so |
| 3 | a name-constraint comparison folded every non-UTF-8 name to `''` | **measured**: a throw-away hierarchy (`bin/make-name-encoding-variants.php`), a leaf outside a T61String constraint: **`Trusted` here**; `c2patool` 0.28.1 `Valid` + `signingCredential.untrusted`; OpenSSL *permitted subtree violation* | **a wrong `Trusted`, in every release since 0.2.5** |
| 4 | under an `id-RSASSA-PSS` key any salt length passed | **measured**: `openssl_verify` returns 1 for salt 0, 32 and 64; RFC 8230 §2 fixes it at the hash length | real |
| 5 | `c2pa.hash.data__1` was not a hard binding | **measured** on two new variants with both `c2patool` versions: alone, verified there (`dataHash.match`), `claim.hardBindings.missing` here; beside `c2pa.hash.data`, `multipleHardBindings` there, skipped here | real; and reasoned from the code: on the BMFF route no other binding was counted at all |
| 6 | `verify()` on a pipe printed a PHP warning before its exception | **measured** | real |
| 7 | `docs/comparison.md`: two rows out of date | read | real |
| 8 | the active COSE is decoded seven times | read | performance only; later |
| 9 | the `storeReached` rewrap copied five times; a stale docblock | read | refactor later; the docblock now |

Two measurements needed a second try. The generator's own check caught that
PHP's `escapeshellarg()` drops bytes that are not UTF-8 under a UTF-8
locale: the first leaf carried the wrong name; the script now sets
`LC_CTYPE` to `C`. The first `hard-binding-instance` failed its data hash
in `c2patool` too: the store had grown by six bytes and its exclusion had
not; it does now, and both versions say `dataHash.match`.

For 4, the fix had to be found first: PHP gives no modulus for an
`id-RSASSA-PSS` key and refuses raw RSA on it (*operation not supported
for this keytype*). The same public key read as `rsaEncryption` (the SPKI's
algorithm identifier replaced, the key bits unchanged) runs SPEC-009's own
EMSA-PSS check: salt 32 `true`, salt 0 and 64 `false` (measured).

## Decided (Maurice van Loon)

"akkoord, volg je advies: alles in 0.3.0": 1, 3, 4, 5, 6 and 7 before the
tag; 2 is not built (the specification says the opposite); 8 and 9 later.

## Amendments (approved) and tests (red)

| spec | amendment | AC | test, red because |
|---|---|---|---|
| SPEC-007 | 6 | AC15: a non-finite float rendered as `"NaN"`, `"Infinity"`, `"-Infinity"` | `JsonException` |
| SPEC-019 | 2 | AC13: such a file gets its report, exit 1 | `JsonException` |
| SPEC-043 | 2 | AC12: a pipe refused without a PHP warning | one warning |
| SPEC-009 | 3 | AC12: salt = hash length under an `id-RSASSA-PSS` key | salt 0 `true` |
| SPEC-012 | 9 | AC11: hard bindings known by base label, counted before any is checked | `claim.hardBindings.missing`; the second skipped |
| SPEC-046 | 1 | AC7: a non-UTF-8 name compared byte for byte | `Trusted` |

`vendor/bin/pest`: 7 failed, 792 passed. One test was first red for a
wrong reason (`toContain()` reads a second argument as a second needle,
not a message) and was corrected before this count.

New fixtures: `tests/Fixtures/name-encoding/`, two variants in
`tests/Fixtures/binding/`, two vectors in `tests/Fixtures/signatures/`
(`bin/make-signature-vectors.php` now takes names and makes only those),
and the `c2patool` answers under `tests/Fixtures/c2patool/{name-encoding,hash-instance}/`.
No private key reaches the repository.
