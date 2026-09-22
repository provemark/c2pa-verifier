# Step 77 — The BMFF hash, reproduced

*2026-09-22.* Step 76 stopped because the stored hash could not be
reproduced and said what the next attempt should do: **instrument c2pa-rs
rather than read more of it.** That is what this step did, and it took one
run.

## The algorithm

For `c2pa.hash.bmff.v3`, over the asset as it stands:

> For each **top-level box that no exclusion matches**, in file order:
> hash the box's own offset as a **big-endian `u64`**, then hash the box's
> bytes.

Verified against both fixtures, byte for byte:

| file | what is hashed | digest |
|---|---|---|
| `fixture-signed.mp4` | `u64(13610)` + `moov` + `u64(14563)` + `mdat` | `87d4d42c438fe632766d…` — **matches** |
| `fixture-signed.avif` | `u64(13611)` + `meta` + `u64(13846)` + `mdat` | `81955e02ee8dee9c5305…` — **matches** |

The offsets are why this is not simply "the file minus some boxes": a box
whose bytes are untouched but whose *position* moved changes the digest.
That is the property the marker exists for, and it is what makes the
excluded C2PA box safe to exclude — everything around it is bound to where
it sits.

## What the instrumentation cost, against what the reading cost

Step 76 spent an afternoon on six hypotheses and got none of them right,
including two that were reconstructions of the very code being guessed at.
This step patched two lines into c2pa-rs's hashing loop —

```rust
eprintln!("PROBE range {}..={} ({} bytes)", start, end, end - start + 1);
```

— ran it in a container against our own two fixtures, and read the answer
off the output:

```
=== fixture-signed.mp4 ===
PROBE marker offset=13610
PROBE range 13610..=14554 (945 bytes)
PROBE marker offset=14563
PROBE range 14563..=16440 (1878 bytes)
state: Valid
```

The first attempt printed nothing, because the patch matched only the first
of two identical loops in that file. That is worth noting too: an
instrument that silently measures nothing looks exactly like an instrument
that measures zero.

The lesson is the one step 61 already taught with the Go verifier and step
65 taught again with the RSA-PSS trailer byte: **when a reading and a
measurement disagree, the measurement is cheaper than the third reading.**
Three times now this project has reasoned its way to a wrong answer that
one command settled.

## What this does not yet settle

Both fixtures begin with `ftyp`, which is excluded, so every included box
in them happens to follow an exclusion. A file whose **first** top-level
box is included would show whether the marker rule is "every included
box" — what the two files imply — or "every included box that follows an
excluded one", which they cannot distinguish. The spec must either measure
that case or state it as an assumption; it may not quietly pick one.

Nested exclusions are also unmeasured: every `xpath` in both files is a
single top-level segment (`/ftyp`, `/uuid`, `/mfra`, `/free`, `/skip`). A
path like `/moov/trak` would resolve to a nested box, and whether such a
box contributes a marker of its own — and how its exclusion splits the
parent's bytes — is not known from these two files.

## What follows

The spec can be written now, and it is smaller than it looked in step 73:
the conversion from box paths to byte ranges, the marker rule, and then
SPEC-012's existing streaming reader. The two unmeasured cases above belong
in it as named open questions, not as guesses.
