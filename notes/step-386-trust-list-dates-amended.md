# Step 386 — SPEC-079 amendment 1: only a removal limits an anchor

*2026-10-23.* After step 385 Maurice asked what issue #9 was. Its
comment of 2026-09-24, which SPEC-079 had been drafted without reading,
parked the issue: reading the `StatusStartingTime` of a `trusted` status
as a `notBefore` is this project's own interpretation (the schema
extension calls it the date the certificate was added to the list), it
would make signatures untrusted that `c2patool` trusts, and the issue
would reopen on an `untrusted` entry or a `ServiceHistory` in the
official list. None of that has happened. Maurice chose to bring
SPEC-079 back to what the parking left uncontested ("optie 2").

## What changed

- The timeline: an anchor is trusted at every time except inside an
  `untrusted` stretch (from its starting time to the next status's, or
  for good). A `trusted` status bounds nothing by itself.
- An empty or absent status, and a `trusted` status without a usable
  time, are read as the PEM bundle reads the anchor: without dates. The
  three NETLOCK entries are used again.
- Still not used, and named: an unknown status, an `untrusted` status
  without a usable time, a history entry with other certificates.
- The docs: `docs/conformance.md` (CRYP-006's row says what is and is
  not read), `docs/comparison.md`, `docs/conformance-harness.md`, the
  fixture README.

Against `c2patool` the verdicts now differ only once the programme
untrusts an anchor; none is untrusted today.

## Measured

- The tests rewritten to the amendment: 6 of 18 red, then 18 green.
- Mutations, each failing: a trusted date as a `notBefore`; an empty
  status not used; an `untrusted` status without a time ignored.
- The corpus before and after: 0 of 1,854 runs moved.
- `composer check`: 1,240 passed, 1 skipped.
