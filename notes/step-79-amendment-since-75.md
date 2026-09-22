# Step 79 — The one amendment since step 75, confirmed

*2026-09-22.* Step 75 confirmed three amendments, all group C. One has
been written since. The arithmetic: 51 (step 51) + 17 (step 58) + 6 (step
68) + 3 (step 75) + 1 = **78**, which is the number the specs now hold.

Legend for **weight**: **A** = a rule of the verifier changed (what is
`Valid`, `Invalid`, `Trusted`, which code, or what is read at all);
**B** = the report's shape or the API changed, verdicts unchanged;
**C** = a test literal, a count, a message, a seam, or a layer line.

## B — the report's shape, the API

| spec | # | what | why | confirmed |
|---|---|---|---|---|
| SPEC-027 | 1 | `checks_performed` now says **`bmffHash`** when the binding manifest carries `c2pa.hash.bmff.v3`, and `dataHash` otherwise | the spec named the statuses and said nothing about this list, so an ISOBMFF file came back listing `dataHash` for a check that never ran. A caller reading it would conclude the data hash had been verified. A list whose whole job is to say what was done must not name something that was not | confirmed 2026-09-22 |

## A and C

None.

## What this changes for a caller

`checks_performed` is part of the report and the README documents it, so a
consumer who keys off the string `dataHash` will see `bmffHash` on ISOBMFF
files where before they saw nothing at all — those files were refused
before SPEC-026. Nothing that used to appear has disappeared: no JPEG, PNG
or WebP report changes, which SPEC-027 AC7 asserts on every run.

This is the first group-B amendment since SPEC-020's, and the first of any
weight since step 75.

## Confirmation

**Confirmed by Maurice van Loon on 2026-09-22** ("bevestig het
amendement"). The amendment line in SPEC-027 carries the same stamp.
