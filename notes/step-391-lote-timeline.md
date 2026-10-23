# Step 391 — SPEC-079 amendment 2, timeline: the LoTE reader fails closed

*2026-10-23.* The third finding of the review of 2026-10-23. Amendment 2
approved by Maurice. The JSON trust lists are new since 0.7.1 and not in
any release, so nothing released is affected.

## The finding

A reviewer built sixteen LoTE documents around the throw-away root of
`control.png` (time-stamped 2026-10-09T05:30:24Z). Run here through
`bin/c2pa-verify --trust-list … --tsa-trust-list tsa.pem control.png`,
five gave `Trusted` where SPEC-079 gives untrusted:

- one certificate in two services: the last service read replaced the
  first's dates, or an unusable entry;
- an `untrusted` and a `trusted` status at the same instant: the input
  order decided;
- fractions of a second dropped, so `.9` and `.1` within a second could
  swap;
- a `ServiceHistory` that is a string, read as no history.

## The fix (`TrustSettings`)

- Each certificate is an anchor once; where several services hold it, it
  is trusted only where all of them trust it (`bothTrust()`), and never
  where one is unusable.
- Statuses are ordered by instant with their fraction, a `trusted` one
  before an `untrusted` one at the same instant. The removed stretches are
  united and their complement taken (`outside()`); a removal starts at the
  second its time falls in and ends at the next whole second after the
  `trusted` status that ends it.
- A `ServiceHistory` that is not a list makes the anchor unusable. An
  entity, service or `ServiceInformation` that is not an object, or a
  `TrustedEntityServices` that is not a list, refuses the list, naming the
  place.
- The "no usable anchor" refusal counts certificates once; certificates
  in a history entry count against the certificate limit; a byte order
  mark before the list is skipped.

## Measured

- Red first: 11 of 15 new tests against the first build. A surviving
  mutation (a `trusted` time rounded down) led to a 16th case, trust
  resuming half a second after the token, red on the first build and on
  the mutant, green after.
- Mutations, each failing: the last service wins (2), fractions and ties
  ignored (3), rounding down (1), a string history read as none (1), the
  BOM kept (1).
- Three of the probes now refuse their list: their only anchor is
  unusable, so no anchor is left. The tests add an unrelated second
  anchor to measure the signer's own answer.
- AC1 unchanged: 32 and 23 anchors, the same reports as the PEM bundles.
- `composer check`: 1,274 passed, 1 skipped.
