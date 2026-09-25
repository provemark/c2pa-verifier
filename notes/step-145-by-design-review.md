# Step 145 — The 38 differences by design, held against ADR-0005

*2026-09-25. Prepared for Maurice van Loon's decision, and decided the same
day (see *Decided* at the end). No code or test changed. Everything below is
reasoned from the rows themselves and the specs and notes they name. Where
a row's protection would need a measurement to be sure, that is said.*

## The test

ADR-0005: this verifier may be **stricter than `c2patool`** only where the
strictness prevents a wrong `Valid` or trust in something unchecked.
Elsewhere it follows `c2pa-rs`.

The test only applies to rows where this verifier refuses what the current
`c2patool` accepts. Many rows under *"Where this verifier differs by
design"* are not like that. They record where this verifier and `c2patool`
share a leniency the spec text does not have, or where only the report's
shape differs. ADR-0005 allows those as they are.

## The 38 rows, sorted

Numbers are the rows' order in the table today.

### A — stricter, and the protection is named (keep): 9 rows

| # | row | what it prevents |
|---|---|---|
| 8 | the allowed list never trusts a timestamp authority | a TSA on the list excusing an expired signer (step 114, measured): a wrong `Valid` |
| 11 | a `trust.anchors` entry anchors only its own `trust_kind` | an ordinary S/MIME certificate from the `"cawg"` list signing C2PA content as `Trusted` |
| 13 | an unknown key in an anchors entry, or an `allowed_list` on a `"tsa"` entry, is refused | a mistyped key silently dropping a restriction, so trust wider than configured; a TSA trusted through the allowed list (row 8) |
| 16 | a timestamp authority is trusted only through anchors | trust by observation (ADR-0004) |
| 21 | a redacted v1 ingredient manifest is judged by its box hash | an ingredient manifest that nothing binds passed on trust |
| 26 | a zero-length `subset` that is not the last is `malformed` | bytes after it escaping the BMFF hash. *Reasoned from C2PA 2.4, not measured against `c2pa-rs`'s hashing.* |
| 29 | a CAWG identity is `Invalid` until validated | a verdict on a credential nobody examined |
| 34 | a hash assertion in an update manifest is `manifest.update.invalid` | an update manifest rebinding the asset. `c2pa-rs`'s own rule for this is unreachable code |
| 38 | the command's exit status carries the verdict; an unreadable `--settings` is exit 2 | `c2pa-verify "$f" && publish "$f"` publishing a tampered file; a mistyped path turning `Trusted` into an unexamined `Valid` |

### B — stricter, and nothing named that it prevents (candidates to follow `c2pa-rs`): 4 rows

| # | row | today's reason | what following `c2pa-rs` would mean |
|---|---|---|---|
| 4 | an external reference with `alg` without `hash`, or the reverse, is `assertion.external-reference.malformed`; both `c2patool` versions read it as unhashed and accept it | C2PA 2.4 §15.10.3.2.2's text | read it as unhashed. An unhashed external reference is allowed anyway, so no binding is lost. SPEC-032 AC5, weight A |
| 18 | a `signingTime` that differs from `genTime` makes the token `malformed`; `c2pa-rs` uses `signingTime` | *"fail closed; no corpus token has them differ"* | use `signingTime`. Both times are inside the TSA's signature, so neither is unchecked. ADR-0004 decision 5, weight A |
| 24 | a `c2pa.redacted` reference to a data box is `assertion.notRedacted`; `c2pa-rs` passes it when the claim's own `redactions` lists it | *"no builder writes one, so the pass route cannot be measured"* | pass it as `c2pa-rs` does. The reason given is a missing fixture, not a protection. SPEC-037, weight A |
| 30 | a COSE header with both `sigTst` and `sigTst2` is `malformed`; `c2pa-rs` takes `sigTst2` | *"fail closed; no corpus file has both"* | take `sigTst2`. Each token must still match the signature and reach a trusted TSA. SPEC-016 AC8, weight A |

None of the four occurs in any file measured so far, so changing them
moves no verdict in the corpus.

