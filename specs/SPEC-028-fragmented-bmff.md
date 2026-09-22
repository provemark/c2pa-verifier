# SPEC-028: Fragmented BMFF — the Merkle tree, and a second kind of input

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | — while draft                                     |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

This is the last of M8. SPEC-026 and SPEC-027 read and verify a whole
ISOBMFF file; a fragmented stream — a DASH init segment and N `.m4s`
fragments — is refused by name, twice: SPEC-026 AC5 refuses a `merkle`
purpose and SPEC-027 AC5 refuses a `merkle` field. Both refusals are
correct and both are dead ends.

Step 82 measured what lifting them takes, on two streams built here with
`ffmpeg` and `c2patool fragment` because none was reachable.

**The shape.** The init segment carries the manifest, and its
`c2pa.hash.bmff.v3` assertion has **no `hash`** — a `merkle` list instead:

```
merkle[0] = {uniqueId: 1, localId: 1, count: 5, alg: sha256,
             initHash: <32 B>, hashes: [<root>]}
```

Each fragment carries a C2PA `uuid` box of its own with `purpose: merkle`:

```
{uniqueId: 1, localId: 1, location: 0, hashes: [<sibling>, <sibling>, <sibling>]}
```

**The three rules, all reproduced against the recorded values.**

1. `initHash` is the ordinary `c2pa.hash.bmff.v3` digest — SPEC-027's
   rule, an offset marker then the bytes, per included top-level box —
   applied to the init segment.
2. A fragment's leaf hash is that same digest applied to the fragment,
   with the same exclusion list, which excludes the fragment's own C2PA
   box through `/uuid` plus the data match.
3. The tree is unbalanced in one specific way: the **left subtree holds
   the largest power of two smaller than the leaf count**, the right holds
   the rest, and a parent is `sha256(left ‖ right)`. Climbing from a leaf
   with the proof in its box reaches `merkle[0].hashes[0]`.

Rule 3 is why two streams were built. The obvious reading — consume the
bits of `location` from the least significant end — verifies four of the
five leaves in the first stream and fails the fifth, the lone leaf that
sits one level up. A rule that is right four times out of five is the most
dangerous kind of wrong, and one stream would have produced it.

**And a second problem, which is not the hash.** Everything this verifier
does takes one stream. A fragmented stream is an init segment *and* N
fragments, and the public API — nine classes, ninety-three symbols, frozen
by SPEC-025 — has nowhere to put them. That is a contract question, and it
is the reason this spec has a blocking open question rather than an API
sketch it is confident about.

## Scope

**In scope**

- Reading the `merkle` list of an init segment's `c2pa.hash.bmff.v3`
  assertion, and the `merkle` purpose box of a fragment.
- `initHash`, the leaf hash, and the climb, by the three rules above.
- The statuses for a fragmented stream, and what a caller is told when a
  fragment does not belong to the init it was offered with.
- How a caller passes more than one stream (see Open questions 1).

**Out of scope** (each needs its own spec before it may be built)

- **Several renditions.** `uniqueId` and `localId` are 1 in both streams
  measured, and the `merkle` field is a *list* because a stream can carry
  more than one map. What those identifiers select is unmeasured, and a
  stream with two maps has not been made. An assertion carrying more than
  one `merkle` map is refused by name until it has.
- The `subset`, `length`, `version` and `flags` exclusion filters and
  nested paths, which SPEC-027 already refuses.
- Fetching fragments. This verifier opens no network connection; a caller
  hands it streams.
- The MPD, the DASH manifest, and anything about playback.

## Behavior

- **AC1 — an init segment and its fragments verify** *(happy path; oracle: `c2patool` 0.27.22)*
  - Given `tests/Fixtures/bmff-fragmented/init.mp4` and its five
    fragments, with the test trust settings
  - When the stream is verified
  - Then the state is `Trusted`, `assertion.bmffHash.match` is reported
    once, and the failure codes equal `c2patool`'s on the same set.

