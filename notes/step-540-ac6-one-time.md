# Step 540 — SPEC-086 AC6 on one time

*2026-11-13.* Maurice: "repareer de klokafhankelijke AC6-test". A test
change only, no change in behaviour (as step 401).

## The fault

`RevocationSourceTest` › *AC6: not asked for an untrusted signer*
verifies a file twice, once with a revocation source and once without,
and compares the two `toJson()`. Neither run passed a validation time,
so each read the clock. The Bing signer
(`writers/microsoft-20260609-bing-fast-heartbeat.jpg`) expired on
2026-10-01, and since then its report carries `signingCredential.expired`
with "expired at <the time checked at>". Two runs that straddle a second
differ. That failed the tag CI of `v0.11.1` (run 43438381527, attempt 1,
`14:01:01Z` against `14:01:00Z`; step 539).

## The change

`spec086Verify()` takes an optional time and hands it to
`Verifier::verify()`. AC6 reads the clock once and passes that time to
both runs. The time stays "now", so the test still judges the files as
before (Bing expired, `cs-parent` unchanged); only the two runs agree.

## Measured

- With the second run given a time one second later, AC6's Bing dataset
  fails and `cs-parent` stays green: the comparison does notice the
  second, so the fault was real and the fix removes it.
- `RevocationSourceTest`: 32 passed.
- `composer check`: exit 0, 1,748 passed, 1 skipped.

## The other comparisons of two runs (checked, not changed)

`RevocationSourceTest` amendment 5, `RevocationSourceCasTest` AC6 and
`TrustListIdentityTest` AC6 also compare two runs on the clock.
`ValidationTimeTest` AC3 has a trusted timestamp, which wins over the
clock. With `--at 2099-01-01`, the certificates behind the first three:

| fixture | expires |
|---|---|
| `revocation/cs-parent.png` | 2036-10-06 |
| `revocation/ca-revoked.png` | 2036-10-06 |
| `writers/openai-20260826-c2pa_2x.png` | 2027-04-23 (one other certificate in it already expired on 2026-09-01; its report today names no clock time) |

So none of them can fail this way now. The OpenAI file is the next to
look at, before 2027-04-23.
