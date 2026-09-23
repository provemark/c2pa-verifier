# Step 11 — The JUMBF parser (SPEC-005 implemented); the `Support` layer

*2026-09-21. Oracle: the trees and hashes measured in step 09, the
variants of step 10.*

## What was built

`src/Jumbf/`, the second layer of the verifier:

- `JumbfParser` — `parse(string $bytes): Superbox`. Recursive; every box
  validated before its children; the depth counter checked when a
  superbox is entered, the box counter when a header is read.
- `Superbox`, `DescriptionBox`, `ContentBox`, `UnknownBox` — `readonly`
  value objects with offsets and lengths. `Superbox::payload()` is the
  range C2PA 2.4 §8.4.2.3 hashes; the superbox keeps the whole store as
  one copy-on-write string rather than a copy per node.
- `JumbfWalk` — one parse's state (the bytes, the box counter) and the
  header check every box goes through: fits before its parent's end, LBox
  not 0 or 1, not below 8, counted against the limit.
- `JumbfException`.

And `src/Support/Bytes.php` — `hex()` and `printable()`, moved out of the
Container layer (SPEC-004 amendment 1) into a new `Support` layer that
`deptrac.yaml` lets every parser use. The JPEG extractor's two inline hex
spots now go through it too.

## Where the decisions of the spec landed

- **Unknown type UUID → `UnknownBox`** (AC7): `child()` peeks at a child
  superbox's description box first; a UUID outside `KNOWN_SUPERBOXES`
  keeps the box with its uuid, label and bytes, and does not walk it.
  Unknown *content* box types are kept the same way (the third open
  question, answered "keep" for assertions; claim and signature superboxes
  are not yet held to "one cbor box" — that is SPEC-007's reading of
  them).
- **Compressed and update manifests → error** (AC13): `refuseUnreadable()`
  on every description box; `brob` in `child()`.
- **The root has no parent** (AC10): its header is read unbounded and the
  walk is what notices a root that claims more than the store holds
  (`its children end at 46025 but its LBox ends it at 46026`). The first
  run had the root bounded by the store and reported the overrun message
  instead — 18 of 19 green; one line fixed it.
- **Labels in messages are hex** (AC12): `label 63 32 70 61 2F …`. The
  label rules of §11.1.4.1.1 are one regular expression over UTF-8 plus
  `mb_check_encoding`; invalid UTF-8 (which is how surrogates arrive) is
  the same error.

## Measured

- Red: 19 tests on the missing classes (`47f9ded`).
- After the parser: 18 passed, 1 failed (AC10, above).
- After the fix and the `Support` move: `composer check` → exit 0:
  spec-check `OK: 6 spec(s), 6 test file(s)`, Pint passed, PHPStan `No
  errors` (one finding on the way: an unused field in `JumbfWalk`,
  removed), Deptrac `Violations 0` with the new layer, Pest **90 passed
  (287 assertions)** — the 71 of M1/SPEC-004 unchanged in outcome.
- The parser on the four real stores: the step-09 trees, box for box;
  the three assertion payloads of the PNG hash to the claim's values.

## Reasoned, not measured

- The `uuid` content-type UUID (`75756964-…`) is in `KNOWN_SUPERBOXES`
  from the JUMBF text as C2PA cites it; no fixture carries one.
- `end()`-style costs do not apply here: the store is in memory; `substr`
  on a shared string is the only copy per content box.
