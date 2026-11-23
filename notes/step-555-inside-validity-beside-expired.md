# Step 555 — `claimSignature.insideValidity` is not reported beside an expired signer

*2026-11-22.* The first of the status-code moves planned on 2026-11-10 for
the `c2patool` 0.29 round, offered one by one. Maurice approved SPEC-039
amendment 3 with "ja akkoord".

## What moved upstream

On 2026-09-24 SPEC-039 copied `c2patool`: `claimSignature.insideValidity`
wherever the signature verifies, an expired signer included (open
question 1). The specification ties the code to validity (C2PA 2.4 §15.8,
2.5 §14.8). Since `c2pa` 0.91.11 (`c2patool` 0.28.6), `verify_internal`
in `claim/claim_verify.rs` no longer logs it when the same signature
carries `claimSignature.outsideValidity` or `signingCredential.expired`.
In `c2pa` 0.92.0 (`c2patool` 0.29.0) that is unchanged. Upstream and the
specification now agree.

## Measured first: what still differs from 0.29.0

This verifier was run over the 930 media files of step 549 without
settings and with `trust/full.settings.json`. Its codes were compared with
0.29.0's answers from that step, on runs where both give the same state
and this verifier reaches the signature. Three moves since 0.27.22:

| move upstream | differing here | outcome |
|---|---|---|
| `insideValidity` withheld beside `expired` | 26 runs, 13 files | this step |
| `claimSignature.validated` withheld on a refused credential (`invalid`, `revoked`, `expired`, `outsideValidity`, `algorithm.unsupported`) | 80 runs, 40 files | proposed as the next step |
| `signingCredential.ocsp.skipped` for an unusable staple | none where the signature is reached | already followed |

The rest of `ocsp.skipped`'s differences fall into two groups, both named
earlier:

- files this verifier refuses before the signature;
- the revocation probes, whose staple this verifier does use (SPEC-030,
  SPEC-066).

## The rule

SPEC-039 amendment 3:

- `claimSignature.insideValidity` is reported where the signature
  verifies, unless `signingCredential.expired` is reported on the same
  `c2pa.signature` url. This verifier has no `claimSignature.outsideValidity`.
- It holds for the active manifest and for every ingredient manifest.
- The time judged does not change. A signer kept in time by a trusted
  time-stamp is not `expired`, and keeps the code.

## Built

- `ClaimSignatureCheck::withinValidity()` withdraws the code once the
  certificate checks have run. `Verifier::check()` and
  `IngredientManifestCheck::manifest()` call it.
- `signingCredential.expired` comes only from `CertificateProfileCheck`,
  which runs before the call.

## Tests

`InsideValidityTest`:

- **AC2** is rewritten: `profile/expired.png` has `expired`, no
  `insideValidity`, and stays `Invalid`. `c2patool` 0.29.0's answer is
  recorded as `inside-validity/profile-expired-no-settings--0.29.0.json`.
- **AC4** reads 0.29.0's deltas (`DRIFT_ORACLE`). A delta holds the code
  exactly when it holds `validated` and not `expired`. An expired
  ingredient signer is among them (`c2pa-rs/ocsp.jpg`).
- **AC7** is new, the control: `tsa-matrix/expired-signer-trusted-tsa.png`
  under its own settings keeps the code and is `Trusted`. 0.29.0's answers
  are recorded with and without settings.

Against the old code, AC2 and AC4 were red and the other five green (AC7
included, as a control should be). After the change, 7 of 7 are green.

**Mutation.** Each call of `withinValidity()` was removed in turn:

- without the call in `Verifier`, AC2 goes red;
- without the call in `IngredientManifestCheck`, AC4 goes red.

## Corpus, before and after

The tree at `2c9f656` was given its own copied `vendor/` in a worktree.
Both trees ran over the 932 media files at 2026-11-22T12:00:00Z: without
settings, with `full.settings.json`, and with the probe's own settings
where one exists. That is 1,991 runs a side.

68 runs on 30 files differ, and only by the removed code. No verdict
moves, and no other code.

Against 0.29.0's answers (the 1,860 runs of step 549):

- **28 runs come closer.**
- **22 runs move further away.** These are the Truepic, Bing, Amazon and
  Lightroom files. `c2patool` trusts their DigiCert and Truepic TSAs
  without an anchor, so their signers stay in time there. Here the TSA is
  untrusted, so the signer is judged at now and is `expired`, as before.
  The code follows that existing, named difference (a TSA trusted only
  through the configured anchors, `_TSA_NOT_CONFIGURED`).
- **6 runs moved but still differ.** These are `ocsp*` and `exp-test1`,
  from the same cause.

**Checks.** `composer check` exit 0, and `composer test:parallel` too: 1,776 passed, 1 skipped;
217 symbols. `bin/spec-check.php` reports OK.

## What changed

- `src/Cose/ClaimSignatureCheck.php`: `withinValidity()`, a comment.
- `src/Verifier/Verifier.php`, `src/Verifier/IngredientManifestCheck.php`:
  one call each.
- `specs/SPEC-039-claim-signature-inside-validity.md`: amendment 3, AC2
  and AC4 rewritten, AC7 new, open question 1 marked, Traceability.
- `tests/Unit/Cose/InsideValidityTest.php`.
- Fixtures: 0.29.0 answers for `profile/expired.png` and for
  `expired-signer-trusted-tsa` (with and without settings).
- `docs/comparison.md`: the row for open question 1 removed, and the
  0.28.6 and 0.29.0 paragraphs updated.
- `CHANGELOG.md`: *Unreleased*.

## Next (for Maurice's word)

`claimSignature.validated` withheld on a refused credential, as SPEC-010
amendment 7. `c2pa` does not count `signingCredential.untrusted` as a
refusal, though C2PA 2.4 §15.7 rejects the claim for it too. That choice
is his.
