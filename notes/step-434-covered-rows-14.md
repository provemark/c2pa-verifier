# Step 434 — The covered rows of §14, each broken once

*2026-10-25.* Step 3 of the audit "Before 2.5: is all of 2.4 there?",
continued from steps 431–433, now outside §15: the 35 covered rows of §14
(trust model, credentials, certificate profile, OCSP). No code changes:
tests, fixtures and the reading.

## How

As in steps 431 and 432: one exact replacement in `src/` per mutation,
the cited tests run, the whole suite when they stay green, the file
restored and `git status src` checked. For the mutations no test caught,
the whole fixture corpus was verified with and without the mutation, each
file under the settings file of its own directory. Trust depends on the
settings, so a run with no settings would show almost nothing. 48
mutations covered 23 rows. The other 12 name no code of their own (the
user may add anchors; the library has no store of its own; a CA's EKU is
ignored), restate a row that was mutated already, or were broken in step
431 (the binding, the claim signature, an anchor carried in `x5chain`).

## What was measured

| result | mutations |
|---|---|
| red in the test the row cites | 38 |
| red only in other tests: citation fixed | 3: label 33 before `"x5chain"`, an untrusted ingredient signer beside a trusted one, the allowed list kept out of the TSA's settings |
| green everywhere | 7 |

The seven, and what became of them:

| rule | what was done |
|---|---|
| §14.4.3: an allowed-list entry issues nothing | `ChainCheckTest` "AC3: a CA certificate in the allowed list trusts no certificate it issued": the trust matrix's `control` with its anchor only in the allowed list is `Valid`, `signingCredential.untrusted`. `c2patool` 0.28.1 and 0.28.3 give the same with that certificate as their `allowed_list` (`tests/Fixtures/c2patool/allowed-list/`) |
| §14.5.1.1: RSASSA-PSS parameters above the leaf | trust matrix probe `int-pss-mgf1-sha384`: `Valid`, untrusted here; `Trusted` in `c2patool` 0.27.22 and 0.28.1, which check the profile on the leaf only. Named in `SPEC061_STRICTER` |
| §14.5.1.1: version 3 above the leaf | trust matrix probe `int-version-2`: untrusted here and in both `c2patool` versions; OpenSSL refuses it ("Using cert extension requires at least X509v3") |
| §14.5.1.1: RSA of at least 2048 bits on the leaf | the test was there, but it looked for "1024", which the §13.2.1 algorithm check also writes for the same key. `CertificateProfileCheckTest` AC2 now looks for the §14.5 explanation |
| §14.5.1.1: an OCSP responder carries OCSPSigning | `bin/make-ocsp-responder-variants.php` (throw-away keys, deleted): `no-eku.der`, a responder the CA issued without OCSPSigning, is skipped |
| §14.5.2 (RFC 6960 §4.2.2.2): the responder is the issuer's | `stranger.der`, a self-signed responder with OCSPSigning, is skipped; `delegated.der`, the CA's responder with OCSPSigning, is believed (revoked). `OcspCheckTest` AC4 |
| §14.5.2 (RFC 6960 §4.2.1): an unsuccessful response | `revoked.der` with its status set to tryLater and its response bytes kept is skipped. `OcspCheckTest` AC4, no new file |

Each of the seven was seen red under its mutation after the change, and
green without it. The two matrix probes are SPEC-061 amendment 6 (tests
only, awaiting confirmation). `composer check`: 1,360 passed, 1 skipped.

## What was reasoned

That the remaining twelve rows need no mutation of their own: each either
names no code or is held by a mutation listed in this step or in step 431.

## Not done

The covered rows outside §14 and §15 (about 160).
