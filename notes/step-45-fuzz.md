# Step 45 — Light fuzzing: 70 870 mutated files through the verifier, no exception escaped, every surviving `Valid` confirmed by c2patool

*2026-09-22.* The bounded-parser claim of this project — every parser
has hard limits, every fault is a report, never an exception, never a
silent `Valid` — rested until now on hand-made edge cases. This step
throws random damage at it. `bin/fuzz.php` (kept in the repository, so
that any seed replays) mutates every corpus file eight ways and runs
`Verifier::verify()`; two outcomes are forbidden whatever the input:

1. an exception escaping the verifier;
2. `Valid` or `Trusted` on a mutated file — unless c2patool says the
   same of the same bytes.

## The mutations

Per file and round, in rotation: `flip1`, `flip8`, `flip64` (that many
random bits anywhere), `truncate` (the file cut at a random offset),
`block` (16 random bytes over a random offset), `store8` and `store64`
(bit flips confined to the manifest store's byte ranges — the JUMBF,
CBOR, COSE and DER parsers rather than the data hash), `storecut` (the
file cut inside the store, so that every length field lies). The seed
is the first argument and `mt_srand` makes a run replayable — measured:
two runs of seed 7 print the same suspects (md5-equal).

## The runs (measured)

| seeds | rounds/file | runs | faults | `Valid` suspects | c2patool on the suspects |
|---|---|---|---|---|---|
| 1–5 | 60 (six kinds, first script) | 29 400 | 0 | 139 | 139 `Valid`, 0 otherwise |
| 11–13 | 80 (eight kinds) | 22 620 | 0 | 88 | 88 `Valid` |
| 100 | 200 (eight kinds, final script) | 18 850 | 0 | 85 | 85 `Valid` |
| **total** | | **70 870** | **0** | **312** | **312 agree** |

101 files (the four corpora, the binding variants, the three signed
fixtures); slowest single run 0.03 s; peak memory 36 MiB (the 5.8 MB
PNG included; `memory_limit` 512M offered, never approached). No
`Trusted` ever (no settings), no run slower than the limits would
notice.

## The 312 that stayed `Valid`, and why that is right

Every one was put through c2patool 0.27.22 and every one is `Valid`
there too. Where the damage landed (measured on samples with the
extractors and `CoseSign1`):

- **The COSE `pad` header.** c2pa-rs reserves space for the signature
  box and fills it with a `pad` of zero bytes that nothing signs or
  hashes; `c2pa-ts`'s file has 23 665 bytes of it in a 27 070-byte
  store, which is why that one file yields 144 of the 312 (and all 56
  `store8` suspects: eight flips that all miss the covered bytes happen
  only where the pad is most of the store). A flip there changes nothing that
  any check covers, by design of the format.
- **The timestamp token.** `C.jpg`'s signature box is 15 740 bytes, most
  of it the 5 942-byte token and 5 819 bytes of pad; a flip in the token
  makes `timeStamp.malformed` or `.mismatch` — informational, the state
  stays `Valid` (SPEC-017, c2pa-rs the same).
- **APP11 segment headers** and JUMBF box lengths inside the exclusion
  that the data hash skips and no hashed URI covers.

So a `Valid` after damage is not a hole: it is the bytes the
specification leaves uncovered, and the oracle agrees on all 312.

## What did not happen

No `TypeError`, no `ValueError`, no `OutOfRangeError`, no
`DivisionByZeroError`, no memory or time blow-up, from any of the parsers
(JPEG/PNG/WebP segment walks, JUMBF, CBOR with floats and indefinite
lengths, COSE, the DER reader on the timestamp token) on 70 870 inputs.
Every truncation and every cut inside the store came back as a report
with a `general.error` or a `claim.*` / `assertion.*` code naming the
offset.

## Limits of this measurement

- "Light": random bit flips and cuts find crashes in length arithmetic;
  they rarely find logic that a crafted structure would (a JUMBF box
  claiming a size that lands exactly on a boundary, a CBOR map with a
  duplicate key in the right place). The hand-made variants of steps
  05–34 cover that ground; this step covers the ground between them.
- The oracle for the survivors is c2patool alone; a flip that both
  implementations wrongly accept would pass here. The format analysis
  above is the reason to believe there is no such flip in these 312.
- Nothing here exercises multi-manifest logic (refused until M7) or
  formats beyond the three.

`bin/fuzz.php` stays; a run with a new seed is one command, and a fault
it finds becomes a hand-made variant with a spec amendment, as every
finding so far has.
