# Step 303 — Every fixture under the settings that make it Trusted

*2026-10-09. Tooling, and a finding for the next step; no change to `src/`.*

The corpus run of step 302 showed that 157 fixtures are `Trusted`
unmutated under at least one settings file. `bin/fuzz.php --trust` started
from 61. A mutation can only make a wrong `Trusted` out of a file that is
`Trusted` to begin with, so the rules that carry a `Trusted` were reached
for a third of the files that have one: the actions rules, redactions,
icons, chain constraints, the public test files and more.

## What changed

With `--trust` and no files named, the fuzzer pairs every fixture that has
a manifest with the first settings file under which it is `Trusted`
unmutated. It tries the file's own `<name>.settings.json` first, then the
settings files in its folder, then `trust/*.settings.json`. A file that
none makes `Trusted` keeps step 295's pairing. The summary reports how long
the pairing took. Without `--trust` nothing changes: seed 20261005 × 60
gives the same 238 suspects by name.

## Measured

| run | pairs (unmutated) | runs | faults | raised | suspects |
|---|---|---|---|---|---|
| `20261005 60 --trust` | 224 (157 `Trusted`, 23 `Valid`, 44 `Invalid`) | 11,484 | 0 | 0 | 534 |
| `20261009 200 --trust` | the same 224 | 38,280 | 0 | 0 | 1,760 |

The pairing took 9 s. Every suspect was judged by `c2patool` 0.28.1 under
the same settings, on the state and the `timeStamp.*` codes; the
plain-text ones by the text-enabled build, with `--text` here. Where
`c2patool` could not read a file (6 and 16), this verifier says `Invalid`.

- Seed 20261005: 534 judged, none more lenient here.
- Seed 20261009: 1,760 judged, **two more lenient here.**

A second run of seed 20261005, after a type fix in the pairing loop, gave
the same suspects by name.

## The finding: the parameters of the leaf's RSASSA-PSS algorithm

Both cases are `unprot8` mutations of `public-testfiles/adobe-20220124-CAI.jpg`
and `adobe-20220124-CII.jpg` (Adobe's 2022 test files, whose `x5chain` is
unprotected), under `trust/anchors-no-config.settings.json`. Unmutated,
both are `Trusted` here and in 0.28.1. After the mutation:

- `c2patool` 0.28.1: `Invalid`, `signingCredential.invalid` ("certificate
  algorithm error") and `signingCredential.untrusted`. 0.27.22 is
  `Invalid` too.
- This verifier: `Valid`, `signingCredential.untrusted` ("the signature of
  C2PA Signer does not verify under Intermediate CA").

`openssl asn1parse` places one flip in each leaf in the RSASSA-PSS
parameters of the algorithm that signed it. In CAI it falls in the MGF1
hash OID of the outer `signatureAlgorithm`; in CII, in the hash OID of the
same field and of tbsCertificate's `signature`. The leaf's key is
untouched, so the claim signature still verifies. `c2pa-rs` refuses a
certificate whose signature algorithm it cannot read. This verifier's
profile (SPEC-015) checks the algorithm's name (`rsassaPss`) and a weak
hash (SHA-1, MD5) but not an unknown hash in its parameters, so the leaf
is only untrusted.

## Checked

`composer check`: 949 passed. PHPStan in Docker `php:8.3-cli`: no errors.
