# Step 422 — An ECDSA algorithm under a key on another curve (SPEC-009 amendment 5)

*2026-10-25.* The third of three probes for changes the corpus does not
cover (step 419), and the open question of step 416. Maurice approved
measuring ("akkoord, meet probe 3") and then the amendment, reporting
`signingCredential.invalid` only ("akkoord, amendement 5, alleen
signingCredential.invalid").

## The question

C2PA 2.4 §13.2.1: *"Implementations shall accept keys on any of these
curves for all ECDSA algorithm choices."* C2PA 2.5 §12.3.1 pairs P-256
with ES256, P-384 with ES384, P-521 with ES512, and says *"Validators may
reject other pairings."* SPEC-009 AC6 accepted a crossed pairing, and step
416 kept it, on the letter of 2.4. What `c2patool` does with a signed file
carrying one had never been measured.

## Measured

`bin/make-ecdsa-pairing-variants.php` (new): a throw-away P-256 root signs
one signer per probe on the probe's curve, and the PNG fixture's claim is
re-signed with the probe's algorithm, R‖S at the curve's size. Keys in
scratch, deleted after the run. `openssl dgst -verify` accepts all six
signatures.

| probe | 0.27.22 | 0.28.1, 0.28.2, 0.28.3 | `c2pa-web` 0.15.3 | here, before | here, after |
|---|---|---|---|---|---|
| `es256-p256`, `es384-p384`, `es512-p521` | `Trusted` | `Trusted` | `Trusted` | `Trusted` | `Trusted` |
| `es256-p384` | `Invalid` | `Invalid` | `Invalid` | **`Trusted`** | `Invalid` |
| `es384-p256` | `Invalid` | `Invalid` | `Invalid` | **`Trusted`** | `Invalid` |
| `es512-p384` | `Invalid` | `Invalid` | `Invalid` | **`Trusted`** | `Invalid` |

With the root as anchor; without settings each `Trusted` is `Valid`.
0.27.22 reports `claimSignature.mismatch`; 0.28.x and `c2pa-web` report
`signingCredential.invalid` (*"certificate key algorithm does not match
COSE signing algorithm"*) and `claimSignature.mismatch`. `c2pa-web` ran
through a scratch copy of `tools/web-oracle/run-web.mjs` in headless
Chrome; no network request was made.

## Read in `c2pa-rs`

- `spki_matches_signing_alg()` in `sdk/src/crypto/cose/verifier.rs`
  (PR 2612, 2026-09-09, first in `c2pa` 0.91.0) ties each ECDSA algorithm
  to its curve and logs `signingCredential.invalid`. It runs in the SDK,
  before either crypto back end, so `c2pa-web` refuses too.
- Before it, the OpenSSL back end of `c2pa_raw_crypto` read a signature as
  R‖S only when its length is the algorithm's (64, 96, 132 bytes); a
  P-384 signature under ES256 is 96 bytes, was read as DER, and failed.
  That is 0.27.22's `claimSignature.mismatch`.
- My expectation before measuring, that `c2pa-web`'s Rust-native back end
  would accept a crossed pairing, was wrong: it never gets that far.

Step 416's "AC6 stands" was therefore lenient against every `c2patool`
measured. No signature was forged (the holder's key signed), but this
verifier said `Valid` where `c2patool` says `Invalid`, which ADR-0005
rules out.

After the fixtures were made, PHPStan found the script's helper names
(`ecRun()` and three more) already declared, differently, in
`bin/make-exclusion-coverage-variants.php`; they became `epRun()` and so on.
The renamed script was run again in scratch with 0.28.3: the same six
answers, here and there.

## The change

`SignatureVerifier::requireFit()` refuses an ECDSA algorithm under a key on
another of the three curves, with `signingCredential.invalid`, naming the
key and C2PA 2.5 §12.3.1. `claimSignature.mismatch` is not added: the
signature is sound. The state equals `c2patool`'s; the code list has one
code fewer.

The synthetic vector `tests/Fixtures/signatures/es256-p384.json` now
expects `exception`; only its `expect` and `purpose` changed (and its
hash in that directory's README), and `bin/make-signature-vectors.php`
says the same.

## Tests and corpus

- AC6 (restated) and AC13 (new, `EcdsaPairingTest`) red first: AC6 on the
  vector's `expect`, AC13 with "Failed asserting that two strings are
  identical" (`Trusted`, not `Invalid`); green after the change.
- The corpus (`corpus-norm241.php`, no settings and
  `trust/full.settings.json`) before and after, the new fixtures copied
  into the before tree: 1,906 runs each; only the six runs of the three
  crossed probes moved.

## What changed

- `src/Cose/SignatureVerifier.php` (`PAIRED_CURVES`, `requireFit()`).
- `bin/make-ecdsa-pairing-variants.php` (new), `tests/Fixtures/ecdsa-pairing/`,
  `tests/Fixtures/c2patool/ecdsa-pairing/` (0.28.2 and 0.28.3);
  `tests/Fixtures/signatures/es256-p384.json` and its README row;
  `bin/make-signature-vectors.php`.
- SPEC-009 amendment 5, AC6 restated, AC13; `docs/reading-c2pa-2.4.md`,
  `docs/comparison.md`, `docs/milestones.md` (the 2.5 waiting list).
- Not yet in `CHANGELOG.md`: that is written in a release commit.

## Next

The three probes are done. Steps 421 and 422 change verdicts and are not
released.
