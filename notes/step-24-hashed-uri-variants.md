# Step 24 — Eight hashed-URI variants through c2patool, before the SPEC-011 tests

*2026-09-21.* SPEC-011 is approved. Its criteria AC3–AC8 name eight
variants that did not exist yet, so this step makes them, runs each
through c2patool 0.27.22, and writes down what it said — before a single
test is written, so that a criterion c2patool contradicts is corrected
now and not after a test has been made to pass. No verifier code.

## Made: `bin/make-hashed-uri-variants.php`

The byte-surgery helpers of step 23's script moved to
`bin/variant-helpers.php`, shared by both scripts; the step-23 script was
re-run afterwards and produced byte-identical files (the SHA-256 lines
matched and `git status` showed no fixture changed). The new script edits
the PNG fixture's store at the step-09 offsets and writes `.bin` (the
store) and `.png` (the fixture carrying it, CRC recomputed) to
`tests/Fixtures/binding/`:

| variant | edit |
|---|---|
| `hashed-uris-two-changed` | one bit in the claim's `hash` for `c2pa.hash.data` *and* for `c2pa.thumbnail.claim` (first and second entry) |
| `hashed-uri-truncated` | the first entry's `hash` `58 20 <32>` → `58 1f <31>` |
| `assertion-duplicate-label` | a second `c2pa.actions.v2` box, byte for byte the first, at the end of the assertion store |
| `assertion-undeclared-unknown-uuid` | the same copy with UUID `deadbeef-0011-0010-8000-00aa00389b71` and label `c2pa.extraz.v2x` |
| `uri-alg-sha384` | the first entry gains `alg: sha384` (map `a2` → `a3`) and a 48-byte SHA-384 of the box payload; the claim's `alg` stays `sha256` |
| `claim-alg-sha1` | the claim's `alg` `sha256` → `sha1` |
| `claim-alg-missing` | the claim's `alg` pair removed (map `a7` → `a6`) |
| `claim-redacted` | `redacted_assertions: ["self#jumbf=c2pa.assertions/c2pa.actions.v2"]` appended to the claim (map `a7` → `a8`) |

A throw-away probe (not committed) on M2's classes confirmed that every
variant parses as the spec assumes: the duplicate is a fourth `Superbox`
in the store, the unknown-UUID copy an `UnknownBox` at 33026 with its
label, `redacted_assertions` lands in `Claim::$other`, and the three
entries hash `match`/`MISMATCH` exactly as AC3, AC4 and AC7 predict.

## Measured: c2patool 0.27.22 on the nine PNGs

`../tools/c2patool tests/Fixtures/binding/<name>.png` (the sister
repository's binary), default output; the JSON is recorded in
`tests/Fixtures/c2patool/variants/` where there was any.
`hashed-uri-changed` (step 23) was run again to record its JSON. Every
variant below also changes the claim or the store's length, so
`claimSignature.mismatch` and/or `assertion.dataHash.mismatch` come
along; the column shows the codes that matter to SPEC-011.

| variant | c2patool | SPEC-011 |
|---|---|---|
| `hashed-uri-changed` | `assertion.hashedURI.mismatch` on `c2pa.hash.data`; `match` on the other two | AC3 ✔ |
| `hashed-uris-two-changed` | `assertion.hashedURI.mismatch` on `c2pa.hash.data` **and** `c2pa.thumbnail.claim`; `match` on `c2pa.actions.v2` — every entry reported | AC3 ✔ (decision 1 is c2patool's behaviour) |
| `hashed-uri-truncated` | `assertion.hashedURI.mismatch` on `c2pa.hash.data`, a report, not an error | AC4 ✔ |
| `assertion-duplicate-label` | `Error: assertion missing: url = c2pa.actions.v2` — no report | AC5: `assertion.undeclared` (divergence kept, as for `assertion-undeclared` in step 23) |
| `assertion-undeclared-unknown-uuid` | `Error: assertion missing: url = c2pa.extraz.v2x` — no report | AC6: `assertion.undeclared` (divergence kept) |
| `uri-alg-sha384` | three `assertion.hashedURI.match` — the entry's own `alg` honoured | AC7 ✔ |
| `claim-alg-sha1` | three `assertion.hashedURI.mismatch` ("hash does not match assertion data"), no `algorithm.unsupported` | AC7: `algorithm.unsupported` per entry — divergence kept; both failures, the spec's code names the cause |
| `claim-alg-missing` | `Error: unknown algorithm` — no report | AC7: `algorithm.unsupported` per entry (divergence kept) |
| `claim-redacted` | `assertion.action.redacted` on `c2pa.actions.v2` ("redaction of action assertions disallowed"); three `hashedURI.match` | AC8: `general.error` — see below |

## What the redaction measurement adds

Two more redaction variants were run and *not* committed, to see what
c2patool actually checks:

- the same claim redacting `c2pa.thumbnail.claim` instead: **no
  redaction-related status at all** — three `hashedURI.match`, only the
  signature and data hash (broken by the edit itself) fail;
- redacting `c2pa.actions.v2` *in another manifest's URI*
  (`/c2pa/urn:c2pa:0000…/c2pa.assertions/c2pa.actions.v2`):
  `assertion.action.redacted` again.

So c2patool's redaction check in 0.27.22 is one rule: the redacted label
may not be an actions assertion (§6.7). It does not check that the
redacted assertion is actually absent — the thumbnail is still in the
store, named by the claim, and hashed `match`, while the claim says it
was redacted. That is exactly the silent route decision 3 closes:
until the redaction rules are implemented (M7), a claim that says
"redacted" gets `general.error`, not a pass. AC8 stands; c2patool's
code for the actions case is noted next to it as the one M7 must emit.

## What this settles

- No criterion of SPEC-011 is contradicted. Three divergences from
  c2patool are recorded, all of the same kind as step 23's: where it
  stops with a hard error (undeclared, duplicate, missing alg) or names
  the symptom (`.mismatch` for a bad claim-level alg), this verifier
  returns the specification's code. Every one is a failure on both sides.
- `hashed-uri-truncated`, `uri-alg-sha384` and `claim-redacted` change the
  store's length by −1, +27 and +65 bytes, so their `assertion.dataHash.mismatch`
  is the exclusion range no longer fitting the chunk — SPEC-012's
  business, not a property of the hashed-URI edit.
- Next: the SPEC-011 tests (step 24b), red on the missing
  `Hash\HashedUriCheck`, the three enum cases and the private
  `assertionStore`.
