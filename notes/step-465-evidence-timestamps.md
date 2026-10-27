# Step 465 — The time-stamps found

*2026-10-27.* SPEC-084 built, with amendment 1. No verdict changes; the
public contract grows by one class and one property, so this waits for the
next minor release, together with SPEC-081 to SPEC-083.

## Why

SPEC-082 says which time a manifest was judged at, and which anchor made
its time-stamp authority trusted, but not the time-stamp itself. A manifest
can be offered tokens in its own `sigTst` / `sigTst2` header and in
`c2pa.time-stamp` assertions (C2PA 2.4 §10.3.2.5, §18.18). The first that
validates and is trusted gives the time (§15.8.1); the others were visible
only as statuses. An archive that wants to show which token counted, or
judge a rejected one again once its authority is listed, needs the tokens.

## What changed

- `ManifestEvidence::$timestamps`: one `Timestamp\TimestampEvidence` per
  token offered, the header's first, then the assertions' in the order
  tried. Each holds the source (`sigTst`, `sigTst2`, `assertion`), the url,
  the token's DER as the file carries it, its `genTime`, the certificates
  inside it, the outcome (`used`, `rejected`, `notTried`) and the
  `timeStamp.*` codes the check gave it, before the report scopes or drops
  any line.
- **The check hands it out.** `TimestampCheck::checkHeader()` and
  `forManifest()` record each token where they judge it, so there is no
  second reading that could drift. A two-token header lists both tokens,
  rejected as malformed; a token that cannot be read keeps its bytes, with
  no time and no certificates; a header that cannot be read lists nothing,
  and its status says why.
- **Amendment 1.** Tokens an assertion offers to a manifest whose claim
  signature the COSE reader refuses are listed as `notTried`: there is
  nothing to judge them against. No corpus file has the case.

## Measured

- **Red first.** The 14 tests for the acceptance criteria all failed on the
  missing class and property; `ApiSurfaceTest` AC1 on the fifteen new
  symbols. Then green: `composer check`, 1,471 passed, 1 skipped (the same
  skip as before).
- **AC8 over the corpus.** Every file under `tests/Fixtures/` under no
  settings, `trust/full.settings.json` and the programme's LoTE lists: in
  every judged manifest exactly one token is used when the time came from a
  time-stamp, and its `genTime` is the time judged at; none otherwise; only
  `notTried` follows a used token; and for the active manifest the entries'
  codes are the report's `timeStamp.*` statuses at their urls.
- **AC9 over the corpus.** The same 3,060 runs before (a worktree at
  `3e8ec9a` with its own copy of `vendor/`, checked to load its own `src/`)
  and after: the SHA-256 of `toJson()` and `toCrJson()` is the same in
  every run.
- **Mutations.** Ten changes to the new code. Eight were caught at once:
  the header token never used, the untried tokens dropped, every code kept
  rather than the `timeStamp.*` ones, the certificates dropped, the time
  dropped, one token of two kept, a rejected assertion token called used,
  the evidence not passed on. Two survived, because no corpus file shows
  the case: the tokens after a used assertion token dropped (M7), and an
  unreadable assertion token dropped (M9). Two tests were added, written
  after the build: `update-manifest/standard-borrows-with-update.jpg`
  with the token's own chain as anchor (used, then not tried), and
  `raw.png`'s ingredient offered a broken token before its own (rejected
  as malformed, then used). Each fails under its mutation.

## For the next release

A contract change: 187 symbols, fifteen classes. The CHANGELOG entry of the
next minor names `Timestamp\TimestampEvidence` and
`ManifestEvidence::$timestamps`, beside the symbols of SPEC-081 to
SPEC-083. Next in C1: the OCSP responses offered, and which one decided.
