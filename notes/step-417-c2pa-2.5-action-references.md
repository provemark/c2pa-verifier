# Step 417 — C2PA 2.5 and the references between actions and ingredients

*2026-10-25.* The second of the three 2.5 clarifications read against this
verifier (step 416 has the reason for reading them first): *"clarified
action/ingredient reference validation"* (2.5 Appendix F.1). Maurice
approved documenting the outcome without code ("akkoord, documenteer").

## What was compared

The 2.4 and 2.5 texts (2.5 hashed in step 416; 2.4 fetched 2026-10-25,
SHA-256 starting `507b8880ca263829`), section against section, as a line
diff:

- actions validation: 2.4 §15.10.3.2.3, 2.5 §14.10.4.2.4;
- validation of references: 2.4 §15.10.3.3, 2.5 §14.10.4.3 — unchanged
  apart from numbering and the new linked-resource-integrity exception;
- ingredient explicit validation: 2.4 §15.11.3.2, 2.5 §14.11.3.2.

## What 2.5 changes, and what this verifier does

| # | 2.5 | direction | here |
|---|---|---|---|
| 1 | A *redacted* ingredient may be the target of `c2pa.opened`, `placed`, `removed`, `transcoded`, `repackaged` | more lenient | a redacted ingredient has no data, so `ActionsCheck::contentRules()` cannot read its relationship: `ingredientMismatch`. Stricter: a wrong `Invalid` at worst, never a wrong `Valid` |
| 2 | `when` with `whenStart` or `whenEnd`, or `whenStart` after `whenEnd`: `assertion.action.malformed` | new, stricter | not checked |
| 3 | `c2pa.deleted`: references, if present, to a `componentOf` ingredient in another manifest | new | not checked |
| 4 | `assertion.action.ingredientLocationMismatch`: the ingredient listed in the other claim field than the actions assertion | new, informational | not reported; no verdict depends on it |
| 5 | `relatedAssertions` may name a redacted assertion; `assertion.redacted` is recorded | more lenient, plus an informational code | accepted already (the claim still lists the label), the code not reported |
| 6 | An ingredient assertion's own `relatedAssertions` | new | not checked |

Measured with `gh search code` on `contentauth/c2pa-rs`, 2026-10-25:
`whenStart`, `ingredientLocationMismatch` and `c2pa.deleted` occur nowhere
in its source; `relatedAssertions` only in `claim_verify.rs` and its test
(the 2.4 rule for actions). `c2pa-rs` applies none of rows 2–6 yet.

## What did not change

The three differences recorded on 2026-10-09 stand as they were:
P06-5 (the hash inside an action's reference is not compared, as in
`c2pa-rs`; the signer wrote both hashes, so no asset byte is at stake),
P06-6 (`c2pa.opened` with more than one reference) and P06-7 (the letter
of the rules against both implementations). 2.5 rewords them without
changing them.

## A sentence that looks wrong (reasoned)

2.5 §14.11.3.2, for an ingredient's `relatedAssertions`, first rejects a
reference to `c2pa.ingredient`, `.v2` or `.v3`, then rejects a reference
whose label *"does not end with `.ingredient`"*. Together the two leave
almost nothing to reference. It reads like an error in the text; anyone
building row 6 should ask C2PA first.

## Decision

No code. Every row is about form, not trust, and building any of them now
would make this verifier differ from `c2patool`. Row 1 is the one that
could matter on real files (a file `Invalid` here that 2.5 accepts); it is
to be measured once `c2patool` follows 2.5. All six are listed in
`docs/milestones.md`, *C2PA 2.5: what waits for `c2pa-rs`*.

## What changed

- `docs/reading-c2pa-2.4.md`: the rows for `opened`, `placed`, `removed`,
  `transcoded`/`repackaged` and `relatedAssertions` say what 2.5 changes.
- `docs/milestones.md`: the new section with the 2.5 rows of steps 416
  and 417.
- No code and no tests changed.
