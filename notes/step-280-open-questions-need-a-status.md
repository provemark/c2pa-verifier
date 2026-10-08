# Step 280 — An open question in an approved spec needs a status (SPEC-000 amendment 2)

*2026-10-08. A rule about the record, after step 279.*

## Why

0.5.1's wrong `Trusted` sat in SPEC-014's open questions for two weeks as
a non-blocker, reasoned and never measured. Step 279 gave all 175 open
questions a status line by hand. Without a rule, the next spec can be
approved and implemented with a question nobody looks at again.

## The rule

SPEC-000 AC12, confirmed by Maurice van Loon the same day: in a spec with
status `approved` or `implemented`, every item of `## Open questions`
(a line starting `- ` or `N. `, not struck through) must have a line
starting `*Status` before the next item, the next heading or the end of
the section. Otherwise `bin/spec-check.php` reports
`SPEC-###: open question at line N has no *Status line (amendment 2)`,
and `composer check` stops. A `draft` may keep open questions without a
status. Whether a status about a verdict is backed by a measurement is
the review's to judge; the checker only sees that the line is there.

## Tests seen red

Fixture tree `tests/Fixtures/spec-check/open-question-without-status/`:
an approved spec with a question that has a status, a numbered question
without one, a numbered question whose status follows a blank line, a
struck-through question, and a bullet without one; and a draft spec with
an open question. Expected: two findings, lines 18 and 25.

Before `specCheckOpenQuestions()` existed:

```
⨯ it AC12: an open question without a status line is a finding in an approved spec, not in a draft
-    0 => 'SPEC-001: open question at line 18 has no *Status line (amendment 2)',
-    1 => 'SPEC-001: open question at line 25 has no *Status line (amendment 2)',
Tests: 1 failed, 13 passed
```

After: 14 passed. On the repository itself: `OK: 60 spec(s)`. With one
status line removed from SPEC-048 by hand:
`SPEC-048: open question at line 139 has no *Status line (amendment 2)`;
restored. `composer check`: 920 passed.
