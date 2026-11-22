# Step 553 — `docs/comparison.md` against `c2patool` 0.29.0

*2026-11-22.* Step 549 measured `c2patool` 0.29.0 and left the comparison
document to a follow-up. Step 550 updated only the TSA-profile row.
Maurice: "werk de comparison.md-rijen uit", then "ja". Documentation and
answer files only. No code, no test, no verdict changes.

## What changed

- **A paragraph "And against `c2patool` 0.29.0"** after the one on 0.28.6:
  - the measurement of step 549 (930 files, 20 moves in four groups, no
    wrong `Valid` or `Trusted`);
  - the four groups, each with its upstream change;
  - the codes that move without a verdict;
  - that the drift alarms still compare with 0.27.22.
- **The two EKU rows** (SPEC-015, SPEC-078) are now agreements with
  0.29.0. 0.28.x was the odd one out.
- **The unprotected `x5chain` row** (SPEC-047 AC1–AC3) names the five
  `chain-constraints/` probes under 0.29.0. Two of them are `Trusted`
  there with issuer "Adobe Inc". The reason cites C2PA 2.5 §13.2 and §13.5.
- **The row on label 33 in both headers** (SPEC-047 amendment 2): 0.29.0
  gives no report, so neither says `Valid` any more.
- **The SPEC-066 row**: `cs-own` agrees now; `cs-revoked`, `cs-two` and
  `ca-revoked` stay stricter here.
- **`tests/Fixtures/revocation/README.md`** gains a 0.29.0 column.

## Measured

`c2patool` 0.29.0 (the binary of step 535), each probe under its own root:

- **`revocation/`**, ten probes, `throw-away-root.settings.json`:
  - `cs-own` is `Invalid` with `signingCredential.ocsp.revoked`;
  - the other nine are `Trusted`;
  - every probe reports `signingCredential.ocsp.skipped`;
  - this verifier gives the states already in the README.
- **`chain-constraints/x5chain-*`**, five probes, `root.settings.json`:
  all `Trusted`. `x5chain-swapped` and `x5chain-text-swapped` name
  "Adobe Inc" as issuer.
- **`manifest-probes/x5chain-unprotected-too`**: `Error: COSE verifier
  failure`, no report.
- **`conformance-test/signed.{jpg,png,webp}`** under `root.settings.json`:
  `Trusted` in 0.29.0 and here.

The answers are recorded as `<probe>--0.29.0.json`, or
`--0.29.0.error.txt` for the probe without a report, under
`tests/Fixtures/c2patool/`. The results equal step 549's for the probes it
ran. No local path is in them.

## Read, not measured

- C2PA 2.5 §13.2 and §13.5, as read in step 549: the credential *shall*
  be in the protected header; a validator shall accept either label. The
  text does not say a validator shall refuse an unprotected chain.
- Why 0.29.0 keeps `cs-revoked` and `cs-two` `Trusted` was not traced.

## Checks

`composer check` exit 0.

## Left

Each on Maurice's word:
- commit and push;
- the drift alarms from 0.27.22 to 0.29.0;
- the status-code moves, one by one;
- whether to tell upstream that 0.29.0 trusts the label-33 swap.
