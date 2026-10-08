# Step 275 — SPEC-014 amendment 5 built; the DigiCert fixture becomes DigiCert's root

*2026-10-08. The fix for step 273's wrong `Trusted`.*

## The fix

`ChainCheck::checkCertificates()` passes the time the leaf is judged at
(a trusted timestamp's `genTime`, else now) to `issuerFault()` for an
anchor as well as for an x5chain intermediate. The explanation names
which it was: *"the trust anchor SPEC-014 Anchor Probe Root Expired is
not valid at … (valid from 2020-01-01T00:00:00Z to 2021-01-01T00:00:00Z)"*.
About ten lines in one file.

`tests/Unit/Trust/AnchorValidityTest.php`: the six red tests of step 273
are green.

## What the fix moved, and why the DigiCert fixture changed

Every file under `tests/Fixtures` (729) was verified under no settings and
under each of the 63 settings files below `tests/Fixtures` (45,927 runs),
before the fix and after it, with a scratch script that records the state
and a hash of the report with today's date masked.

After the fix alone, two reports moved beyond the five probes, both
`c2pa-rs` files under the four settings files that hold the DigiCert
anchor:

- `boxhash.jpg`, stamped 2022-06-15, and `exp-test1.png`, stamped
  2022-07-29, both by `DigiCert Timestamp 2022 - 2`.
- The fixture `digicert-trusted-root-g4.pem` was the `DigiCert Trusted
  Root G4` cross-certificate issued by `DigiCert Assured ID Root CA`,
  valid from 2022-08-01. Judged at `genTime`, it was not yet valid: both
  TSAs became `timeStamp.untrusted`, and `exp-test1.png`'s signer was
  judged at now and `expired`. Its verdict stayed `Invalid`.
- `openssl verify -x509_strict -partial_chain -attime 1655314608` with that
  certificate as the only CA and the token's certificates as untrusted:
  "error 9 at 2 depth lookup: certificate is not yet valid".
- `c2patool` 0.28.1 says `timeStamp.trusted` for `boxhash.jpg` under
  `full`, `full-plus-digicert-g4` and `ec-root-only` alike: it does not
  need a DigiCert anchor at all (ADR-0004). It is no oracle for this.

Two tests failed on it: SPEC-017 AC10 (the anchor un-expires
`exp-test1`) and SPEC-031 AC7 (the legacy settings and their twin give
the same JSON).

Maurice van Loon chose to use DigiCert's self-signed root in the fixture,
the root `docs/trust-settings.md` tells users to configure (valid
2013-08-01 to 2038-01-15, SHA-256
`55:2F:7B:DC:F1:A7:AF:9E:6C:E6:72:01:7F:4F:12:AB:F7:72:40:C7:8E:76:1A:C2:03:D1:D9:D2:0A:C8:99:88`,
fetched from `cacerts.digicert.com`). Same subject and key. The PEM block
was replaced in the six settings files that embed it; nothing was
re-signed and no `c2patool` answer changed. SPEC-017 amendment 7,
SPEC-031 amendment 4 and SPEC-029 amendment 3 record it, confirmed the
same day.

## Measured after the fix and the fixture change

- Corpus: the only verdicts that move are SPEC-014 AC12's five probes,
  `Trusted` → `Valid`. Every report under a DigiCert settings file is
  equal to the one before the fix.
- `bin/fuzz.php 20261005 60`, before (the fix stashed) and after: 12,903
  runs over 249 files, 0 faults, the same 122 suspects, `Valid` 122.
- `composer check`: 918 passed; PHPStan, Deptrac, Pint and the spec check
  clean.

## For users (reasoned)

A user who configured the root from `docs/trust-settings.md` has an
anchor valid since 2013; this amendment changes nothing for them. A user
who configured the cross-certificate gets `timeStamp.untrusted` for
DigiCert tokens from before August 2022.
