# Step 505 — A delegated OCSP responder must be valid when it signed

*2026-10-29.* The second half of finding T1 of the security review before
0.10.0 (route A). SPEC-030 amendment 8, approved before the test was
written.

## The finding

An OCSP response is signed by the certificate's issuer or by a delegated
responder: a certificate the issuer issued with id-kp-OCSPSigning.
`OcspCheck::responder()` checked that the issuer signed it and that it
carried the EKU, not that it was valid. RFC 6960 §3.2 requirement 4 asks
that the signer be "currently authorized", and C2PA 2.4 §15.9.1 refers to
it. With a caller's revocation source (SPEC-086) a stored `good` that
proves something keeps the source from being asked, so a retired
responder key could keep a revoked signer `Trusted`.

## What was measured

- The reviewer's probe before the change: a `good` signed today by a
  responder whose certificate expired 2021-01-01 is
  `signingCredential.ocsp.notRevoked`, the source asked 0 times.
- `bin/make-ocsp-window-variants.php` (step 504's generator) now also
  issues two delegated responders, one with `-not_before 20200101000000Z
  -not_after 20210101000000Z` and one valid for ten years, and has each
  sign a `good` for the signer. `openssl ocsp -resp_text` shows the
  responder ids and validities. Running it again made all of the
  directory's files anew; AC12 reads its times from the response and is
  unaffected.
- AC13 before the change: the expired-responder case failed
  (`notRevoked`), the control passed. After: both pass, and SPEC-030's 47
  tests. The old `OcspCheck` against the final files once more: fails.
  A first draft of the test read `producedAt` through an `openssl`
  process; it reads it from the control response's evidence instead, so
  the suite gains no process.
- The reviewer's probes after: the expired responder's `good` is
  `skipped` and the source is asked; the step-504 route stays closed.
- The corpus before (`dd5c2bf`, worktree, `vendor/` copied) and after:
  2,166 runs, none differs.
- `composer check` exit 0 (1,670 passed, 1 skipped), `vendor/bin/pest
  --parallel` exit 0.

## What was done

`responder()` takes the response's `producedAt` and refuses a delegated
responder whose certificate was not valid then, as it refuses one without
id-kp-OCSPSigning: the response is not usable, the status `skipped`
naming the responder's validity and `producedAt`, and a source is asked.
`producedAt`, not the judged time, so a genuine response keeps counting
however late it is judged; a `revoked` from a responder already expired
when it signed stops counting, as one from a responder without the EKU
already did. The issuer as signer is unchanged. `docs/reading-c2pa-2.4.md`
(P05-7, the §15.9.1 requirement-4 row, now covered) says so.
