# Step 95 — Saying how it was built, not only that it was

*2026-09-23.* The maintainer: it may be stated clearly that this is made
with Claude Code, **but in a controlled way**.

The disclosure already existed, at the bottom of the README and honest as
far as it went:

> This verifier is written with Claude Code (Anthropic), directed and
> reviewed by Maurice van Loon. Every contribution the assistant makes is
> recorded in `AI-LOG.md` […]

What it did not say is what "controlled" means. A reader who wants to know
whether this is worth trusting does not need the word *reviewed*; they need
to know what the review consisted of, and how to check that it happened.

## The rule the section now follows

Every control named is checkable in this repository. That is stated in the
section itself, because a list of virtues nobody can verify is worth less
than no list at all:

- a specification before any code, 31 of them, with a status the
  maintainer moves;
- a traceability table per specification, enforced by `bin/spec-check.php`
  — the build fails if a spec claims `implemented` with an empty row;
- tests written first and **seen failing**, with the failing output quoted
  in the step's note;
- one concept per step, explained and approved before it was built, with
  `AI-LOG.md` recording model, request, output, what was *measured with
  the command* and what was merely *reasoned*;
- changes of plan amended, numbered, weighed and confirmed — 87 times —
  rather than made quietly;
- independent oracles: `c2patool` 0.27.22, a second implementation in Go,
  a third in Python;
- and what is **missing** published too, all 111 obligations with the 17
  unmet ones named.

Each of those numbers was checked against the repository before it went in:
31 spec files, 87 numbered amendments, 17 rows marked as gaps.

## The sentence that keeps it honest

> What none of that is: an independent security audit. Nobody outside this
> project has reviewed the code.

Without that line the section would read as a claim of assurance it has not
earned, and it would quietly contradict the first-version notice added in
step 94. The two now say the same thing from two directions: the method was
disciplined, and discipline is not the same as having been checked by
someone else.

## And one line at the top

The notice above the fold now says the project is written with Claude Code
under that method, and links down. A reader who would want to know this is
not made to scroll past the API reference to find it, and the invitation is
explicit: **judge the method, not the tool.**
