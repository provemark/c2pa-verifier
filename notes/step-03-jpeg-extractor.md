# Step 03 — The first code: JPEG APP11 → manifest store bytes

*2026-09-19. Implements SPEC-001. Oracle: c2patool 0.27.22; c2pa-rs
`sdk/src/asset_handlers/jpeg_io.rs` on `main`, read the same day.*

## What was built

Three files under `src/Container/`, the first code in the repository:

- `ManifestStoreBytes` — a `readonly` value object with one property, the
  reassembled JUMBF box from LBox to its last byte. Nothing interpreted.
- `ContainerException` — one exception for every malformed or out-of-order
  case. There is never a partial result: whole store, `null`, or throw.
- `JpegManifestStoreExtractor` — walks the marker segments from SOI to SOS
  with `fread` for headers and `fseek` for bodies it does not need. For every
  APP11 whose payload starts with `JP` it reads the 16-byte piece header
  (CI, En, Z, LBox, TBox — the layout measured in step 02), checks the
  limits, then the sequence, then agreement with the first piece, and only
  then reads the piece's data. LBox and TBox go into the result once, from
  the first piece; the data of every piece follows in order.

The order of the checks matters for AC9: LBox is compared to the limit
before any data is read, so an oversized box costs 40 bytes of reading,
not 64 MiB.

## Measured

- Before the code: `vendor/bin/pest` → 14 failed, 11 passed (the tests-first
  commit `4b3ff90`, re-run after the Pest 4 pin).
- After: `composer check` → exit 0: spec-check `OK: 2 spec(s), 2 test
  file(s)`, Pint passed, PHPStan `No errors`, Deptrac `Violations 0`, Pest
  **25 passed (58 assertions)**.
- The store: 94,740 bytes, SHA-256
  `f47af93e8afe0f71912e4c7545184ace2fb2cd5628b4a3ac832e591ac33546a3` (AC1),
  the same hash from `gap-between-pieces.jpg` (AC4) and `app11-not-jp.jpg`
  (AC8).

## A fixture was wrong, and what the correct one revealed

The first run left one test red, AC7: the extractor reported `LBox
1914008084 exceeds the limit` on `lbox-differs.jpg`. A hexdump showed why:
`bin/make-jpeg-variants.php` had `$lboxOffset = 10`, but LBox sits at
payload offset 12 (marker 2 + length 2 + CI 2 + En 2 + Z 4). The variant
had overwritten the last two bytes of Z and the first two of LBox, so
piece 2 carried Z = 1 and LBox = 0x72157214. c2patool's `invalid embedded
file box` on that file — recorded in step 02 as the AC7 oracle — was the
result of the broken Z (a second piece with Z ≤ the count is dropped, and
the box is then 30,740 bytes short), not of a differing LBox.

The offset was corrected, the variants regenerated (only `lbox-differs.jpg`
changed; new SHA-256 `13f3eb14…`), and the corrected file measured:

```
c2patool tests/Fixtures/jpeg/lbox-differs.jpg
→ validation_state: Valid; claimSignature.validated, assertion.dataHash.match
```

**c2patool accepts a box whose second piece claims a different length.**
Reading `read_c2pa` in `jpeg_io.rs` shows why: for the first piece it appends
`raw_vec[8..]` (LBox, TBox and data); for every continuation piece with the
same En and `z > count` it appends `raw_vec[16..]` — the comment says
`// take out LBox & TBox` — without reading those fields. CI is bound to
`_ci` and never compared to `JP`; the box is recognised by the bytes
`c2pa` at payload offset 24 (the start of the description box UUID). So on
this file c2patool assembles exactly the 94,740 bytes of the untouched
fixture, and its `Valid` is a correct verdict on those bytes.

Two more things the same function shows, noted for later specs:

- Continuation is accepted when `z > count`, not `z == count + 1`: a
  sequence 1, 3 passes; 1, 1 does not.
- The whole file is read with `read_to_end` before parsing.

## Decided: AC7 stays an error

