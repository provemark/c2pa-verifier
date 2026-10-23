# Step 392 — SPEC-079 amendment 2, EKU choice: an entry is reached when trust is judged

*2026-10-23.* The fifth finding of the review of 2026-10-23, under
SPEC-079 amendment 2 as Maurice approved it.

## The finding

Two places ask whether a chain reaches one `trust.anchors` entry:
`CertificateProfileCheck::acceptedEkus()`, for the EKUs of that entry's
`trust_config` (SPEC-031 AC8), and `ChainCheck::kindNote()`, for the note
that names an entry of the other kind (SPEC-031 AC6). Both asked a new
`TrustSettings` built from the entry's certificates, with no time (so at
the clock) and without the entry's SPEC-079 dates. The trust check itself
judges at the time-stamp's or the validation time, with the dates. The
two answers could differ: an entry's EKUs could count while the entry
itself no longer vouched for the chain then, or the other way round.

## The fix

`ChainCheck::reaches($chain, $set, $at)` asks the trust check about one
entry alone, its allowed list and dates kept, at `$at`. Both places use
it, and every caller passes the time it judged trust at: the signer's
check, the certificate profile, and the TSA's note (the token's time).

## Measured

- Red first: 3 of 8 new tests against the first build (with
  `acceptedEkus()` made public, which the tests need; the class is
  internal).
- Mutations, each failing: `reaches()` without the dates (3), at the
  clock (2), and each of the three callers not passing its time (1 each).
  Those three first survived; the end-to-end case (`eku-probe.jpg` with
  e4's entry dated to end 2026-10-10, judged on 2026-10-01; the same root
  as a "tsa" entry; `C.jpg` with DigiCert's root as a "manifest" entry
  ending 2026-01-01) catches them.
- The corpus before and after: 0 of 1,854 runs moved.
- `composer check`: 1,282 passed, 1 skipped.
