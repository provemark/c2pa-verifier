# Step 397 — SPEC-073 amendment 2: `--at` stays within years 0001–9999 in UTC

*2026-10-23.* A low item of the review of 2026-10-23.

## The finding

`--at` accepted any RFC 3339 time, and the report records it in UTC. An
offset can carry a valid time past the range RFC 3339 can write:
`9999-12-31T23:59:59-23:59` was recorded as `10000-01-01T23:58:59Z`,
`0001-01-01T00:00:00+01:00` as `0000-12-31T23:00:00Z`, and crJSON's
`validationTime` then broke its schema.

## The fix

`Command::rfc3339()` refuses a time whose UTC year is below 1 or above
9999, with the same "not RFC 3339" error and exit 2. The library's
`verify()` still takes any `DateTimeInterface`.

## Measured

- Red first: the two offset cases (year 0000 as written was already
  refused). Green after; `--at ''` and the three edge instants pinned.
- Dropping either bound fails its case.
- `composer check`: 1,303 passed, 1 skipped.