The maintainer decided on 2026-09-19 to keep AC7 as approved ("behouden
voor nu"). The reasoning:

- A divergence from the oracle can go two ways. Here the verifier says
  *error* where c2patool says *Valid*: the user sees a rejection of a file
  that carries a consistent store, which is inconvenient but leads nobody
  to trust something untrustworthy. The other direction — *Valid* where the
  oracle says *error* — is the one failure this project exists to prevent,
  and this divergence is not of that kind.
- LBox and TBox are repeated in every piece by design; a piece that
  disagrees with its siblings about the box's own length is a malformed
  container, and refusing malformed input is the fail-closed rule.
- C2PA 2.4 §A.3.1 (quoted in step 02) only demands "sequential" and
  "contiguous" and points to ISO 19566-5 D.2 for the layout, which was not
  read. Nothing measured says a reader must compare the repeated fields;
  nothing says it may not.

Reversing this needs an amendment to SPEC-001, not a code change. The
divergence is recorded in `tests/Fixtures/jpeg/README.md` next to the file.

## Two small edits outside `src/`

- The test helper `spec001Bytes()` and AC12 used `?->bytes ?? ''` on a
  nullable result; PHPStan level max flags the nullsafe as redundant under
  `??`. Changed to `->bytes ?? ''` — same semantics (`??` is isset-like and
  silent on null), no criterion touched.
- `readExactly()` rejects a negative length with a `LogicException`; PHPStan
  could not prove `fread` never received one.

## Amendment 1: two fixtures for what was guarded by reasoning only

The maintainer walked through the code and asked for a fixture for the
end-of-file probe in `skip()`, and for an explanation of the `< 0xC0`
marker boundary. Explaining the boundary against ITU-T T.81 Table B.1
showed it was wrong: it catches TEM (`01`) and the reserved codes
(`02`–`BF`) but not RST0–7 (`D0`–`D7`), which also carry no length field.
Two variants were built and measured before the amendment was written:

```
c2patool tests/Fixtures/jpeg/truncated-in-app0.jpg
→ Error: asset could not be parsed: Could not parse input JPEG
c2patool tests/Fixtures/jpeg/rst-before-sos.jpg
→ Error: No claim found
```

The second is c2patool doing what this spec forbids: after `FF D0` it
reads the next two bytes (`FF EB`, the marker of piece 1) as a length of
65,515, skips piece 1, finds piece 2 without a first piece and reports
"no claim" — an error, but one that hides the cause. Our extractor did the
same and failed further on with `expected a marker at offset 65537, found
A7`. With `hasLengthField()` (the table, not a number) it now says
`unexpected marker FF D0 at offset 20 before SOS`. Stricter than the
oracle, in the safe direction, and it names the byte to look at.

**A claim in the walkthrough was wrong, and the mutation test found it.**
The explanation said that without the probe a file truncated before the
first piece would yield `null`. Removing the probe and running AC14 showed
the test still green: the loop can only reach `return null` through the
`break` at SOS, and a truncated file never gets there — the next
`readMarker` hits end of file and throws. What the probe actually buys is
the *offset in the message*: the truncated segment (2) instead of the
position after it (20). The first version of AC14 could not tell those
apart either (`'offset 2'` matches `'offset 20'` as a substring); it now
asserts the whole message, and with the probe removed it is red:

```
mutant: unexpected end of file while reading marker of the segment at offset 20: wanted 1 bytes, got 0
```

AC15 was red before the change (the misleading offset 65537) and green
after. Final: `composer check` exit 0, 27 passed (62 assertions).

## Reasoned, not measured

- The default limits (2,048 pieces, 64 MiB) are the spec's sketch; no
  measurement says what the largest real-world store is.
- LBox below 8 (0 = "to end of file", 1 = 64-bit XLBox in ISO BMFF) is
  rejected: neither can be reassembled from fixed-size pieces, and no C2PA
  writer is known to produce them. Untested by a fixture; fail closed.
- Fill bytes (`FF FF …`) followed by anything but a marker are an error.
  (The marker rule itself moved from reasoned to measured in amendment 1.)
