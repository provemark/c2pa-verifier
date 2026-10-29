# Step 509 — An empty Extension in a timestamp is a refusal, not a PHP error

*2026-10-29.* Finding C1 of the security review before 0.10.0, a crash.
SPEC-016 amendment 7, approved before the test was written.

## The finding

A claim signature carries an RFC 3161 timestamp in its unprotected header
(`sigTst`/`sigTst2`, C2PA 2.4 §10.3.2.5). Its TSTInfo may carry
extensions (RFC 3161 §2.4.2), which the verifier reads to refuse a
critical one it does not know. Each Extension is `SEQUENCE { extnID OID,
critical BOOLEAN DEFAULT FALSE, extnValue OCTET STRING }` (RFC 5280
§4.1). `TstInfo::extensions()` took `$parts[0]->oid()` without asking
whether element 0 was there. An empty Extension (`30 00`) made PHP warn
"Undefined array key 0" and then throw `Error: Call to a member function
oid() on null`: no `Asn1Exception`, so no check caught it.

The unprotected header is outside the signature, so such a token can be
put into any signed file without breaking it: one file, and a caller gets
a fatal error instead of a report.

SPEC-016 AC11 (step 153) mutates every constructed element of three
corpus tokens. It did not reach this: none of them carries extensions.

## What was measured

- The reviewer's probe before the change: `verify()` throws that `Error`
  at `src/Timestamp/TstInfo.php:180`.
- The new test appends `[1] { SEQUENCE {} }` to `c2pa-rs/C.jpg`'s TSTInfo
  with the test's own `DerPatch` (as AC7's critical-extension case
  appends one), turns PHP warnings into exceptions, parses the token and
  judges it as the timestamp header of `C.jpg`'s signature. On `c62259a`:
  fails on "Undefined array key 0". After: passes; the parse is a
  `TimestampException` ("SEQUENCE … has 0 element(s), no element 0"), the
  check `timeStamp.malformed`. The old `TstInfo` against the final test
  once more: fails.
- The reviewer's probe after: a report, `timeStamp.malformed`, the state
  what it is for any broken token.
- SPEC-016's 72 tests and SPEC-017's 23 pass. The corpus before
  (`c62259a`, worktree, `vendor/` copied) and after: 2,168 runs, none
  differs.
- `composer check` exit 0 (1,690 passed, 1 skipped), `vendor/bin/pest
  --parallel` exit 0.

## What was done

The `extnID` is read with `Der::element(0)`, made in step 153 (SPEC-016
amendment 4) for exactly this: a missing element is an `Asn1Exception`,
which the parse turns into a `TimestampException` and the check into
`timeStamp.malformed`. The optional `critical` keeps its `isset`. The
other places in `src/` that index a parsed list either count it first or
hold a list known not to be empty.
