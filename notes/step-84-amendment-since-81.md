# Step 84 — The one amendment since step 81, confirmed

*2026-09-22.* 51 + 17 + 6 + 3 + 1 + 2 + 1 = **81**, which is what the
specs hold.

Legend for **weight**: **A** = a rule of the verifier changed; **B** = the
report's shape or the API changed, verdicts unchanged; **C** = a test
literal, a count, a message, a seam, or a layer line.

## B — the report's shape, the API

| spec | # | what | why | confirmed |
|---|---|---|---|---|
| SPEC-025 | 2 | The contract grows from nine classes to ten: `Verifier\FragmentedVerifier` | SPEC-028 gives a caller a way to offer a DASH init segment and its fragments. This is the **first class ever added to the promise** rather than the surface merely being recorded, and the recorded snapshot grew by two lines — `method merkleMapOf` and `method verify` | confirmed 2026-09-22 |

## A and C

None.

## What it costs a caller

Nothing that existed changes. `Verifier::verify()` has the same signature,
returns the same report, and every whole-file answer is what it was —
SPEC-028 AC7 asserts that on every run. What grew is a place to look: a
caller who needs fragmented streams now has a class for it, and one who
does not will never meet it.

The cost is the one named when the shape was chosen: someone who finds
`Verifier` and not `FragmentedVerifier` concludes fragmented streams are
unsupported. The README's Public API table and `docs/comparison.md` both
name it, and SPEC-025 AC5 fails if the README ever stops.

## Worth noticing: both alarms rang without being remembered

`ApiSurfaceTest` AC2 failed the moment the class landed — in neither the
contract nor marked `@internal`. AC5 failed until the README named it.
Neither is a rule anybody had to recall at the right moment, which is what
step 75's note asked of an alarm after SPEC-024 #1 slipped past one that
only enumerated what it already knew.

## Confirmation

**Confirmed by Maurice van Loon on 2026-09-22** ("bevestig het
amendement"). The amendment line in SPEC-025 carries the same stamp.
