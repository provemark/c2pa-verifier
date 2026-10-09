# Step 296 — Fuzzing the COSE unprotected header

*2026-10-09. Tooling only; no change to `src/`.*

The claim signature covers the COSE protected header and the claim, not the
unprotected header (RFC 9052 §4.4; C2PA 2.4 §14.2). The unprotected header
holds the RFC 3161 timestamp (`sigTst2`, or `sigTst` for a version 1 claim,
C2PA 2.4 §14.6) and, in older files, `x5chain`. A token carries its own CMS
signature, but the certificate set in it is not signed. A change there
leaves the file's hash and the claim signature intact, and reaches only the
trust and timestamp rules. Random mutations (step 295) almost never land
there.

## What changed

With `--trust`, `bin/fuzz.php` has two more kinds, `unprot1` and `unprot8`.
They flip one or eight bits inside the values of the active manifest's
unprotected header: each timestamp token, and each `x5chain` certificate
when the chain is unprotected. The values are taken from
`CoseSign1::ofManifest()` and found in the file by their bytes. A value
split across container segments is not found; it is skipped and counted.

A file that stays `Trusted` after such a flip is often right: its signer is
valid now and needs no timestamp. So for these two kinds a suspect is a run
that still reports `timeStamp.validated` or `timeStamp.trusted` (the changed
token accepted), or that stays `Valid` or `Trusted` after a flip in an
unprotected `x5chain`. `RAISED` stays as in step 295.

The new kinds join the rotation only with `--trust`. Without it the runs
are unchanged: `php bin/fuzz.php 20261005 60 <out>`, 12,903 runs, the same
122 suspects. With `--trust` the same seed gives other mutations than in
step 295.

## Measured

| run | runs | faults | raised | suspects |
|---|---|---|---|---|
| `20261005 60 --trust` | 6,030 | 0 | 0 | 90 (66 from `unprot`) |
| `20261009 200 --trust` | 20,100 | 0 | 0 | 337 |

45 of the 120 pairs have an unprotected value the fuzzer could reach. One
value was split across JPEG segments and skipped.

Every suspect was judged by `c2patool` 0.28.1 with the same settings, on
the state and the `timeStamp.*` codes. The one plain-text suspect was
judged by the text-enabled build, with `--text` here. Of 427 judged, none
is more lenient here: no higher state, and no `timeStamp.trusted` where
0.28.1 does not report it. The differences are all stricter here:

- **A changed certificate of the TSA's chain inside the token** (the TSA
  leaf or its intermediate): `timeStamp.untrusted` here. Seven of the eight
  files are version 1 claims, for which `c2patool` judges the timestamp
  differently (step 290); on version 2 claims both agree. Where the signer
  has expired, this verifier then judges it now (ADR-0004) and says
  `Invalid`.
- **A changed copy of the root inside the token**: `untrusted` here,
  `trusted` in 0.28.1, which takes the anchor from the settings and leaves
  the token's copy aside.
- **An unreadable certificate in an unprotected `x5chain`**: `Invalid` here
  (`signingCredential.invalid`, the certificate cannot be read), `Valid` or
  `Trusted` in 0.28.1 when the broken one is the root or an intermediate it
  does not need. Fail closed, as SPEC-014 asks.
- `C_with_CAWG_data.jpg` is `Invalid` here before any mutation (its
  `cawg.identity` credential is refused unseen), as in the corpus.

The 9 suspects equal in both verifiers with `timeStamp.trusted` after a
flip are flips in the token's copy of the DigiCert root. Neither verifier
uses that copy.

## Checked

`composer check`: 943 passed. PHPStan in Docker `php:8.3-cli`: no errors.
