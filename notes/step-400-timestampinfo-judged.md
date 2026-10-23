# Step 400 — SPEC-076 amendment 1: timeStampInfo shows the token the verdict used

*2026-10-23.* A low item of the review of 2026-10-23.

## The finding

crJSON's `timeStampInfo` showed the header's time-stamp token whenever it
could be read. Since SPEC-064 the verdict can take its time from a
`c2pa.time-stamp` assertion's token instead, when the header's TSA is not
trusted. crJSON then showed one time and TSA while the verdict used
another.

## The fix

`CrJsonDocument::signature()` passes the url of the manifest's own
`timeStamp.trusted` to `TimestampCheck::readToken()`, which puts the
token offered at that url first. Where the header's token counted, or
none did, nothing changes.

## Measured

- `timestamp/assertion/header-wins.png` at a fixed time: under its own
  settings the parent's `timeStamp.trusted` is the header's and
  `timeStampInfo` names the TSA Matrix TSA; with that TSA left out,
  `timeStamp.trusted` is the assertion's. Before the fix `timeStampInfo`
  still named the TSA Matrix TSA (red); after, the throw-away TSA.
- Mutations, each failing: the judged token not put first, the results
  not passed on.
- `composer check`: 1,312 passed, 1 skipped.
