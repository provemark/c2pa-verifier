# Step 504 — An OCSP `good` without `nextUpdate` is current for 24 hours

*2026-10-29.* The first half of finding T1 of the security review before
0.10.0 (route A, Maurice's choice: close the two gaps of P05-7 that made a
stored `good` read as current, and keep ADR-0007's order — stored answers
first, the caller's source when they prove nothing). SPEC-030 amendment 7,
approved before the test was written.

## The finding

A caller may hand the verifier a revocation source (SPEC-086). It is
asked only when the responses stored in the file prove nothing
(`skipped` or `unknown`). Whoever writes the file chooses those
responses: they sit in the unsigned `rVals` header. `statusOf()` counted a
`good` as current while the judged time lay in `[thisUpdate,
nextUpdate]`, and a response without `nextUpdate` had no upper bound.
C2PA 2.4 §15.9.1 ends the window at `producedAt` + 24 hours when
`nextUpdate` is absent. `docs/reading-c2pa-2.4.md` P05-7 had recorded the
difference as harmless, "only between `notRevoked` and `skipped`". With a
source that is wrong: `skipped` is what makes the source be asked.

## What was measured

- The reviewer's probe, re-run before the change (judged time 2028-01-01,
  the source answering `revoked` from 2027-06-01): no staple gives
  `signingCredential.ocsp.revoked`, the source asked once; a stapled
  `good` without `nextUpdate` from 2026-10-29 gives `ocsp.notRevoked`,
  the source asked 0 times.
- `bin/make-ocsp-window-variants.php`: a throw-away CA and signer, a
  `good` without `nextUpdate` (`openssl ocsp -resp_text`: "This Update" =
  "Produced At", no "Next Update") and a `revoked` about the same
  certificate.
- AC12 before the change: the +23-hour assertions passed, the +25-hour
  one failed (`notRevoked`, not `skipped`). After: passes. The old
  `OcspCheck` once more against the final test: fails.
- The reviewer's probe after: the stapled old `good` now gives
  `ocsp.revoked`, the source asked once. The expired-responder probe still
  gives `notRevoked`: that is step 505.
- All 45 tests of SPEC-030 pass.
- The corpus before (`395004a`, worktree, `vendor/` copied) and after:
  2,162 runs, none differs. Its stapled responses carry `nextUpdate`.
- `composer check` exit 0 (1,668 passed, 1 skipped), `vendor/bin/pest
  --parallel` exit 0.

## What was done

`statusOf()`: a `good` without `nextUpdate` judged later than
`producedAt` + 24 hours is `signingCredential.ocsp.skipped`, naming the
bound, so a source is asked as when nothing was stored. It is the
response's rule, so it holds for a fetched response as for a stored one.
A `revoked` is untouched, as is a response with `nextUpdate`. P05-7 and
the §15.9.1 row in `docs/reading-c2pa-2.4.md` are corrected.
