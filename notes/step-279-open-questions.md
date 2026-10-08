# Step 279 — Every open question in the specs given a status

*2026-10-08. After 0.5.1. No code changed.*

## Why

The wrong `Trusted` fixed in 0.5.1 had been written down on 2026-09-25
(step 148) as an open question in SPEC-014, labelled a non-blocker:
*"the validity of the anchor itself … no probe measures it."* The
reasoning that it did not matter (RFC 5280 treats an anchor as input) was
never measured. Nothing forced the question to be looked at again.

## What was done

The first pass searched for `^- Non-blocker` and found 27 open questions
in 10 specs. That count was wrong: the specs write open questions in
other shapes too (`1. **…**`, bold bullets, *(non-blocking)*), and a
second pass over every item in every `## Open questions` section found
175: 27 + 147 + the one amendment 5 had already struck through.

Each was read against the code, the notes and, where it is about a
verdict, `c2pa-rs` 0.91.1 or a measurement. Each now carries a line
*Status 2026-10-08 (step 279)* under it. The question itself is
unchanged.

For the first 27:

| status | count |
|---|---|
| answered by a later step, with the step named | 10 |
| design or process, no verdict | 9 |
| about a verdict, fail closed (at worst stricter) | 7 |
| about a verdict, equal to `c2pa-rs`, read not measured | 1 |

For the other 147, roughly: about 45 answered or decided in their own
text or by a later step, about 50 design, process or report shape with no
verdict, about 30 about a verdict and fail closed or stricter, and about
20 about a verdict and equal to an oracle (each line says which, and
whether that was measured or read).

Checked more closely, because they could have been lenient:

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

- **SPEC-035, where the redaction set comes from.** Answered by SPEC-035
  amendment 5 (step 158), which closed a wrong `Trusted`: only manifests
  the graph reaches from the active one count.
- **SPEC-027, a file whose first top-level box is included.** Measured in
  step 78.
- **SPEC-029, the dispatch of both BMFF labels.** Measured by its AC1 and
  AC7.
- **SPEC-048, a trust anchor with a weak self-signature.** Never
  measured, so measured now: a root self-signed over ECDSA-SHA1 as the
  anchor, with an intermediate and a leaf over SHA-256, is `Trusted` in
  `c2patool` 0.27.22 and 0.28.1 and here, and `openssl verify
  -x509_strict` accepts the chain. Equal to every oracle.

One status line corrects a question: SPEC-020's question on
`ingredient.manifest.missing` says the difference is named in
`docs/comparison.md`, and it is not.

The question SPEC-014 amendment 5 answered was already struck through
there and is not among the 27.
