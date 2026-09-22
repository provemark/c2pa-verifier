# Step 80 — M8 is not done, and the record said otherwise

*2026-09-22.* Maurice asked whether M8 was finished. It is not, and three
tracked files said it was. This step is the correction, written before any
further work, because a wrong claim in the record costs more the longer it
stands.

## What went wrong, plainly

**SPEC-027 was implemented and I treated that as M8 being done.** They are
different things. `docs/milestones.md` carries a "done when" column for
every milestone and M8's own description names *"Merkle trees (fragmented
files only), exclusions"* — neither of which is built. The spec closed; the
milestone did not. I did not re-read the milestone against the work before
writing that it was complete.

**And MOV was claimed before it was measured.** The README, the comparison
table and the milestones row all said MP4, MOV and AVIF. MP4 and AVIF had
fixtures, an oracle and tests. MOV had nothing: no fixture, no recorded
`c2patool` verdict, no test. It was written because ISOBMFF covers it in
principle, which is a reason to *expect* something, not to *state* it.

Measured afterwards, MOV does verify — `Trusted`, hard binding included,
and `c2patool` calls the same file `Valid`. That the guess was right is
luck, not method, and it is the part worth being uncomfortable about: a
claim that happens to be true is indistinguishable in the record from one
that was checked.

## The state of M8, measured

| | stand |
|---|---|
| MP4, AVIF | done — fixtures, recorded `c2patool` verdicts, tests in `SPEC-026` and `SPEC-027` |
| MOV | verifies, measured once in a scratch directory; **no fixture, no oracle, no test** |
| HEIC | unmeasured; SPEC-026's scope calls it "comes along for free", which is an assumption |
| Fragmented BMFF, Merkle trees | not built; refused by name. Named in M8's own description |
| `subset`, `length`, `version`, `flags` filters | not built; refused by name |
| Nested exclusion paths (`/moov/trak`) | not built; refused by name |

Nothing in that list is silently wrong: every missing case is refused with
its own message, and no ISOBMFF file can come back `Valid` with something
unchecked. The defect was in the *description*, not the verifier.

## What was corrected here

- `README.md`: says MP4 and AVIF are covered by tests, and that MOV
  verifies but has no fixture yet.
- `docs/comparison.md`: one ISOBMFF row naming exactly what is verified,
  what is unmeasured, and what is refused.
- `docs/milestones.md`: the SPEC-027 row no longer claims MOV, and points
  here.

Nothing in `src/` changed. 397 tests still pass.

## The lesson, and it is not "be more careful"

Three times in this project a measurement has settled what a reading got
wrong, and each time the answer was cheap. This is the same shape from the
other side: **writing a claim is also cheap, and that is the danger.** A
sentence in a README costs nothing to type and inherits all the authority
of the fixtures beside it.

The rule this project already has — *measured ≠ reasoned, and say which* —
was not applied to a sentence about formats because it did not feel like a
measurement. It was one. "MOV works" is a measurable statement, and it was
written in the voice of something measured while sitting next to two things
that actually were.

## What would finish M8

1. A MOV fixture with its oracle and a test, and HEIC measured the same
   way. Small, and it turns this correction into something the suite holds.
   It needs an amendment to SPEC-026 AC9 and SPEC-027 AC1, whose literals
   say "the two fixtures".
2. A fragmented fixture, then the Merkle spec. This is the real remainder
   and the part the brief warned about.
3. The four filters and nested paths, if and when a file exists that uses
   them.

---

# 80b — the claim made true

MOV and HEIC are fixtures now, each signed with c2patool 0.27.22 and the
test certificates, each with its recorded verdict beside it:

| flavour | this verifier | `c2patool` 0.27.22 | where the file came from |
|---|---|---|---|
| mp4 | `Trusted` | `Valid` | the sister repository, unchanged |
| mov | `Trusted` | `Valid` | the sister repository, unchanged |
| avif | `Trusted` | `Valid` | the sister repository, unchanged |
| heic | `Trusted` | `Valid` | made here from `fixture-unsigned.png` with macOS `sips`, so nothing third-party enters |

397 tests pass, `composer check` exit 0. HEIC needed making because no
repository this project can reach had one; that it works was not known
until it was tried.

## Two amendments, and the rule that was missing

**SPEC-026 AC9** asked only for AVIF — the flavour that happened to be in
hand when it was written. It now covers all three of AVIF, MOV and HEIC,
and carries the sentence that should have been there from the start:

> No ISOBMFF flavour may be named in the README, in `docs/comparison.md`
> or in a milestone row unless a fixture here holds it.

**SPEC-027 AC1** said "the two fixtures", which was true when written and
stopped being true the moment MOV and HEIC were claimed elsewhere. It
covers four now. No rule changed: the same digest over the same algorithm,
on two more flavours of the same container.

Both await confirmation.

## What is left of M8

| | stand |
|---|---|
| MP4, MOV, AVIF, HEIC | done — fixtures, oracles, tests |
| Fragmented BMFF, Merkle trees | **not built**; refused by name. Named in M8's own description |
| `subset`, `length`, `version`, `flags` filters | not built; refused by name |
| Nested exclusion paths | not built; refused by name |

M8 remains open, and the one thing standing between it and closed is the
fragmented case — which is also the part the brief warned about, and the
part where c2pa-rs was still fixing its own bugs when this project started.
