# Step 550 — A TSA off the certificate profile is an untrusted time-stamp, not an `Invalid` file

*2026-11-22.* Step 549 found that `c2patool` 0.29.0 no longer makes a file
`Invalid` when its timestamp authority's leaf fails the certificate
profile. This verifier still did, by SPEC-017 amendment 8, which followed
0.28.1. Maurice approved the amendment with "akkoord, volgen; geen
credentialInvalid; patch 0.11.4".

## The rule

SPEC-017 amendment 12:

- A TSA leaf's profile faults give `timeStamp.untrusted` only. The
  explanation names the certificate and the faults, as before.
- The time-stamp gives no time. The signer is judged at the validation
  time (C2PA 2.4 §15.8.2, 2.5 §14.8.2).
- No `signingCredential.invalid` is added, for any claim version. The
  version 1 exception of amendment 8 goes with the rule it excepted:
  `TimestampCheck::judge()` and `checkHeader()` lose their `$claimVersion`
  parameter. The class is `@internal`, so no contract symbol changes (217).
- `timeStamp.credentialInvalid` is not reported. The specification allows
  it, `c2patool` 0.29.0 does not report it, and it would be a contract
  symbol without an oracle.

SPEC-062 amendment 5 follows from it (confirmed by Maurice the same day). The timestamp matrix's alarm
compares the eight `tsa-leaf-*` probes with 0.29.0, and the other probes
with 0.28.1 as before. `tsa-leaf-eku-email-only` leaves the list of
differing codes, because 0.29.0 agrees with it now. The generator defines
37 probes, two of them for the `tsa-profile` set.

## Read

- **C2PA 2.4 §15.8.2** (2.5 §14.8.2, "Validating the TimeStampToken"):
  - if the TSA certificate cannot be found or chained, the validator
    issues `timeStamp.untrusted` (informational) and ignores the
    time-stamp;
  - for an invalid certificate it *may* add `timeStamp.credentialInvalid`.
- **2.5** adds that ignoring means proceeding *"as if no time-stamp were
  present"*.
- **`c2pa-rs` #3031** (in 0.92.0): *"keep timestamp certificate profile
  failures informational and scoped to the timestamp, without relaxing TSA
  EKU or trust checks"*.

## Built and measured

**Probes.** `bin/make-tsa-matrix.php` gained two variants, run with
0.29.0 and 0.28.6 as its two oracles:

- `expired-signer-tsa-leaf-ca-true`, an `openssl ts` token;
- `expired-signer-tsa-leaf-eku-email-only`, a CMS-signed token.

In each, the signer expires during the run, after the token is made, and
the TSA's leaf is off the profile. The script waited 159 s for the signers
to expire, then judged the probes:

| probe | 0.28.6 | 0.29.0 | here, before | here, after |
|---|---|---|---|---|
| `…-ca-true` | `Invalid` (`invalid`, `untrusted`, `expired`) | `Invalid` (`untrusted`, `expired`) | `Invalid` (`invalid`, `expired`) | `Invalid` (`expired`) |
| `…-eku-email-only` | `Invalid` (`invalid` only; `timeStamp.trusted`) | `Invalid` (`untrusted`, `expired`) | `Invalid` (`invalid`, `expired`) | `Invalid` (`expired`) |

0.28.6 trusted a TSA with only E-mail Protection, and the time-stamp kept
the expired signer in time. That file was `Invalid` there only through
the profile code, and `c2pa` #2854 fixed it. Here that TSA was already
untrusted (SPEC-017 AC7).

`c2patool` 0.29.0's answers were recorded for:

- the four existing `tsa-profile` probes;
- the eight `tsa-matrix` probes with a TSA off the profile.

Each was recorded with and without settings: `Trusted` with settings,
`Valid` without, on all twelve.

**Tests.** `TsaProfileTest` was rewritten for AC14 and AC15. Against the
old code, 6 tests were red and 2 green:

- **Green**, as they should be on both sides: the control, and AC15's
  guard that a TSA on the profile still keeps the expired signer of
  `tsa-matrix/expired-signer-trusted-tsa` `Trusted`.
- **Red**:
  - AC14 ×3: the state;
  - the version 1 test: the `claimVersion` parameter;
  - AC15 ×2: `signingCredential.invalid` present.

After the change, 8 of 8 are green. `TsaMatrixTest` failed on AC1, AC4 and
AC7 until SPEC-062 amendment 5, then passed 6 of 6. The codes of the eight
probes equal 0.29.0's exactly.

**Mutation.** With the profile check skipped (`if (false && $faults !==
[])`), the TSA is trusted and its time used. AC14 ×3 and both AC15 probes
go red: an expired signer would be `Trusted`. The other direction, adding
`signingCredential.invalid` again, is the old code, already seen red.

**Corpus, before and after.** The tree at `44ce16e` was given its own
copied `vendor/` in a worktree. Both trees ran over the 932 media files at
2026-11-22T12:00:00Z, in three configurations: without settings, with
`tests/Fixtures/trust/full.settings.json`, and with the probe's own
`<name>.settings.json` where one exists. That is 1,991 runs a side, with
no exception on either side. 39 runs differ:

- the eleven probes with a TSA off the profile (8 in `tsa-matrix/`, 3 in
  `tsa-profile/`): `Invalid` → `Trusted` with their own settings, and
  `Invalid` → `Valid` without settings and with `full`;
- the two new probes: `Invalid` → `Invalid`, codes only
  (`signingCredential.invalid` gone).

No other file moves, and no writer's real file does.

**Checks.** `composer check` exit 0, and `composer test:parallel` too:
1,775 passed, 1 skipped, 217 symbols. `bin/spec-check.php` reports OK.

## What changed

- `src/Timestamp/TimestampCheck.php`: the block adding
  `signingCredential.invalid` removed, and `$claimVersion` removed from
  `judge()` and `checkHeader()` and from their three call sites.
- `specs/SPEC-017-timestamp-check.md`: amendment 12; AC14 rewritten, AC15
  new; Traceability.
- `specs/SPEC-062-tsa-matrix.md`: amendment 5.
- `tests/Unit/Timestamp/TsaProfileTest.php`, `TsaMatrixTest.php`.
- `bin/make-tsa-matrix.php`: the two variants.
- Fixtures: the two probes with settings; 0.28.6 and 0.29.0 answers for
  them; 0.29.0 answers for the four `tsa-profile` and eight `tsa-matrix`
  probes; `tests/Fixtures/timestamp/tsa-profile/README.md`.
- `docs/comparison.md`: the TSA-profile row is now an agreement with
  0.29.0. `docs/reading-c2pa-2.4.md`: the §15.8.2 row.
- `CHANGELOG.md`: *Unreleased*, for 0.11.4.

## Open

- SPEC-062 amendment 5 follows from Maurice's decision on SPEC-017
  amendment 12. He confirmed it on 2026-11-22 ("akkoord amendement 5").
- Release 0.11.4, as a patch: preparing it, tagging and Packagist each
  wait for his word.