- **AC2 — the init segment is bound by `initHash`** *(error path)*
  - Given the same set with one byte of the init segment's `moov` altered
  - When it is verified
  - Then `assertion.bmffHash.mismatch`, and the explanation names the init
    segment rather than a fragment: the caller must be able to tell which
    file failed.

- **AC3 — a tampered fragment is caught, and named** *(error path)*
  - Given the same set with one byte of `seg_3`'s `mdat` altered
  - When it is verified
  - Then `assertion.bmffHash.mismatch`, and the explanation names
    `seg_3` and its `location`. A stream is many files and a verdict that
    does not say which one is half an answer.

- **AC4 — a fragment from another stream does not pass** *(the interesting negative)*
  - Given the five-fragment stream with `seg_3` replaced by a fragment of
    the seven-fragment stream built in step 82
  - When it is verified
  - Then it is a mismatch. The fragment is internally valid, correctly
    signed material from a real stream, and belongs to a different tree —
    which is exactly the substitution a Merkle root exists to prevent.
    `c2patool`'s answer on this file is unmeasured and the tests-first
    step must record it before this criterion is asserted.

- **AC5 — the count is part of the promise** *(error path)*
  - Given the set with one fragment withheld, and separately with a
    fragment offered twice
  - When it is verified
  - Then each is a failure naming the count the assertion declared and the
    number offered. A tree whose leaves are not all present has not been
    verified, whatever the ones present say.

- **AC6 — more than one `merkle` map is refused by name** *(malformed input)*
  - Given an assertion whose `merkle` list holds two maps
  - When it is verified
  - Then it is `Invalid` with the reason named. Rendition selection is
    unmeasured and a guess here would pick a tree and call the result a
    match.

- **AC7 — a whole file still behaves exactly as it did** *(the drift alarm)*
  - Given every fixture of SPEC-026 and SPEC-027, and the four corpora
  - When each is verified
  - Then every state and failure code is unchanged. Adding a second kind
    of input may not move the answer for the first.

## References

- Measured, step 82 (`notes/step-82-fragmented-bmff.md`): the box layout
  of a signed init segment and fragment; the `merkle` and merkle-box
  fields; `initHash` reproduced exactly; the leaf hash reproduced exactly;
  the tree shape checked against all five proofs of one stream and all
  seven of another.
- Measured, step 77: the digest rule this spec reuses three times over.
- Oracle: `c2patool` 0.27.22 with its `fragment` sub-command, which built
  the fixtures; its verdict on a mixed stream (AC4) is not yet recorded.
- Reasoned: that a fragmented stream is more than one file and the public
  API has nowhere to put it — a contract question, not a hash question.

## API sketch

Deliberately absent. The shape depends on Open question 1, and sketching
one here would make a decision look like a detail. What is certain is the
work behind it: the digest already exists, the tree does not.

## Open questions

1. **How a caller offers more than one stream.** Three shapes, and each
   costs something different:
   - `Verifier::verifyFragmented($init, iterable $fragments)` — one more
     method on a contract class. Simple to find, and it makes `Verifier`
     the thing that knows about DASH.
   - A `FragmentedVerifier` of its own — a tenth class in the contract,
     which keeps `Verifier` as it is and adds a second place to look.
   - A value object passed to the existing `verify()` — smallest
     surface, largest surprise, and `verify()` currently takes a resource.

   Whichever is chosen, SPEC-025's recorded surface grows and the snapshot
   must be updated deliberately. **Blocker: every acceptance criterion
   above is written as "when the stream is verified", and that sentence
   has no subject until this is decided.**
2. **What `uniqueId` and `localId` are for.** Both are 1 in both measured
   streams. AC6 refuses more than one map, so nothing depends on the
   answer yet — but the field is a list, and a spec that refuses the plural
   should say it is refusing rather than that the plural does not exist.
   Non-blocker.
3. **Whether a missing fragment is `Invalid` or an error.** AC5 says
   failure; the alternative is refusing to answer at all, as a container
   fault. A caller who offers four of five fragments has made a mistake,
   and telling them "invalid" may point at the file when the fault is in
   the call. Non-blocker, and worth a sentence in the explanation whichever
   way it goes.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
