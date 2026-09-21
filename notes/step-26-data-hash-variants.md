# Step 26 — The store's range measured, twelve data-hash variants through c2patool, before the SPEC-012 tests

*2026-09-21.* SPEC-012 is approved. Two things had to be measured before
a test could be written: whether the *exact-range* rule for the store's
exclusion (AC3) holds on real files, and what c2patool says about the
twelve variants AC4–AC8 name. No verifier code.

## Measured: the store's range equals the exclusion, exactly

A throw-away probe (not committed) walked each container the way the M1
extractors do — JPEG marker segments, PNG chunks, WebP RIFF chunks — and
recorded, per piece that carries the store, `[offset, length]` from the
first byte of the piece's framing to its last data byte; then merged
contiguous pieces and compared with the `exclusions` of the file's own
`c2pa.hash.data` assertion:

| file | pieces | merged | store | framing | exclusion | |
|---|---|---|---|---|---|---|
| `fixture-signed.jpg` | `[20, 64012]`, `[64032, 30760]` | `[20, 94772]` | 94,740 | 32 | `[20, 94772]` | **exact** |
| `adobe-20220124-C.jpg` | `[20, 51130]` | `[20, 51130]` | 51,118 | 12 | `[20, 51130]` | **exact** |
| `fixture-signed.png` | `[33, 46037]` | `[33, 46037]` | 46,025 | 12 | `[33, 46037]` | **exact** |
| `fixture-signed.webp` | `[312, 100643]` | `[312, 100643]` | 100,635 | 8 | `[312, 100643]` | **exact** (the pad byte at 100,955 is outside) |
| `jpeg/gap-between-pieces.jpg` | `[20, 64012]`, `[64050, 30760]` | two ranges | 94,740 | 32 | `[20, 94772]` | **differs** — the COM segment sits between the pieces |

So `ManifestStoreBytes::$ranges`, as SPEC-012 defines it, reproduces the
writer's exclusion byte for byte on all four fixtures, and the gap
variant is exactly the case the rule refuses. Step 23's framing numbers
(32/12/12/8) are confirmed from the other side.

## Made: `bin/make-data-hash-variants.php`

The same helpers as steps 23 and 24, plus two of its own: `dataHash()`
(the hash of bytes minus ranges — tooling, in memory) and `rehashed()`,
which recomputes the assertion's `hash` over the carrier PNG minus the
store's range and any extra ranges, then recomputes the claim's hashed
URI for the assertion, so that only the signature is left broken. The
point of that effort is the oracle: if c2patool then says
`assertion.dataHash.match`, the script's streaming arithmetic — the
same rule the verifier will implement — is right before any of it is in
`src/`.

Three variants were wrong on the first build and caught by a parse
probe before c2patool saw them: `alg-missing` (the store shrank by 11
bytes, its exclusion had not), `alg-sha384` (the 48-byte hash written 16
bytes too far, after an offset "correction" that corrected the wrong
thing), `hard-binding-bmff` (the claim's LBoxes not shifted by the
longer label). Each fix is one line; each would have shown up as a
mismatch and been mistaken for a property of the verifier.

## Measured: c2patool 0.27.22 on the twelve PNGs

| variant | c2patool | SPEC-012 |
|---|---|---|
| `exclusion-extra` | **`assertion.dataHash.match`** + informational `additionalExclusionsPresent`; only the signature fails | AC4 ✔ — the arithmetic confirmed |
| `exclusions-unsorted` | the same | AC5 ✔ (sorted first) |
| `exclusions-not-list`, `exclusion-start-negative`, `exclusion-length-text` | `Error: could not decode assertion c2pa.hash.data …` — no report | AC6 `.malformed` (a report; divergence of the usual kind) |
| `hash-as-text` | decodes; `dataHash.mismatch` | AC6 `.malformed` — stricter: a text string is not a hash |
| `exclusions-too-many` | decodes 1,025 ranges; `dataHash.mismatch` + the informational | AC6 `.malformed` — a bound, where c2patool has none |
| `alg-missing` | **`dataHash.match`** — the claim's `alg` applied | AC7 ✔ |
| `alg-sha384` | **`dataHash.match`** — the assertion's `alg` applied | AC7 ✔ |
| `hard-binding-missing` | `Error: claim missing hard binding` — no report | AC8 `claim.hardBindings.missing` |
| `hard-binding-bmff` | `Error: could not decode assertion c2pa.hash.bmff.v2 … missing field xpath` | AC8 `general.error` (M8) |
| `hard-bindings-two` | **`assertion.multipleHardBindings`**, url `self#jumbf=/c2pa/urn:c2pa:488bf983-…` — the *manifest*, explanation `claim has multiple data bindings`; two `dataHash.match` | AC8 — **amended**, see below |

## One criterion amended by the measurement

AC8 said the two hard-binding codes carry the *claim box's* url.
c2patool puts `assertion.multipleHardBindings` on the manifest's URI,
`self#jumbf=/c2pa/<label>`, and since the missing case is a hard error
there, it follows by analogy. SPEC-012 amendment 1 changes AC8 and Scope
item 1 to the manifest's URI, and makes explicit that the count is of
boxes in the store, not labels (two boxes under one label are two —
which is what the variant is). Nothing else changed. The first Open
question of the spec foresaw exactly this: a c2patool answer that
contradicts a criterion amends it before the tests.

## What this settles

- The exact-range rule is measured, not assumed, on four writers'
  output (c2pa-rs 0.90 through the sister service for three, Adobe's
  2022 tool for one).
- The streaming hash the verifier will compute is already confirmed by
  the oracle on three variants whose only fault is the signature.
- Next: the SPEC-012 tests (step 26b), red on the missing
  `Hash\DataHashCheck`, the six enum cases and the missing `$ranges`.
