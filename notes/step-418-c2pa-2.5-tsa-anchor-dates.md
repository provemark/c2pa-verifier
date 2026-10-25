# Step 418 — C2PA 2.5 and the dates of a TSA anchor (SPEC-079 amendment 4)

*2026-10-25.* The third of the three 2.5 clarifications read against this
verifier (step 416 has the reason): *"recommended validity intervals for
TSA trust anchors"* (2.5 Appendix F.1). Maurice approved documenting it
without code ("akkoord, documenteer en push").

## 2.4 against 2.5 (measured in the texts of steps 416 and 417)

- 2.4 §14.4.2: a TSA trust anchor is an X.509 certificate. No dates, no
  time to judge it at. Dates existed only for signer anchors (§14.4.1).
- 2.5 §13.4.2: a TSA trust anchor *configuration* "should include a
  "notBefore" date/time" and, once trust has ended, a "notAfter". If
  present, it *"shall not be used for time-stamps asserting times before
  that date/time"*, nor after "notAfter". The time judged is the one the
  time-stamp asserts: its `genTime`.

## This verifier (SPEC-079)

| 2.5 §13.4.2 | here | |
|---|---|---|
| a TSA anchor can carry dates | read from the programme's LoTE JSON lists through `--tsa-trust-list` | since 0.8.0 |
| judged at the time the time-stamp asserts | at the token's `genTime` (SPEC-079 Scope; AC5) | chosen by reasoning in SPEC-079, now normative |
| "notAfter": no trust after it | an `untrusted` stretch | applied |
| "notBefore": no trust before it | a `trusted` status's start is not read as one | by decision (SPEC-079 amendment 1, issue #9 parked) |

## What follows (reasoned)

- SPEC-079 amendment 3 said 2.4 §14.4.2 "names no other time to judge a
  TSA at", so judging at `genTime` was this project's reading, with a
  known limit: a TSA whose key leaked can backdate a token to before its
  removal. 2.5 prescribes that time, so the limit is the
  specification's own.
- 2.5 does not say how a LoTE list expresses "notBefore". The programme's
  schema extension calls a `trusted` status's time "the date a
  certificate was added to the trust list", which is not a "notBefore".
  Amendment 1 stands; `c2patool` still reads no dates.
- 2.5 also lets validation results carry a trust list's sequence number
  (`LoTESequenceNumber` as the `identifier` of a `trustListUris` entry).
  That is a rule for whoever writes validation results, such as a claim
  generator recording an ingredient. This verifier writes none: n/a.

## What changed

- `specs/SPEC-079-…md`: amendment 4. No criterion changed.
- `docs/milestones.md`: the §13.4.2 row and step 418 in the 2.5 section.
- No code and no tests changed.

## The three clarifications, together

Steps 416–418 read the three 2.5 clarifications that could touch a
verdict on files signed today. None makes a verdict wrongly `Valid` or
`Trusted` here. The additions of 2.5 wait for `c2patool`
(`docs/milestones.md`, *C2PA 2.5: what waits for `c2pa-rs`*).
