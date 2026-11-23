# Step 557 — `signingCredential.trusted` is not reported on a refused credential

*2026-11-23.* This is the third status-code move of the `c2patool` 0.29
round. It was found while building step 556. Maurice approved SPEC-014
amendment 11 with "ja akkoord", and decided that the evidence follows the
report.

## What moved upstream

`c2pa` 0.92.0 (`c2patool` 0.29.0) removes `signingCredential.trusted`,
even when the chain reaches an anchor:

- in `crypto/cose/verifier.rs`, when the certificate profile check fails,
  with `signingCredential.invalid` or `signingCredential.expired`
  (*"A certificate that fails the profile check is not a trusted signing
  credential, even when it chains to a trust anchor"*);
- in `claim/claim_verify.rs`, when OCSP finds the credential revoked
  (*"That outranks the certificate chain reaching a trust anchor"*).

Only the profile's own faults count. A `signingCredential.invalid` from a
later check, such as a key that does not fit the algorithm, leaves
`trusted` in place there.

## Measured first

`c2patool` 0.29.0 was run on the 127 probes that carry their own settings.
In 26 runs this verifier reported `trusted` where 0.29.0 did not, each
with a profile fault:

- 16 `Invalid` on both sides, with `invalid`;
- 8 with `expired`;
- the two PSS-SHA1 probes, which are `Valid` there.

Under `trust/full.settings.json`, and without settings, nothing differs,
because these probes are not anchored there. That is why step 549 did not
show this move.

## The rule

SPEC-014 amendment 11:

- `ChainCheck::withoutRefused()` withdraws `signingCredential.trusted` on
  a signature url where `CertificateProfileCheck` reported a fault, or
  where `signingCredential.ocsp.revoked` stands.
- It runs after the revocation check, for the active manifest and every
  ingredient manifest.
- **The evidence follows the report** (Maurice). `evidence()`'s `signer`
  is null where `trusted` was withdrawn (`ChainCheck::trustedIn()`), so
  SPEC-082 AC7 holds unchanged. The revocation source (SPEC-086) still
  gets the anchor the walk reached, because the lookup comes before the
  refusal it may produce.

## Tests

`tests/Unit/Trust/TrustedRefusalTest.php`, SPEC-014 AC15–AC19:

- **AC15:** `profile/eku-any.png` under its root (`invalid`) and
  `trust/chain-matrix/leaf-expired.png` (`expired`). No `trusted`, no
  evidence signer.
- **AC16:** `revocation/cs-own.png` (`ocsp.revoked`).
- **AC17:** `ingredient-staple/child-staple-revoked.png`. Its ingredient
  delta holds `ocsp.revoked` and not `trusted`, as in 0.29.0's recorded
  answer. The multi-manifest corpus is checked as well.
- **AC18:** the control. `trust/chain-matrix/control.png` is `Trusted`,
  with an evidence signer.
- **AC19:** the rule on hand-made lists. An `invalid` that is not the
  profile's takes nothing away.

**Red first.** Against the old code, AC15, AC16, AC17 and AC19 were red,
and the control was green. AC17 was first written over the corpus only,
and was green there. No ingredient under `full.settings.json` has a
refused credential beside `trusted`, so it proved nothing. The
`ingredient-staple` probe makes it red.

**Mutations:**

- Without the call in `Verifier`, AC15 and AC16 go red.
- Without the call in `IngredientManifestCheck`, AC17 goes red.
- With the evidence given the anchor again, AC15, AC16 and SPEC-082 AC7
  go red.

## Other criteria that follow

The full suite turned six tests red, and each follows from the rule:

- **SPEC-082 AC7 ×2:** `signer` without `trusted`. Fixed by the evidence
  following the report.
- **SPEC-015 AC2:** it named `trusted` beside a profile fault. SPEC-015
  amendment 12 rewrites it.
- **SPEC-015 AC10:** it compares with answers recorded before 0.29.0, and
  now applies the rule to them (amendment 12).
- **SPEC-031 AC2:** the Truepic signer is `expired` at now here, because
  its TSA is not configured. It is now judged on 2023-02-13 (SPEC-031
  amendment 6).
- **SPEC-031 AC8:** it applies the rule to answers recorded before 0.29.0
  (amendment 6).

## Corpus, before and after

The tree at `1bff834` was given its own copied `vendor/`. Each tree ran
1,991 runs. 34 runs on 34 files differ, all under their own settings, and
only by `trusted` (28 in the active manifest, 6 in an ingredient only). No
verdict moves, and no other code.

Against 0.29.0 under the same settings, 26 runs come closer and 2 move
further away: `leaf-serial-negative` and `leaf-serial-zero`. This verifier
already refuses those serial numbers, and that difference is named.

**Checks.** `composer check` exit 0. `composer test:parallel`: 1,786
passed, 1 skipped. 217 symbols. `bin/spec-check.php` reports OK.

## Not done

On a leaf outside its validity, 0.29.0 also reports
`signingCredential.untrusted`, because its chain check refuses the expired
leaf. This walk does not. That is a difference in the walk, not a code
withheld, and is open for its own look.

## What changed

- `src/Trust/ChainCheck.php`: `withoutRefused()`, `trustedIn()`.
- `src/Verifier/Verifier.php`, `src/Verifier/IngredientManifestCheck.php`:
  the profile's statuses kept, one call each, and the evidence signer
  conditional.
- Specs:
  - SPEC-014 amendment 11, AC15–AC19, Traceability;
  - SPEC-015 amendment 12 (AC2);
  - SPEC-031 amendment 6.
- Tests:
  - `tests/Unit/Trust/TrustedRefusalTest.php` (new);
  - `CertificateProfileCheckTest.php`, `TrustAnchorsTest.php`.
- Fixtures: 0.29.0 answers for `chain-matrix/control`,
  `chain-matrix/leaf-expired` and `ingredient-staple/child-staple-revoked`,
  plus two README notes.
- `docs/comparison.md`, `CHANGELOG.md`.
