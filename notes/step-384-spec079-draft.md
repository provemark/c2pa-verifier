# Step 384 — SPEC-079 drafted: the programme's JSON trust lists, with their dates

*2026-10-23.* Issue #9, after the measurement of step 383. Maurice
agreed with reading the LoTE JSON lists through the existing options and
chose, on advice, that an anchor with an empty status is not used and
the report says why. `specs/SPEC-079-trust-list-dates.md` is the draft.
No code. Step 383 was pushed first.

## What the draft says

`--trust-list`, `--tsa-trust-list` and `fromTrustLists()` read a LoTE
document as well as a PEM bundle. Each service's statuses form a
timeline (the current one and the `ServiceHistory`, each lasting until
the next); the anchor counts only at a time inside a `trusted` stretch:
the trusted time-stamp's, else the validation time, for a signer; the
token's `genTime` for a TSA. An entry that cannot be placed is not used,
and named. `c2pa-rs` does none of this, so the criteria rest on C2PA 2.4
§14.4.1 and §15.7 and the programme's schema extension.

## Measured for the draft

- ETSI TS 119 602 v1.1.1's JSON schema (in `conformance-public`): a
  `TrustedEntityService` holds `ServiceInformation` and an optional
  `ServiceHistory` of instances, each with `ServiceStatus`,
  `StatusStartingTime` and its own `ServiceDigitalIdentity`.
- `control.png`'s time-stamp: 2026-10-09T05:30:24Z. `no-timestamp.png`'s
  signer: valid from 2026-10-09T05:30:24Z, so AC4's listed date is the
  12th, after it, and the two validation times are the 11th and the 13th.
