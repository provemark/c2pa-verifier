# Step 481 — Three status codes brought to the letter of C2PA 2.4

*2026-10-27.* SPEC-088 built: group F of the 2.4 reading (step 426). The
verdict of no file changes; three faults now carry the code §15 names. One
new contract symbol, `StatusCode::AssertionCborInvalid` (209 symbols). It
goes into the next minor with SPEC-081 to SPEC-087.

## What changed

- **An assertion that is not CBOR** (§15.10.3.1) is
  `assertion.cbor.invalid`, where it was `general.error`, at the same url
  and with the same explanation (`Manifest::assertionData()`), as the
  claim's `claim.cbor.invalid`. The store is refused as before.
- **A time-stamp token** whose `messageImprint` names a hash outside §13.1,
  or whose `certificates` hold no certificate for its signer, is
  `timeStamp.untrusted` (§15.8.2), where it was `timeStamp.malformed`. The
  token is ignored as before. `TimestampException` (internal) carries a
  code `UNTRUSTED` for the two faults found while reading the token, and
  `TimestampCheck` reports what the exception says at the three places
  that judge a token; `judge()` reports the unmatched `sid` directly.
- **Kept**, each with its reason in SPEC-088: a CMS signature that fails
  stays `untrusted` (as both `c2patool` versions), a BMFF hash with an
  unknown `alg` stays `assertion.bmffHash.mismatch` (as both), an external
  reference without `alg`, a `hashed_uri` without a destination, and the
  codes the enum does not hold.
- **Fixtures.** `claim/assertion-cbor-{reserved,break}` from
  `bin/make-claim-variants.php` (no keys; deterministic, the other variants
  rebuilt byte for byte), and `timestamp/tsa-matrix/tsa-cert-absent` and
  `tsa-cert-absent-chain-only` from `bin/make-tsa-matrix.php`'s new `certs`
  option (throw-away keys, deleted). `c2patool` 0.28.3's answers stored
  beside them; for the TSA probes also 0.28.1 and 0.27.22, as the matrix
  asks.
- `docs/comparison.md`, `docs/conformance.md` (`PRED-ASSE-007` *yes*: 78
  yes, 9 partial), `docs/reading-c2pa-2.4.md` (three rows covered: 334
  covered, 50 partial; the missing-code list at 45).
- Amendments, approved by Maurice, for the earlier tests that pinned the old codes:
  SPEC-017 amendment 11 (an unmatched `sid`), SPEC-062 amendment 4 (two
  probes, the SHA-1 codes), SPEC-084 amendment 2 (the SHA-1 token's code).

## Measured

- **Before the build.** 7 of the 11 tests failed; the 4 that passed are
  guards true before (AC4's three other faults stay `malformed`; AC3's
  explanation names what is missing).
- **After.** `composer check` and `vendor/bin/pest --parallel`, both exit
  0. Five earlier tests failed at first — the matrix's probe count (33 →
  35), three pins of the SHA-1 imprint's `malformed`, and SPEC-017's
  unmatched `sid` — each the old code, covered by the amendments above.
- **A first placement, changed.** The two TSA probes went first into a set
  of their own; the matrix test reads every probe the generator defines,
  so they joined `tsa-matrix` instead.
- **AC5.** 3,042 runs (three settings, a fixed validation time) in a
  worktree at `1ad620d` (own `vendor/` copy, checked to load its own
  `src/`) and after: 18 differ — the six files above, under each setting —
  and a full diff of their reports shows only codes and explanations.
- **Mutations.** Eight. Six caught at once; one (a time-stamp assertion's
  token reported `malformed`, M6) by a test written after the build, which
  offers the SHA-1 token through `TimestampAssertions`. One equivalent: the
  header parser never throws the untrusted fault, so the code there was
  put back to `timeStamp.malformed` rather than left untested (M8).
