# Step 453 — `signingCredential.expired` is a code of older tables

*2026-10-26.* Documentation only: no verdict, no status code and no
public symbol changes.

## Why

The docblock of `StatusCode` promises the codes of C2PA 2.4 §15.2.2
verbatim, "no word of our own". One case does not fit that promise. A
signer outside its certificate's validity is reported as
`signingCredential.expired`, as `c2pa-rs` reports it. The 2.4 table no
longer has that code: §15.8.2 names the case
`claimSignature.outsideValidity`. The gap was already recorded in
`docs/reading-c2pa-2.4.md` (§15.2.2, "reported under another code") and in
`docs/conformance.md` (`PRED-CRYP-016`), but not where the promise is made.

## What was measured

- **The published specifications.** Each version was fetched from
  spec.c2pa.org (`specifications/<v>/specs/C2PA_Specification.html`), and
  both codes were counted:

  | version | `signingCredential.expired` | `claimSignature.outsideValidity` |
  |---|---|---|
  | 1.0, 1.4, 2.0 | in the table, and prescribed by the validation text | absent |
  | 2.1 | still in the table | in the table, and prescribed by the validation text |
  | 2.2, 2.3, 2.4, 2.5 | absent | the only one |

- **The stored `c2patool` answers.** Files under `tests/Fixtures/` with
  `signingCredential.expired` exist for `c2patool` 0.27.22 (19), 0.28.0 (3),
  0.28.1 (6) and 0.28.3 (1, `tsa-matrix/expired-signer-imprint-sha1`).

## What changed

- `src/Report/StatusCode.php`: the docblock names the one code that comes
  from an older table, and why it stays.
- `src/Trust/CertificateProfileCheck.php`: the same, in one clause.
- `docs/comparison.md`: a row under "Where this verifier differs by
  design", with the version history.
- `docs/reading-c2pa-2.4.md`: the version history added to the existing
  §15.2.2 entry.

## Not done

Adding `claimSignature.outsideValidity` itself would add a status code, and
so change the contract. It waits for the minor release that gathers the
§15.2.2 codes reported under another name (group F of step 426).
