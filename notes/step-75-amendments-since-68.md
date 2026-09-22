# Step 75 — The three amendments since step 68, confirmed

*2026-09-22.* Step 68 put six amendments on one page and Maurice van Loon
confirmed all of them. Three have been written since. The arithmetic
checks out: 51 (step 51) + 17 (step 58) + 6 (step 68) + 3 = **77**, which
is the number the specs now hold.

Legend for **weight**: **A** = a rule of the verifier changed (what is
`Valid`, `Invalid`, `Trusted`, which code, or what is read at all);
**B** = the report's shape or the API changed, verdicts unchanged;
**C** = a test literal, a count, a message, a seam, or a layer line —
nothing a user of the verifier could notice.

**This is the first round with no group A and no group B.** Not one of the
three changes a verdict. That is worth saying out loud, because two of them
came out of writing code against an approved criterion and finding it
wrong — which is the procedure working, not the specs drifting.

## A — rules of the verifier

None.

## B — the report's shape, the API

None.

## C — literals, counts, seams, layers

| spec | # | what | why | confirmed |
|---|---|---|---|---|
| SPEC-025 | 1 | AC3 said the README **must not document** `$store`. It now forbids presenting it as part of the report and requires any mention of it to say what it is | silence is the wrong rule. A reader whose IDE offers `$report->store` will reach for it; what protects them is being told plainly that it is unsupported, not being left to infer it from an omission | confirmed 2026-09-22 |
| SPEC-026 | 1 | AC7 asked for a `size == 0` box that is **not the last** to be refused. That case cannot occur, and the criterion now says what is true | `size == 0` means "to the end of the file", so the declaration is what *makes* a box the last one. The amendment names the cost rather than wishing it away: such a box can swallow its successors, nothing in the container betrays it, and the hard binding is what catches it | confirmed 2026-09-22 |
| SPEC-024 | 1 | AC1 said "a JPEG, PNG or WebP" and the Scope said "all three containers"; both now say every container this verifier reads, and the test carries the fourth bound | SPEC-026 added ISOBMFF with the same 16 MiB bound and the same `MemoryBudget`. The rule did not change; only its reach did, and the literal had to follow | confirmed 2026-09-22 |

## One thing worth your eye, though none of these is group A

**SPEC-024 #1 exists because a test could not see what it did not
enumerate.** Its criterion named three constants; a fourth container
arrived carrying the same bound, and the test stayed green while the
spec's own words had become wrong. Nothing was broken and nothing was at
risk — but the alarm that was supposed to notice a container's bounds did
not notice a container.

What did notice was PHPStan, at level max, on an unhandled `match` arm.
That is worth remembering the next time a drift alarm is written: an alarm
that lists what it knows about is blind to arrivals, and the analyser is
often the thing that is not.

## Confirmation

**All three confirmed by Maurice van Loon on 2026-09-22** ("bevestig alle
drie de amendementen"). Each amendment line in SPEC-024, SPEC-025 and
SPEC-026 carries the same stamp.

## How to confirm (the procedure, as in steps 51, 58 and 68)

Per group, or per line: "bevestigd", or a question or a "no" naming the
spec and number. A "no" on an A-line reverses a rule and gets its own
step: the spec text back, a test that shows the reversed rule, and the
note. Confirmations are dated on this page and, for group A, in the
spec's amendment line itself.
