# Step 431 — The covered rows of §15.1–§15.9, each broken once

*2026-10-25.* Step 3 of the audit "Before 2.5: is all of 2.4 there?"
(`docs/milestones.md`). A row of `docs/reading-c2pa-2.4.md` marked
*covered* names the code that keeps the rule and a test that shows it. A
test that never fails when the rule breaks shows nothing, so this step
broke the code of each covered row in §15.1–§15.9 (45 rows) and looked
whether a test noticed. No code changes in this step: tests, two fixture
variants, one fixture set and the reading.

## How

A mutation per row: one exact replacement in `src/` (the check removed,
the condition forced, the code changed, the fallback added), the tests
the row cites run, then, if they stayed green, the whole suite; the file
restored byte for byte after each run and `git status src` checked clean.
35 mutations covered 40 rows; five rows (#3, #7, #17, #25, #37) name no
code of their own to break (a report shape, a rule c2patool keeps for us,
a principle) and were left as read.

## What was measured

| result | mutations | rows |
|---|---|---|
| red in the test the row cites | 26 | — |
| red only in other tests: the row cited the wrong test | 6 | §15.5.1 active manifest; §15.7 the `algorithm.unsupported` code, the leaf itself an anchor (twice), the signature check; §15.8.2 `messageImprint` |
| green everywhere | 3 | §15.4.1, §15.7 signature URI, §15.9 revoked CA |

The three that no test caught:

1. **§15.4.1, a data hash with no algorithm anywhere.** `DataHashCheck`
   refuses it with `algorithm.unsupported`; with a SHA-256 fallback added
   instead, every test stayed green. SPEC-027 amendment 9 (step 427) has
   a test for the BMFF hash only. New variant
   `tests/Fixtures/binding/alg-missing-everywhere.png`
   (`bin/make-data-hash-variants.php`: `alg-missing` with the claim's
   `alg` cut too); `DataHashCheckTest` AC7 now asserts the code. `c2patool`
   0.28.3 refuses the file outright ("unknown algorithm"), as step 427
   recorded for the BMFF case.
2. **§15.7, a signature URI that resolves to another box.**
   `Manifest::checkReferences()` refuses it as `claimSignature.missing`;
   with that check removed, every test stayed green. New variant
   `tests/Fixtures/claim/signature-uri-other-box` (`bin/make-claim-variants.php`:
   the URI set to `self#jumbf=c2pa.assertions`); `ManifestStoreTest` AC10
   asserts the code and the message. `c2patool` 0.28.3:
   `claimSignature.missing` too.
3. **§15.9, a revoked CA, in the branch where the walk meets the anchor as
   a certificate of `x5chain`.** A new fixture set,
   `tests/Fixtures/anchor-in-chain/` (`bin/make-anchor-in-chain-variants.php`:
   throw-away root, intermediate and signer, the root carried in
   `x5chain`, a stapled response about the intermediate), with a test
   under `RevocationBeyondStapleTest` AC1: revoked → `Valid` with
   `signingCredential.untrusted` naming the intermediate; no response or
   `good` → `Trusted`. The mutation **stays green**, and must: the walk
   reaches the anchor through the branch "the anchor issued this
   certificate" one link earlier. The other branch, "this certificate is an
   anchor", is reached past the leaf only when the earlier branch was
   refused for a fault that the walk's own link check then repeats; at the
   leaf the path holds no CA. Its revocation lookup cannot change a
   verdict: an equivalent mutant, recorded, the code left as it is. The
   fixture set stays: a signer that carries its root is a real shape, and
   the test now pins it. `c2patool` 0.28.1 and 0.28.3 say `Trusted` for all
   three; they read no staple about a CA (step 430, SPEC-030 open
   question 2).

Each new test was seen red under its mutation and green without:
`DataHashCheckTest` 1 failed, `ManifestStoreTest` 1 failed.

## What changed in the reading

Ten rows: the three above, the six that cited a test that does not catch
the break (now naming the test that does), and §15.8.1.1, whose citation
named a test title that amendment 9 had changed.

## What was reasoned

That the mutations are representative: each breaks the line the row
names, in the way a careless edit would. A row that survives a mutation
not tried is not proven; this step proves only that each row has at
least one test that a plain break of its code turns red.

## Not done

The covered rows outside §15.1–§15.9 (about 290). Their scope is
Maurice's choice.
