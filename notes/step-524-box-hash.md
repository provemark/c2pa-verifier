# Step 524 — The box hash: refused, and never reached

*2026-11-10.* The last of the upstream changes step 521 named: `c2pa`
0.91.11 (upstream #2949) requires a box hash (`c2pa.hash.boxes`, C2PA 2.4
§18.6) to account for data after the last box it names. The question, as
in step 523: is this verifier more lenient there? Maurice: "akkoord, begin
met box-hash", then "akkoord, documenteer 524".

## Read

`DataHashCheck::check()` answers every hard binding that is neither
`c2pa.hash.data` nor a BMFF hash with `general.error`: *"the hard binding
c2pa.hash.boxes is not supported yet: BMFF, box and collection hashes are
M8 and later; only c2pa.hash.data is verified today"*. `Verifier` sends a
manifest whose binding is not a BMFF hash to that check. A manifest bound
only by a box hash is therefore never `Valid` here, with trailing data or
without. `docs/conformance.md` already records it (`PRED-CONT-006`).

Not more lenient, then. The difference runs the other way: a correct
box-hash file is `Invalid` here and may be `Trusted` in `c2patool`.

## Measured

1. The corpus holds two files with a box hash. Neither reaches the
   refusal:

   | file | `c2patool` 0.28.3 and 0.28.6, with and without trust | here |
   |---|---|---|
   | `writers/microsoft-20260609-bing-fast-heartbeat.jpg` | `Invalid`: `signingCredential.invalid` | `Invalid`: `claimSignature.missing` (its URIs lack the leading slash, so the signature box is not found) |
   | `hard-binding-redacted/hash-boxes-relative.png` | `Invalid`: `assertion.hardBinding.redacted` | `Invalid`: the same |

2. A file with a box hash that would reach the refusal could not be made.
   `c2patool` 0.28.6, given `{"builder": {"prefer_box_hash": true}}` in its
   settings, signed `fixture-unsigned.jpg` (throw-away root and signer,
   keys overwritten and deleted) with a `c2pa.hash.data` all the same. With
   23 bytes appended after EOI, all three said `Invalid`,
   `assertion.dataHash.mismatch`; untouched, all three `Trusted`. That
   measures the data hash, not the box hash. Box-hash signing appears to
   be reached through the `c2pa-rs` embeddable API only; that was not
   traced.

## What it means

- No wrong `Valid` here: the box hash is refused, not judged.
- No test holds that refusal for `c2pa.hash.boxes`. SPEC-012 AC8 holds it
  for a relabelled `c2pa.hash.bmff.v2`, a test written before M8 that now
  asserts the other message ("verified by BmffHashCheck"). If a change
  routed a box hash elsewhere, or let it fall through as "no binding", no
  test would turn red. The next step proposes one (SPEC-012
  amendment 12).

## What changed

- `docs/comparison.md`: a row under *Where `c2patool` can do more*.
- No code, no tests, no fixtures.
