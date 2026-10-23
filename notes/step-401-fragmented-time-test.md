# Step 401 — a test for the time on the fragmented path

*2026-10-23.* A test gap the review of 2026-10-23 named. No change in
behaviour.

`FragmentedVerifier::verify()` passes the validation time to the
`Verifier` it builds (SPEC-073), but no test showed it: a mutation that
dropped the time stayed green. The new AC4 case verifies
`tests/Fixtures/bmff-fragmented/` now (the signer valid, no
`signingCredential.expired`), in 2099 and in 2000 (expired), and checks
the recorded `validationTime`.

Measured: dropping the time in the hand-off fails two of the three
cases. `composer check`: 1,315 passed, 1 skipped.
