# Step 86 — `c2pa.hash.bmff.v2`: not another digest, another exclusion list

*2026-09-22.* Step 85 found a real public file this verifier refuses:
`c2pa-rs`'s own `video1.mp4`, which `c2patool` calls `Valid` and which
carries `c2pa.hash.bmff.v2`. This step measures what v2 is, by the route
steps 76 and 77 established: instrument c2pa-rs rather than reason about
it.

Nothing in `src/` changed.

## The finding, in one line

**v2 and v3 share the digest exactly. What differs is the exclusion
list — and v2's uses the three filters SPEC-027 named as out of scope.**

## What v2's assertion holds

```
alg: sha256   name: "jumbf manifest"   hash: dce8c6a97993acaa…

exclusions (8):
  /uuid                                      data: [{offset: 8, value: <C2PA UUID>}]
  /ftyp
  /meta/iloc
  /mfra/tfra
  /moov/trak/mdia/minf/stbl/stco             subset: [{offset: 16, length: 0}]
  /moov/trak/mdia/minf/stbl/co64             subset: [{offset: 16, length: 0}]
  /moof/traf/tfhd                            subset: [{offset: 16, length: 8}]  flags: 01 00 00
  /moof/traf/trun                            subset: [{offset: 16, length: 4}]  flags: 01 00 00
```

Six of the eight paths are **nested**. Four carry a **`subset`**. Two carry
**`flags`**. SPEC-027 refuses all three by name, and now it is clear why
they existed in `c2pa-rs` and in none of this project's fixtures: they are
what v2 is made of.

v3, as `c2patool` 0.27.22 writes it today, excludes five whole top-level
boxes — `/uuid`, `/ftyp`, `/mfra`, `/free`, `/skip` — and nothing finer.
The two versions are not the same instruction with a different number; they
are two ways of saying which bytes the signer stood behind, and v2's is the
precise one.

## The digest is the same, measured

c2pa-rs instrumented and run on `video1.mp4` prints:

```
PROBE marker offset=30686
PROBE range 30686..=31736 (1051 bytes)
PROBE range 31761..=33309 (1549 bytes)
PROBE range 33334..=33391 (58 bytes)
PROBE marker offset=33392
PROBE range 33392..=37953 (4562 bytes)
PROBE marker offset=37954
…
```

Four markers for four included top-level boxes, and **no marker before the
continuation ranges**. A nested exclusion punches a hole inside a box; it
does not make a new box. That is exactly the rule SPEC-027 implements, so
the digest needs no change at all.

The two holes are measured too, and they pin `subset`:

| gap | inside | offset in box |
|---|---|---|
| 31737–31760, 24 bytes | `stco` at 31721, 40 bytes long | 16 |
| 33310–33333, 24 bytes | `stco` at 33294, 40 bytes long | 16 |

`subset: {offset: 16, length: 0}` — **length 0 means to the end of the
box**, 40 − 16 = 24. That matches `bmff_to_jumbf_exclusions()` read in step
76, and now it is measured rather than read.

## Two details worth carrying into a spec

**The second `uuid` box is hashed.** `video1.mp4` has two: the C2PA box at
24 and another at 33392. Only the first matches `data: [{offset: 8, value:
<C2PA UUID>}]`, and the second is included, marker and all. The data filter
is doing real work here, not ceremony — in every fixture this project made
itself, there was only ever one `uuid` box to tell apart from nothing.

**`free` is hashed under v2 and excluded under v3.** v2's list has no
`/free`; v3's does. A file is bound to the bytes its own assertion names,
which is the point, but it means a verifier cannot carry one default list
and apply it to both.

## What implementing v2 would take

Three filters and a label, and a fixture now exists for all of them:

1. **Nested `xpath` resolution** — walk into child boxes rather than
   matching only top-level types. SPEC-026's extractor already walks the
   top level; this needs the same walk one level deeper, bounded.
2. **`subset`** — narrow an excluded range, with length 0 meaning to the
   end of the box. Measured above.
3. **`flags`** with `exact` — `c2pa-rs` defaults `exact` to true, and
   `video1.mp4`'s two flag filters carry `exact: null`. Unmeasured: whether
   these two exclusions actually *match* in this file, since `tfhd` and
   `trun` live under `moof`, which a non-fragmented MP4 does not have. The
   spec must not assume they are exercised by this fixture.
4. Accepting the `c2pa.hash.bmff.v2` label alongside v3.

None of this touches the digest, the streaming reader, the marker rule or
the report. It is one function — `matches()` in `BmffHashCheck` — growing
from "top-level type, optional data" to the full filter set, and one label
added where the check is dispatched.

## What is still not known

Whether `/moof/traf/tfhd` and `/moof/traf/trun` ever match in a
non-fragmented file, and therefore whether `flags` can be *tested* with
this fixture at all. A fragmented v2 stream would settle it; this project
has a fragmented **v3** stream it built itself, and none in v2. That is the
one thing a v2 spec cannot measure with what is on hand, and it should say
so rather than implement `flags` against nothing.
