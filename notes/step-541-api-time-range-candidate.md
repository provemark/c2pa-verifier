# Step 541 — Candidate: the API accepts a validation time RFC 3339 cannot write

*2026-11-13.* Recorded as decided in the library's SPEC-047, open question
2 (Maurice: "goedgekeurd, open vragen zoals voorgesteld"). No code; a
candidate, not a spec.

`Verifier::verify($stream, $settings, $validationTime)` accepts any
`\DateTimeInterface`. The command line refuses a time whose UTC year lies
outside 0001–9999 (SPEC-073 amendment 2, step 397), because RFC 3339
cannot write it. The API does not, and writes it as it is. Measured on
0.11.1:

- `@253402300800` (the first second of year 10000): crJSON's
  `validationTime` is `10000-01-01T00:00:00Z`;
- `@-62135596801` (the last second of year 0): `0000-12-31T23:59:59Z`;
- both verify, and both are `Invalid` for `fixture-signed.png`.

The library's `C2paVerifierReader::readVerified()` now refuses such a time
before calling the verifier (its SPEC-047 AC5), so its callers are covered.
A caller of this API directly is not. Whether the verifier should refuse it
too, and how (an exception, or a crJSON that leaves the time out), is open
and would need its own amendment to SPEC-073.
