# Step 556 — `claimSignature.validated` is not reported on a refused credential

*2026-11-23.* This is the second status-code move of the `c2patool` 0.29
round. Maurice approved SPEC-010 amendment 7 with "ja akkoord, volg
c2pa-rs".

## What moved upstream

- **The specification.** C2PA 2.4 §15.7 (2.5 §14.7) assigns
  `claimSignature.validated` only *"if the claim has not yet been
  rejected"*.
- **`c2pa`.** Since 0.91.11 (`c2patool` 0.28.6), `verify_internal` in
  `claim/claim_verify.rs` logs the code only when the signature carries
  none of `SIGNING_CREDENTIAL_REJECTIONS`:
  - `signingCredential.invalid`
  - `signingCredential.ocsp.revoked`
  - `signingCredential.expired`
  - `claimSignature.outsideValidity`
  - `algorithm.unsupported`
- **`signingCredential.untrusted` is left out on purpose** (*"An untrusted
  credential does not count here"*).
- The rule is unchanged in `c2pa` 0.92.0 (`c2patool` 0.29.0).

In step 549's 0.29.0 answers:

- `invalid` (70 runs) and `expired` (26 runs) never stand beside the code;
- neither does `ocsp.revoked` (`revocation/cs-own.png` under its root);
- `untrusted` stands beside it on 1,083 runs.

## The choice

§15.7 says that an untrusted chain rejects the claim too. Following the
text would take the code from every file without trust settings. Then a
`Valid` file would carry no signature success code, and the reports would
no longer compare with `c2patool`. Maurice chose to follow `c2pa`: `untrusted`
is not a refusal here. `docs/comparison.md` names the difference from the
text.

## Built

- `ClaimSignatureCheck::withRefusals()` withdraws the code on a signature
  url that carries one of the four refusals this verifier has (it has no
  `claimSignature.outsideValidity`).
- `Verifier::check()` calls it after the revocation check, because
  `ocsp.revoked` only appears there.
- `IngredientManifestCheck::manifest()` calls it after its own revocation
  block.

## Tests

`tests/Unit/Report/ValidatedRefusalTest.php`, SPEC-010 AC11–AC15:

- **AC11:** `profile/eku-any.png` (`invalid`) and `profile/expired.png`
  (`expired`). The 0.29.0 answers are recorded for `eku-any` with and
  without its root.
- **AC12:** `revocation/cs-own.png` (`ocsp.revoked`).
- **AC13:** the multi-manifest files against 0.29.0's deltas. The expired
  ingredient signer of `c2pa-rs/ocsp.jpg` is among them.
- **AC14:** the control. `fixture-signed.png` is untrusted, keeps the
  code, and is `Valid`.
- **AC15:** the rule on a hand-made list, per url, for each refusal.

Against the old code, AC11, AC12, AC13 and AC15 were red (the last one
because the method did not exist yet). AC14 was green, as a control should
be. After the change, 5 of 5 are green.

**SPEC-039 AC4.** It went red with this change, rightly. Beside `invalid`,
`insideValidity` now stands without `validated`, as in 0.29.0. SPEC-039
amendment 4 rewords AC4 as two implications: never beside `expired`, and
beside every `validated`.

**Mutations:**

- Without the call in `Verifier`, AC11 and AC12 go red.
- Without the call in `IngredientManifestCheck`, AC13 goes red.
- With the call moved before the revocation check, AC12 goes red, so the
  order is guarded too.

## Corpus, before and after

The tree at `f855a52` was given its own copied `vendor/` in a worktree.
Both trees ran over the 932 media files at 2026-11-22T12:00:00Z: without
settings, with `full.settings.json`, and with the probe's own settings
where one exists. That is 1,991 runs a side.

166 runs on 68 files differ, and only by the removed code: 164 in the
active manifest, 2 in an ingredient only. No verdict moves, and no other
code.

Against 0.29.0's answers (step 549's 1,860 runs):

- **80 runs come closer.**
- **50 runs move further away.** Each is a file where this verifier refuses
  a credential that `c2patool` accepts, for a reason already named in
  `docs/comparison.md`:
  - the TSA that is not trusted without an anchor, which makes the signer
    `expired` (Truepic, Bing, Amazon, Lightroom, `ocsp*`, `exp-test1`);
  - the stricter certificate profile (PSS with SHA-1, serial numbers zero,
    negative or 257 bits);
  - `x5chain` as text;
  - the revocation probes whose staple this verifier uses.

  The code follows this verifier's own refusal.

**Checks.** `composer check` exit 0. `composer test:parallel` exit 0,
1,781 passed, 1 skipped. 217 symbols. `bin/spec-check.php` reports OK.

## Found, not done here

Under its anchor, `c2patool` 0.29.0 also omits `signingCredential.trusted`
on a refused credential (`profile/eku-any.png`, `revocation/cs-own.png`).
This verifier reports it. That is a third move, one that `full.settings.json`
does not show because it anchors neither probe. It is proposed as its own
step.

## What changed

- `src/Cose/ClaimSignatureCheck.php`: `withRefusals()`.
- `src/Verifier/Verifier.php`, `src/Verifier/IngredientManifestCheck.php`:
  one call each.
- `specs/SPEC-010-report-claim-signature.md`: amendment 7, AC11–AC15,
  Traceability.
- `specs/SPEC-039-claim-signature-inside-validity.md`: amendment 4, AC4.
- `tests/Unit/Report/ValidatedRefusalTest.php` (new),
  `tests/Unit/Cose/InsideValidityTest.php`.
- Fixtures: `c2patool/profile/eku-any--0.29.0.json`,
  `eku-any-no-settings--0.29.0.json`, and the README.
- `docs/comparison.md`: the paragraphs, and a row for `untrusted`.
- `CHANGELOG.md`: *Unreleased*.
