# Step 93 — The three amendments since step 88, confirmed

*2026-09-22.* 84 + 3 = **87**, which is what the specs hold.

Legend for **weight**: **A** = a rule of the verifier changed; **B** = the
report's shape or the API changed, verdicts unchanged; **C** = a test
literal, a count, a message, a seam, or a layer line.

All three come from SPEC-030 — the stapled-OCSP work of steps 91 to 92b —
and they are unusually worth reading, because two of them are the record of
a mistake that would otherwise have been invisible.

## A — a rule of the verifier

None. Revocation is new behaviour under a new spec, not a changed rule
under an old one; SPEC-030 AC9 pins the twelve verdicts that existed before
it and none moved.

## B — the report's shape, the API

| spec | # | what | why | confirmed |
|---|---|---|---|---|
| SPEC-025 | 3 | `StatusCode` grows by four cases; the recorded surface 95 → 99 symbols | SPEC-030 needs `signingCredential.ocsp.revoked`, `.notRevoked`, `.unknown` and `.skipped`. A caller matching exhaustively on the enum has four more arms | confirmed 2026-09-22 |

## C — a criterion, a number, a message

| spec | # | what | why | confirmed |
|---|---|---|---|---|
| SPEC-030 | 1 | AC1 names the DigiCert anchor; AC6 becomes the same file without it | the judged time decides everything here, and without an anchor `ocsp.jpg` is `Invalid` at *now* with a stale response — the opposite of what AC1 asked | confirmed 2026-09-22 |
| SPEC-030 | 2 | `id-pkix-ocsp-basic` is `1.3.6.1.5.5.7.48.1.1`, not `…48.1` | the shorter arc is the AIA access method; the fixture refused the wrong one and the fixture was right | confirmed 2026-09-22 |

## The one worth reading twice: a number in prose

SPEC-030's Scope named the response type as `1.3.6.1.5.5.7.48.1`. That is
*id-pkix-ocsp*, the access method an Authority Information Access extension
carries. The response type is one arc deeper.

Had the implementation followed the specification as written, every stapled
response would have been rejected as "not a basic response", and:

- **all ten acceptance criteria would still have passed.** AC1 and AC6 both
  ended in `skipped`; AC2, AC4, AC7 and AC10 expect `skipped`; AC5 and AC9
  never depended on it; AC3 and AC8 test the seam, which would have
  answered the same way.
- the feature would have been **silently inert**, in a codebase whose first
  design rule is that a verifier which wrongly says `Valid` is worse than
  one that errors.

What caught it was a fixture that could not have been downloaded — no
public file carries a `revoked` stapled response, so step 92a built one —
and the fact that it was built from `openssl`, an independent source of the
same number.

Two tests recorded the mistake from the other side: **AC7 and AC8 were
green while the OID was wrong, and went red when it was fixed.** A test
that passes because nothing happened is the failure mode this project's
rule "assert that something specific is PRESENT" exists to catch, and it is
the second time it has been seen here.

## What SPEC-025 #3 costs a caller

Four enum cases. Nothing that existed changes name, value or weight, and
`ValidationResult` is built the same way; what grows is the set a
`match (true)` over `StatusCode` must cover, and the number of lines in a
typical status list — every file now carries one `signingCredential.ocsp.*`
entry, because a check that was skipped has to be visible.

The alarm rang without anybody having to remember it. `bin/api-check.php`,
which only joined `composer check` in step 89 after drifting unnoticed for
four commits, refused the run with "public but not recorded" on all four
cases before a word of this was written down.

## Confirmation

**Confirmed by Maurice van Loon on 2026-09-22** ("bevestig beide
amendementen", after confirming SPEC-030 #1 with 92b). Each amendment line
in SPEC-030 and SPEC-025 carries the same stamp.
