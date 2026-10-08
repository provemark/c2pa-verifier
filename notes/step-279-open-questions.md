# Step 279 — Every open question in the specs given a status

*2026-10-08. After 0.5.1. No code changed.*

## Why

The wrong `Trusted` fixed in 0.5.1 had been written down on 2026-09-25
(step 148) as an open question in SPEC-014, labelled a non-blocker:
*"the validity of the anchor itself … no probe measures it."* The
reasoning that it did not matter (RFC 5280 treats an anchor as input) was
never measured. Nothing forced the question to be looked at again.

## What was done

`grep "^- Non-blocker" specs/*.md`: 27 open questions in 10 specs. Each
was read against the code, the notes and, where it is about a verdict,
`c2pa-rs` 0.91.1. Each now carries a line *Status 2026-10-08 (step 279)*
under it. The question itself is unchanged.

| status | count | questions |
|---|---|---|
| answered by a later step, with the step named | 10 | SPEC-011 ×1, SPEC-012 ×1, SPEC-014 ×1, SPEC-016 ×1, SPEC-017 ×5, SPEC-018 ×1 |
| design or process, no verdict | 9 | SPEC-000, -001, -011, -012, -013 ×2, -014, -016, -018 |
| about a verdict, fail closed (at worst stricter) | 7 | SPEC-012, -013, -014 (names), -015 ×2, -016 ×2 |
| about a verdict, equal to `c2pa-rs`, read not measured | 1 | SPEC-018 (`c2pa.actions` in a version 2 claim) |

Two were checked more closely, because they could have been lenient:

- **SPEC-014, names compared as arrays.** A link in the walk counts only
  when the name matches and the issuer's key verifies the signature
  (`Certificate::signedBy()`, AC5). Two names that compare equal cannot
  make a wrong `Trusted` without the anchor's private key. Reasoned.
- **SPEC-018, the version 1 label in a version 2 claim.** `c2pa-rs` 0.91.1
  finds actions assertions by `label_root()`, the label without its
  version (`assertion.rs`, `assertions_eq`), so `c2pa.actions` in a
  version 2 claim gets the version 2 rules, as `ActionsCheck` gives it
  here. Read, not measured. Measured: `c2patool` 0.27.22 and 0.28.1 both
  rewrite the label to `c2pa.actions.v2` when they sign, so neither can
  make the probe. A re-signed probe is the next measurement step.

The question SPEC-014 amendment 5 answered was already struck through
there and is not among the 27.
