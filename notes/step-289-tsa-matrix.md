# Steps 289–290 — The timestamp matrix, and a TSA off the profile

*2026-10-09. The second step of the consolidation: the timestamp
authority's side of trust, as step 283 did the signer's.*

## 289 — The generator and the first measurement

`bin/make-tsa-matrix.php` builds one valid signer chain and one valid
timestamp authority chain (TSA leaf ← intermediate ← anchor, the leaf with
the critical timeStamping EKU), and probes in which one thing about the
timestamp differs. Each probe is the PNG fixture's store with its version 2
claim re-signed by a throw-away signer and an RFC 3161 token from `openssl
ts -reply` in the unprotected header (`sigTst2`, over the CounterSignature
structure of C2PA 2.4 §14.6). The unprotected header is not signed, so the
token is added after the claim is signed. Throw-away keys, deleted.

`openssl ts -reply` refuses a TSA leaf whose extended key usage is anything
but the critical timeStamping alone (measured on four variants), so the
EKU variants need a token signed another way and are not in this round.

Three probes have a signer valid for 2.5 minutes, stamped at once, and are
judged after it has expired: the only way to measure a timestamp that keeps
an expired signer valid, since `openssl ts` stamps the current time.

The settings hold the signer's root as a `"manifest"` entry and the TSA's
root as a `"tsa"` entry. `c2patool` 0.27.22 does not read `trust.anchors`
(SPEC-031), so 0.28.1 is the oracle here.

Against 0.28.1, on 22 probes:

| outcome | probes |
|---|---|
| the same verdict | 19: control, no timestamp, the TSA leaf expired or not yet valid (`timeStamp.outsideValidity`), an RSA TSA, every intermediate and root variant, the TSA not configured, the legacy string, the token over the wrong bytes (`timeStamp.mismatch`), `sigTst`, both headers, and the three expired-signer probes |
| **more lenient here** | 3: a TSA leaf with no keyUsage, with `CA:TRUE`, signed over SHA-1 — `Trusted` here, `Invalid` in 0.28.1 |

The expired signer: `Trusted` with a trusted TSA, here and in 0.28.1;
`Invalid` (`signingCredential.expired`) with an untrusted TSA or none.

Code differences where the state agrees: the TSA's root only as a
`"manifest"` entry gives `timeStamp.untrusted` here and `trusted` in 0.28.1
(SPEC-031, by design); both headers give `timeStamp.malformed` here where
0.28.1 reads `sigTst2` (SPEC-016, stricter); 0.28.1 adds
`signingCredential.untrusted` to an expired signer, as in the trust matrix.

## 290 — SPEC-017 amendment 8

Read in `c2pa-rs` 0.91.1: `verify_time_stamp` checks the TSA's leaf with
`check_end_entity_certificate_profile`, which logs every fault as
`SIGNING_CREDENTIAL_INVALID`, and appends that log to the manifest's
(`crypto/time_stamp/verify.rs`, line 553). It does so under
`verify_timestamp_trust`, which `c2pa-rs` turns off for a version 1 claim.
Measured: 0.28.1 says `Invalid` for the three probes with and without
settings, and with `verify_trust: false`.

Amendment 8, Maurice van Loon's choice (follow `c2patool`), confirmed the
same day: for a version 2 claim, every fault the TSA leaf has against the
profile is reported as `signingCredential.invalid` as well as
`timeStamp.untrusted`, the explanation naming the timestamp authority. A
version 1 claim is unchanged. With `verify_trust: false` this verifier
still checks no TSA; named in `docs/comparison.md`.

AC14 with four probes from the generator's new fixture mode
(`tests/Fixtures/timestamp/tsa-profile/`), and both `c2patool` versions'
answers with and without settings. `tests/Unit/Timestamp/TsaProfileTest.php`
before the change: 4 failed, 1 passed (`Trusted` where `Invalid`; the
`claimVersion` parameter unknown). After: 5 passed, once two expected words
were corrected to the profile check's own (`no KeyUsage extension`,
`ecdsa-with-SHA1`). `composer check`: 936 passed.

Measured around the change: 781 files under no settings and 113 settings
files: the only verdicts that move are the three probes, under every
settings file; no real file moves. The fuzz seed: 12,903 runs, 0 faults,
the same 122 suspects before and after.

## On the way: helper names again

`composer check` stopped on PHPStan: `make-tsa-signer-variants.php` already
defines `tsRun()` and its siblings, the prefix this generator had taken.
Renamed to `tx…`; PHPStan clean on macOS and in Docker `php:8.3-cli`. The
third time in two days a global helper name in `bin/` collided (steps 286
and this one); a test that refuses two `bin/` scripts defining the same
function would end it.
