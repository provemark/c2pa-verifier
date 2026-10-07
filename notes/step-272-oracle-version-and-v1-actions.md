# Step 272 — Two records put right: 0.28.1's library, and the actions rules for version 1 claims

*2026-10-07. No code changed; this verifier at `537f3d1` (0.5.0).*

## Why

Writing chapter 5 of the book (the command line, on `c2patool` 0.28.1) and
chapter 4 (a second verification website) turned up two things this
repository records wrongly or not at all.

## 1. `c2patool` 0.28.1 is built on `c2pa-rs` 0.91.1

Step 193 said 0.28.1 was "still on `c2pa-rs` 0.91.0". The `Cargo.lock` at
the tag `v0.28.1` of `contentauth/c2patool` (commit `4b479f6`) locks
`c2pa` 0.91.1; at `v0.28.0` it locks 0.91.0. `Cargo.toml` names
`version = "0.91.0"`, a lower bound, which is likely where 0.91.0 came from.
Reasoned, not measured: that the release binary was built with that lock.
Step 193's header is corrected; nothing it measured depends on it.

## 2. §15.10.3.2.3 for version 1 claims

SPEC-018 and SPEC-033 apply the actions rules to version 2 claims only, as
`c2pa-rs` does without `verify.strict_v1_validation`, and accept
`ingredients` or `ingredient` in either actions assertion. Both are
decisions in the specs, but `docs/conformance.md` did not list them
against C2PA 2.4, whose rule names `c2pa.actions` and its `ingredient`
field. It does now, under "Outside the catalogue".

Measured 2026-10-07 on `tests/Fixtures/writers/adobe-20260425-lightroom-classic-church.jpg`:

- `bin/c2pa-verify`: `Valid`, failure only `signingCredential.untrusted`.
- `c2patool` 0.27.22 and 0.28.1: `Valid`; with `{"verify":
  {"strict_v1_validation": true}}`: `Invalid`, `assertion.action.malformed`
  "first action must be created or opened", no `ingredientMismatch`.
- `Dawn-Technology/c2pa-ts` at `653f2bf` (0.17.2), built from source and run
  in Node 26.7.0: `assertion.action.ingredientMismatch` ("c2pa.opened
  action is missing ingredients"); its version 1 reader takes only
  `parameters.ingredient` (`src/manifest/assertions/ActionAssertion.ts`).
  With a fallback to `parameters.ingredients` the failure disappears. The
  same file on verifieermij.nl, which runs that library, is labelled
  "Gemanipuleerd".

Read: `c2pa-rs` 0.91.1 `claim.rs` `verify_actions` (early return for
version 1 claims without the setting; rule 2.b.ii accepts either field).

## Also checked, nothing to change

`c2patool` 0.28.1 loads trust files from the folder of its settings file
(`c2pa-trust-list.pem` and three others, by name, so on a case-insensitive
file system `C2PA-TRUST-LIST.pem` matches too). None of the folders this
repository runs `c2patool --settings` in holds a file under one of those
names, so no stored oracle answer was made with an extra list.
