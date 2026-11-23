# Step 558 — A leaf outside its validity makes the chain untrusted

*2026-11-23.* This is the fourth status-code move of the `c2patool` 0.29
round. It was found while building step 557. Maurice approved SPEC-014
amendment 12 with "ja akkoord".

## What upstream does

`check_certificate_trust` in `c2pa` 0.92.0
(`crypto/cose/certificate_trust/rust_native.rs`) checks every certificate
of the chain at the time judged. That time is a trusted time-stamp's
`genTime`, or else now. The OpenSSL path sets the same time. A leaf that is
not valid then gives `CertificateNotTrusted`, so
`signingCredential.untrusted`, even when the chain reaches an anchor. The
profile check reports `signingCredential.expired` beside it.

The recorded answers date the move. For `trust/chain-matrix/leaf-expired`,
0.27.22 gave `expired` only, while 0.28.1 and 0.29.0 give `untrusted` and
`expired`. The trust matrix (SPEC-061) and the time-stamp matrix (SPEC-062)
named this as a code difference.

## The rule

SPEC-014 amendment 12:

- The walk here leaves the leaf's validity to the profile check
  (SPEC-015).
- Where the profile check reported `signingCredential.expired` and the
  walk gave `signingCredential.trusted`, `ChainCheck::withoutRefused()`
  (step 557) puts `signingCredential.untrusted` in its place. The
  explanation names the leaf's validity.
- Where no anchor was reached, `untrusted` is there already.
- With `verify_trust` false there is no walk and no trust code.
- A leaf kept in time by a trusted time-stamp is not `expired`, and stays
  `trusted`.

## Tests

`tests/Unit/Trust/TrustedRefusalTest.php`, SPEC-014 AC20–AC23:

- **AC20:** `trust/chain-matrix/leaf-expired.png` under its own settings,
  and `profile/expired.png` under its root. Both have `expired` and
  `untrusted`, and no `trusted`. The 0.29.0 answer for the second is
  recorded.
- **AC21:** the control. `expired-signer-trusted-tsa` under its own
  settings stays `Trusted`.
- **AC22:** with `verify_trust: false`, `expired` only, as 0.29.0 says.
  That answer is recorded too.
- **AC23:** the rule on hand-made lists, per url.

Against the old code, AC20 and AC23 were red, and the two controls were
green. After the change, all are green. AC19's `expired` case moved to
AC23, so AC19 keeps the `invalid` case.

**Mutation.** With the replacement switched off, AC20 and AC23 go red.

## Named differences that close

The full suite turned three tests red, each rightly:

- **SPEC-061 AC4:** `leaf-expired` and `leaf-not-yet-valid` agreed with
  0.28.1 while they were still named in `SPEC061_CODES_DIFFER`. They leave
  the list (SPEC-061 amendment 7).
- **SPEC-062 AC4:** `expired-signer-untrusted-tsa`,
  `expired-signer-no-timestamp` and `expired-signer-two-tokens` leave
  `SPEC062_CODES_DIFFER` for the same reason (SPEC-062 amendment 6).
- **SPEC-015 AC10:** it compares with the 0.27.22 answers, and now applies
  amendment 12 to them as well (SPEC-015 amendment 12 extended).

## Corpus, before and after

The tree at `e21cc29` was given its own copied `vendor/`. Each tree ran
1,991 runs. 14 runs differ, all under their own settings, and only by an
added `signingCredential.untrusted`:

- 8 in the active manifest: `leaf-expired`, `leaf-not-yet-valid`, and
  the `expired-signer-*` probes of the time-stamp matrices;
- 6 in an ingredient: the expired parent of the `timestamp/assertion`
  probes.

No verdict moves.

Against 0.29.0 under the same settings, 12 of the 14 agree. One of the
other two has no answer from 0.29.0. The other, `update-other-label`,
already has a different state there (the named time-stamp-assertion
difference), and its ingredient is `expired` here.

**Checks.** `composer check` exit 0. `composer test:parallel`: 1,790
passed, 1 skipped. 217 symbols. `bin/spec-check.php` reports OK.

## Not done

- A CA certificate outside its validity is `claimSignature.outsideValidity`
  plus `untrusted` in `c2pa`. Here it is `untrusted` (AC12).
- `leaf-ku-cert-sign`, `int-no-ski` and `profile-ed25519/leaf-spki-params`
  are `untrusted` in 0.29.0 for reasons in the walk itself. They stay
  named.

## What changed

- `src/Trust/ChainCheck.php`: `withoutRefused()` replaces in place.
- Specs:
  - SPEC-014 amendment 12, AC20–AC23, Traceability;
  - SPEC-061 amendment 7;
  - SPEC-062 amendment 6;
  - SPEC-015 amendment 12 extended.
- Tests:
  - `TrustedRefusalTest.php`;
  - `TrustMatrixTest.php`, `TsaMatrixTest.php` (the lists);
  - `CertificateProfileCheckTest.php` (the helper).
- Fixtures: `c2patool/profile/expired--0.29.0.json` and
  `expired-verify-off--0.29.0.json`; two README updates.
- `docs/comparison.md`, `CHANGELOG.md`.
