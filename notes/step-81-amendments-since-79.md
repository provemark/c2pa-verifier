# Step 81 — The two amendments since step 79, confirmed

*2026-09-22.* The arithmetic: 51 + 17 + 6 + 3 + 1 + 2 = **80**, which is
what the specs now hold.

Legend for **weight**: **A** = a rule of the verifier changed; **B** = the
report's shape or the API changed, verdicts unchanged; **C** = a test
literal, a count, a message, a seam, or a layer line.

## C — literals, counts, seams, layers

| spec | # | what | why | confirmed |
|---|---|---|---|---|
| SPEC-026 | 2 | AC9 covered only AVIF; it now covers AVIF, MOV and HEIC, and carries a new rule: **no ISOBMFF flavour may be named in the README, in `docs/comparison.md` or in a milestone row unless a fixture here holds it** | AC9 asked for the flavour that happened to be in hand. MOV and HEIC were then claimed in three tracked files with no fixture, no oracle and no test. Both do verify — measured afterwards — which is luck, not method | confirmed 2026-09-22 |
| SPEC-027 | 2 | AC1 said "the two fixtures"; it covers four | true when written, untrue the moment MOV and HEIC were claimed elsewhere. No rule changed: the same digest over the same algorithm, on two more flavours of the same container | confirmed 2026-09-22 |

## A and B

None. No verdict changed, and no report changed shape.

## The one worth a second look, though both are C

**SPEC-026 #2 is the first amendment in this project written because of
something said rather than something built.** Every earlier one corrected a
criterion that measurement showed wrong. This one corrects a criterion that
was *too narrow to stop a sentence* — AC9 asked for AVIF, got AVIF, and had
nothing to say when the README grew two more formats.

The rule it now carries is deliberately about the record and not about the
code: a flavour named anywhere must be a fixture here. That is the only
kind of rule that would have caught what happened, because what happened
was not a bug.

## Confirmation

**Both confirmed by Maurice van Loon on 2026-09-22** ("bevestig de twee
amendementen"). Each amendment line carries the same stamp.
