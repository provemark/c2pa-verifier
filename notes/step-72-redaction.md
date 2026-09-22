# Step 72 — The redaction message, and a worry of mine that was wrong

*2026-09-22.* Step 71 read the conformance suite's predicate catalogue and,
following `PRED-ASSE-009` (self-redaction prohibition), looked at this
verifier's redaction path. Two things came out of it: a message that dated
itself, and a suspicion that turned out to be unfounded. Both are settled
here.

## What is actually measured

Two files in the corpus carry a non-empty `redacted_assertions`, out of 174
with a manifest store:

| file | what it is |
|---|---|
| `binding/claim-redacted.png` | one of this project's own variants: the claim was edited to declare a redaction, which breaks the signature. Not a redaction — a tampered file |
| `ingredient-manifest/redacted.png` | signed, built for SPEC-021 |

On the second, the two verdicts agree and the reasons do not:

| | verdict | codes |
|---|---|---|
| this verifier | `Invalid` | `signingCredential.untrusted`, `general.error` (redactions refused) |
| `c2patool` 0.27.22 | `Invalid` | `assertion.selfRedacted` — "claim contains self redaction"; `assertion.action.redacted` — "redaction of action assertions disallowed" |

The redacted URI points at `c2pa.actions.v2` **in the same manifest**, so
that file is both a self-redaction and an action redaction — two things
C2PA 2.4 forbids outright. Our refusal reaches the right verdict, and it
reaches it without knowing why. Neither `assertion.selfRedacted` nor
`assertion.action.redacted` exists in this project's `StatusCode` enum.

**There is no legal redaction anywhere in the corpus** — not in c2pa-rs's
fixtures, not in the public test files, not among the nine writers. That is
the fact the refusal rests on, and it has not changed.

## The message was stale, the rule was not

`docs/comparison.md` and SPEC-021 both record the refusal as a deliberate
decision of the maintainer, dated 2026-09-22, with its real reason:
validating a redaction requires the claim-signature hash method
(C2PA 2.4 §15.11.3.3.1) and the `assertion.notRedacted` check, and no
corpus file exercises either. That documentation is right.

What a user was told at runtime was not:

> the claim declares 1 redacted_assertions; redactions (C2PA 2.4 §6.7) are
> **not supported before M7**, and a claim that says "redacted" is not
> passed on trust

M7 closed in step 57. The message pointed at a milestone that had already
passed, so a reader would reasonably conclude the refusal was temporary and
nearly over. It is neither. The message now states the rule and the reason
without a date:

> the claim declares 1 redacted_assertions; this verifier refuses such a
> claim rather than guessing at it. Validating a redaction needs the
> claim-signature hash method (C2PA 2.4 §15.11.3.3.1) and the
> assertion.notRedacted check, neither of which this verifier implements,
> so a claim that says "redacted" is not passed on trust

The test had the same flaw and hid it: it asserted the explanation
contained the string `M7`. It now asserts on `15.11.3.3.1` and
`assertion.notRedacted` — the parts that will still be true next year. A
test that pins a milestone into a message is a test that has to be edited
every time the project moves, which is how a stale message survives.

No rule changed, no verdict changed: 381 tests green, the same verdicts on
the same files.

## The worry that was wrong

Step 71's note raised a question: `ManifestGraph` has a
`$redactedAssertions` field, and a claim declaring redactions is refused —
so either the field is unreachable, or the two paths disagree.

Measured:

```
ManifestGraph::fromStore(ingredient-manifest/redacted.png)
  redactedAssertions: 1
  -> self#jumbf=/c2pa/urn:c2pa:488bf983…/c2pa.assertions/c2pa.actions.v2
```

The field is populated. The refusal adds a status; it does not stop the
walk, so the graph is built as usual and collects what the claims declare.
The two paths do not disagree, and nothing needs fixing. Recorded because
the question was raised in a tracked note, and a suspicion left in a note
without its answer becomes a phantom bug somebody chases later.

## What is still open, and is not for this step

`assertion.selfRedacted` and `assertion.action.redacted` are §15 codes this
verifier does not have, because it refuses before it could ever emit them.
Implementing redaction properly — the two prohibitions, the
claim-signature hash method, and the `assertion.notRedacted` check — is a
spec of its own and needs a fixture with a *legal* redaction, which the
tooling in `bin/` can build but nobody has. `docs/comparison.md` already
names it as "a fixture, then a spec"; this step changes nothing about that
plan.
