# Step 426 — The open rows of the 2.4 reading sorted

*2026-10-25.* The second of four steps towards "is all of 2.4 there?"
(`docs/milestones.md`). Step 425 brought `docs/reading-c2pa-2.4.md` up to
date; 60 rows stayed open (53 partial, 7 candidates). This step sorts them
by what the gap can do to a verdict. Documentation only.

## The question per row

Can the gap give a `Valid` or `Trusted` that `c2patool` would not give, or
one that leaves an asset byte or the signature unchecked? That is the only
gap that counts under the project's first rule (fail closed) and ADR-0005
(stricter than `c2patool` only where it prevents a wrong `Valid`).

## The result

| group | rows | what to do |
|---|---|---|
| A. Possibly more lenient than `c2patool` | 5 | probe first: four questions |
| B. The letter stricter than both implementations | 27 | keep; record |
| C. Already stricter than `c2patool` | 7 | keep |
| D. No verdict depends on it | 7 | nothing |
| E. Not supported, fails closed | 6 | could be built (non-fragmented Merkle) |
| F. Another code, the same verdict | 8 | nothing |

The four questions of group A:

1. A claim without `alg`, with a BMFF hash that has none either: SHA-256
   here; `c2pa-rs` refuses such a claim outright (read in `store.rs`).
2. A claim `alg` outside §13.1 that no entry falls back on: not refused
   here; `c2pa-rs` not read.
3. A `c2pa.opened` with one good and one unresolvable reference: passes
   here; `c2pa-rs` lets the last reference decide (read).
4. An ingredient's own stapled `revoked`, with no certificate-status
   assertion in the store: not read here; `c2patool` not measured.

## Measured and reasoned

- Measured: nothing new. Group B's equality with `c2pa-rs` comes from the
  source reading of 2026-10-09; it is measured only where the row names a
  test (`AnchorValidityTest` AC12 for CA validity).
- Reasoned: that no group-B row lets an asset byte leave the hard binding
  or a signature go unchecked. Each concerns values the signer wrote
  inside the signed claim.

## Next

The four probes, each built as a fixture and judged by `c2patool` 0.28.3
and this verifier. A probe that shows this verifier more lenient becomes a
spec; one that shows them equal or this verifier stricter becomes a row in
`docs/comparison.md`.
