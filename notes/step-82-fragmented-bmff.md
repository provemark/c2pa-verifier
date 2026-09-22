# Step 82 — Fragmented BMFF, measured whole

*2026-09-22.* The fragmented case is all that stands between M8 and
closed, and the brief called it the least documented part of C2PA. This
step measures it end to end. Nothing in `src/` changed; the verifier still
refuses the whole stream by name.

## The stream had to be made

No repository this project can reach holds a fragmented C2PA stream, so
one was built: `ffmpeg` 8.0 splits this repository's own
`fixture-unsigned.mp4` into a DASH init segment and five `.m4s`
fragments, and `c2patool fragment --fragments_glob` signs the set. Both
the five-fragment stream and a seven-fragment one were made; the second
exists only to tell one tree shape from another.

`tests/Fixtures/bmff-fragmented/` holds the five-fragment stream, 40 kB,
with the commands that made it. A measurement without the file it was made
on is a claim.

## What a signed fragmented stream looks like

```
init.mp4 (14 480 B)        seg_1.m4s (2 313 B)
  @0     ftyp  28            @0    styp  24
  @28    uuid  13647         @24   sidx  52
         purpose='manifest'  @76   uuid  175
         merkle_offset=0            purpose='merkle'
  @13675 moov  805           @251  moof  184
                             @435  mdat  1878
```

The init carries the manifest; **each fragment carries a C2PA box of its
own** with `purpose: merkle`. And the init's `c2pa.hash.bmff.v3` assertion
has **no `hash` field at all** — a `merkle` list instead:

```
merkle[0] = {uniqueId: 1, localId: 1, count: 5, alg: sha256,
             initHash: <32 B>, hashes: [<32 B>]}
```

while each fragment's merkle box holds

```
{uniqueId: 1, localId: 1, location: 0, hashes: [<32 B>, <32 B>, <32 B>]}
```

— its leaf index and the sibling hashes from leaf to root. Five leaves, a
path of three; the seventh fragment of the other stream has a path of two.

## The algorithm, reproduced

Three things, and the first two are already built.

**1. `initHash` is the ordinary v3 digest.** The same rule step 77
measured — a big-endian `u64` of each included top-level box's offset,
then its bytes — applied to the init segment. Computed by hand it matches
`d944c92d824db6da…` exactly.

**2. A fragment's leaf hash is the same rule again**, applied to the
fragment, with the same exclusion list from the init's assertion — which
excludes the fragment's own C2PA box through `/uuid` plus the data match,
exactly as it excludes the manifest box in a whole file.

**3. The tree is unbalanced in a specific way.** The left subtree holds
the largest power of two smaller than the leaf count, the right holds the
rest, and a parent is `sha256(left ‖ right)`. Climbing from a leaf with its
proof reproduces the root in `merkle[0].hashes[0]`:

| stream | leaves | proofs checked | result |
|---|---|---|---|
| five fragments | 5 | 5 of 5 | every one reaches the root |
| seven fragments | 7 | 7 of 7 | every one reaches the root |

The seven-fragment stream is what pinned the shape. A simpler rule — take
the bits of `location` from the least significant end — verifies four of
the five leaves in the first stream and fails on the fifth, the lone leaf
that sits one level up. Measuring one stream would have produced a rule
that is right four times out of five, which is the most dangerous kind of
wrong.

## What this costs to build

Less than it looked. The digest rule is already implemented and is used
three times over: for a whole file, for the init segment, and for each
fragment. What is new is the tree: reading the merkle box, climbing with
the proof, and the shape rule above. The exclusions, the offset markers,
the streaming reader and the assertion parsing all stay as they are.

What is also new is a shape of input this verifier has never had: **more
than one file**. Everything here takes a single stream; a fragmented stream
is an init segment plus N fragments, and the public API has nowhere to put
them. That is a bigger question than the hash, and it belongs in the spec's
scope before any criterion is written.

## What is still not known

- `uniqueId` and `localId` appear in both the assertion and every fragment
  box, and both are 1 in these streams. What they are for — several
  renditions of the same content, presumably, which is what the `count`
  and the list of `merkle` maps are shaped for — is unmeasured.
- A stream with more than one `merkle` map, which is what multiple
  renditions would produce, has not been made.
- Whether `c2patool` verifies a fragment against a *wrong* init segment,
  and what it says, is unmeasured. That is the interesting negative case
  and the spec should ask for it.
