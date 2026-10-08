# Steps 283–284 — The trust matrix, and the first thing it found

*2026-10-08. The first step of the consolidation after 0.5.1: no new
features for two weeks, and trust checked systematically instead of by
the right question at the right time.*

## Why

Most of the wrong `Trusted` verdicts this project has found sat in the
chain and trust checks, and each was found because someone asked a
specific question. The corpus holds real files with valid chains; the
fuzzer runs without trust anchors. Neither reaches the place.

## 283 — The generator and the first measurement

`bin/make-trust-matrix.php` builds one valid chain, leaf ← intermediate ←
anchor (P-256, SHA-256, the C2PA leaf profile), and 41 variants in which
one property of one certificate differs: validity (expired, not yet
valid), basicConstraints, keyUsage, extended key usage, a critical
unknown extension, the issuer's digest (SHA-1), and the key (P-384,
RSA-2048, RSA-1024, Ed25519). Each probe is `fixture-signed.png`'s store
with its claim re-signed by the probe's leaf, the COSE_Sign1 replaced at
its own length, so no signer's checks decide what can be built. Throw-away
keys, deleted at the end. Each probe is judged by `c2patool` 0.27.22 and
0.28.1, by `openssl verify -x509_strict -partial_chain`, and by
`bin/c2pa-verify`, under settings holding only the probe's anchor.

Against `c2patool` 0.28.1, on 42 probes:

| outcome | probes |
|---|---|
| the same verdict | 38 |
| stricter here, by design | `leaf-eku-time-stamping` (step 152's rule), `int-sha1` (SPEC-048) |
| **more lenient here** | **`int-no-key-usage`, `anchor-no-key-usage`** |

One difference in codes, not in verdict: for an expired or not yet valid
leaf, 0.28.1 adds `signingCredential.untrusted` to
`signingCredential.expired`; 0.27.22 and this verifier do not. The state
is `Invalid` in all three.

## 284 — SPEC-014 amendment 7

SPEC-014 amendment 4 (step 148) asked an issuing certificate for
`keyCertSign` only *when keyUsage is present*. An intermediate or an
anchor without the extension could issue: `Trusted` here; `Valid` with
`signingCredential.untrusted` in both `c2patool` versions; OpenSSL
"CA cert does not include key usage extension". RFC 5280 §4.2.1.3
requires the extension. `c2pa-rs` 0.91.1's OpenSSL check sets
`X509_V_FLAG_X509_STRICT`, where the refusal comes from (read).
None of the 53 anchors the plugin bundles lacks keyUsage (measured).

Amendment 7, confirmed by Maurice van Loon the same day: every issuing
certificate carries keyUsage with `keyCertSign`. AC13, with three probes
made by the generator's fixture mode under `tests/Fixtures/trust/key-usage/`
and both `c2patool` versions' answers.

`tests/Unit/Trust/KeyUsageIssuerTest.php` before the change: 3 failed,
1 passed (the control), each failure `signingCredential.trusted` where
`untrusted` was expected. After: green; `composer check` 927 passed.

Measured around the change: every file under `tests/Fixtures` (735)
under no settings and 67 settings files (49,245 runs): the only verdicts
that move are the two probes, `Trusted` → `Valid`; under other anchors
`int-no-key-usage`'s explanation changes and its verdict does not. The
fuzz seed 20261005: 12,903 runs, 0 faults, the same 122 suspects before
and after. The whole matrix again: the only differences from 0.28.1 left
are the two stricter-by-design probes.

## Next

The matrix is not yet a test. Its probes and their recorded answers
become SPEC-061 (a drift alarm over the matrix), and the timestamp
authority's chain gets a matrix of its own.