### Borderline — stricter, and the protection is arguable: 2 rows

| # | row | the case for keeping | the case against |
|---|---|---|---|
| 12 | a top-level `trust.allowed_list` is refused (exit 2); `c2patool` 0.28.0 drops it silently | the same settings file would otherwise mean `Trusted` here and `Valid` at `c2patool`, silently. It protects the shared-file promise | it prevents no wrong `Valid`: dropping the list makes `c2patool` trust *less*, not more |
| 20 | an ISOBMFF `uuid` box that announces C2PA but cannot be read is an error; `c2patool` says *"no claim found"* | a file whose credentials are broken is not a file without credentials, and saying so is true | neither answer is `Valid`, so no wrong `Valid` is prevented. The difference is in what the report says |

### ADR-0005 is silent: 1 row

| # | row | why it does not fit |
|---|---|---|
| 10 | a leaf whose only EKUs are C2PA claim-signing or documentSigning is accepted; `c2patool` 0.28.0 calls it `signingCredential.invalid` | here this verifier is **more lenient than the current `c2pa-rs`**, on the spec's side (§14.4.1). ADR-0005 speaks about being stricter than `c2patool`, and about being more lenient than the *spec* where `c2pa-rs` is. It says nothing about being more lenient than `c2pa-rs` where the spec is. The six OpenAI files of step 141 make this real |

### C — not stricter than the current `c2patool` (the test does not apply): 22 rows

Rows 1, 2, 3, 5, 6, 7, 9, 14, 15, 17, 19, 22, 23, 25, 27, 28, 31, 32, 33,
35, 36 and 37.

Each records either a leniency this verifier shares with `c2pa-rs` against
the spec text (1, 3, 6, 9, 14, 22, 28, 35, 36), agreement with `c2patool` 0.28.0 where 0.27.22 differed (2, 5, 7, 15), or a difference in the
report and not in the verdict (17, 19, 23, 25, 27, 31, 32, 33, 37).
ADR-0005 allows all of them as they are. Rows 7 and 15 would pass the
test anyway: 15 kept a changed EXIF date from staying `Trusted` (step
108, measured), and 7 keeps an asset from being bound by an assertion
the signer did not make.

## What is asked

1. **Group A (9 rows):** add the protection to each row's *why* column
   where it is not already there? That is text only.
2. **Group B (4 rows):** follow `c2pa-rs`, one row at a time, each as an
   amendment of weight A to the spec named, tests first? Or keep them, and
   extend ADR-0005 with a second ground, *"no file shows it, so no
   reading of `c2pa-rs` can be measured"*?
3. **Rows 12 and 20:** keep, with the argument written down, or move to
   the gaps?
4. **Row 10:** extend ADR-0005 with a line for being more lenient than
   `c2pa-rs` where the spec supports it? The proposal: allowed when the
   spec's text is clear, and it is recorded here as a difference by
   design.

## Decided

Maurice van Loon took the four proposals as they stood:

1. **Group A:** the three rows whose *why* did not yet name a protection
   now do (13: a mistyped key widening trust; 26: bytes escaping the BMFF
   hash, reasoned; 34: an update manifest rebinding the asset). The other
   six already named it.
2. **Group B:** rows 4, 18, 24 and 30 moved from *"differs by design"* to
   *"`c2patool` can do more"*, each with *"a file that carries it; none
   has so far"* as its trigger. Their behaviour is unchanged. Following
   `c2pa-rs` on any of them is a weight-A amendment, tests first, once a
   file shows the case. ADR-0005 was **not** given a second ground for
   strictness.
3. **Row 12** stays, with the argument written into its row: it prevents
   no wrong `Valid`, but a shared settings file whose meaning differs
   silently is a trust fault. **Row 20** moved to the gaps like group B.
4. **Row 10:** ADR-0005 has an addendum. Being more lenient than `c2pa-rs`
   is allowed where the spec's normative text is clearly on this
   verifier's side, and never on examples or an older `c2pa-rs` alone.
   The row points at it.

`docs/comparison.md` now has 33 rows under *"differs by design"*.
