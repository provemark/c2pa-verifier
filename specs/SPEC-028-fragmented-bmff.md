# SPEC-028: Fragmented BMFF — the Merkle tree, and a second kind of input

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-22                      |
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
- How a caller passes more than one stream: a `FragmentedVerifier` taking
  one fragment stream at a time (Open question 1, decided).
- The tenth class the contract gains, and the SPEC-025 snapshot that must
  grow with it — deliberately, as a diff somebody reads.

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

Every criterion below reads "when the stream is verified", and since Open
question 1 was decided that means `FragmentedVerifier::verify()` with the
init segment and an iterable yielding one fragment stream at a time.

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

- **AC8 — a location outside the tree is refused** *(amendment 1; required: error path)*
  - Given the stream with `seg_1` withheld and a copy of `seg_5` whose
    merkle box says `location` 5 (`broken/seg_5-location-5.m4s`), and
    separately with `seg_5` withheld and a copy of `seg_1` saying
    `location` -1 (`broken/seg_1-location-minus-1.m4s`)
  - When the stream is verified
  - Then each is `Invalid` with `assertion.bmffHash.mismatch` and no
    `assertion.bmffHash.match`, as `c2patool` 0.27.22 and 0.28.0 answer
    both sets, and the explanation names the fragment and its location.
    A location is a leaf's place in a tree of `count` leaves: it is at
    least 0 and less than `count`, or the fragment has no place.

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

Settled by Open question 1. `Verifier` is untouched; this is a tenth class
in the contract, and it holds a `Verifier` rather than repeating it.

```php
// namespace Provemark\C2paVerifier\Verifier;

final readonly class FragmentedVerifier
{
    public function __construct(private Verifier $verifier = new Verifier) {}

    /**
     * An init segment and its fragments, as one verdict.
     *
     * @param  resource  $init  the init segment, readable and seekable
     * @param  iterable<string, resource>  $fragments  a name and an open stream,
     *         one at a time: each is read to its end before the next is asked
     *         for, so fifty fragments never mean fifty open handles. The name
     *         is what a status says when that fragment is the one that failed.
     *         Nothing here closes a stream it did not open.
     */
    public function verify($init, iterable $fragments, ?TrustSettings $settings = null): VerificationReport;
}
```

The report is the same `VerificationReport` a whole file yields, so
everything downstream of it is unchanged. What differs is inside the
statuses: a fragmented stream is many files, and each status says which.

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
   must be updated deliberately. **Decided by Maurice van Loon,
   2026-09-22: a `FragmentedVerifier` of its own, taking one fragment
   stream at a time.**

   Why that one, in his words and mine: the audience is hosts checking a
   single image, and for them it is worth more that `verify()` does one
   thing with one signature than that they see a method they will never
   call. What this builds will grow — the `merkle` field is already a
   list — and it grows in a class nothing else depends on. And it is the
   only shape where the existing contract does not change: something is
   added, nothing moves.

   The cost is named rather than waved away: a caller who finds `Verifier`
   and not this class concludes fragmented streams are unsupported. The
   README's Public API table and `docs/comparison.md` both name it, which
   SPEC-026 amendment 2's rule requires anyway.

   **One fragment stream at a time** because fifty fragments must not mean
   fifty open handles. The iterable yields a name and an open stream, the
   verifier reads that fragment to the end before the next is asked for,
   and it closes nothing it did not open.
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

## Amendments

1. **2026-09-25, step 151, found by the security review; measured** *(confirmed by Maurice van Loon, 2026-09-25)* —
   a fragment's `location` is range-checked: `0 <= location < count`,
   else `assertion.bmffHash.mismatch` naming the fragment. The path
   through the tree was computed from the location without that check,
   so a location past the end took the last leaf's path and a negative
   one the first leaf's. The merkle box is excluded from the leaf hash,
   so a copy of a fragment with only its location changed still climbed
   to the root. The duplicate check saw two different numbers and the
   count check saw `count` places filled. A stream with one fragment
   withheld and another offered twice was therefore `Trusted`.

   Measured with both `c2patool` versions on the two sets of AC8: each
   is refused with `assertion.bmffHash.mismatch`; the untouched stream
   is `Trusted`. This is a correction toward `c2patool`, not a
   difference from it. New criterion AC8.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

All tests are in `tests/Unit/Verifier/FragmentedVerifierTest.php`, group
`SPEC-028`. The class a caller holds is
`src/Verifier/FragmentedVerifier.php`; the work is in
`src/Hash/BmffHashCheck.php`, which SPEC-027 already had.

| Acceptance criterion | Test (name) | Source (symbol) |
|---|---|---|
| AC1 | `AC1: an init segment and its fragments verify` | `FragmentedVerifier::verify()`, `BmffHashCheck::checkMerkle()` |
| AC2 | `AC2: the init segment is bound, and the explanation says it was the init` | `BmffHashCheck::checkMerkle()` (the `initHash` comparison) |
| AC3 | `AC3: a tampered fragment is caught and named` | `BmffHashCheck::checkFragment()` |
| AC4 | `AC4: a fragment of another stream does not pass, though it is valid in its own` | `BmffHashCheck::checkFragment()`, `path()` |
| AC5 | `AC5: the count is part of the promise` | `BmffHashCheck::checkMerkle()` (the count), `checkFragment()` (a location filled twice) |
| AC6 | `AC6: more than one merkle map is refused by name` | `FragmentedVerifier::merkleMapOf()`, `BmffHashCheck::merkleMapOf()` |
| AC7 | `AC7: a whole file still behaves exactly as it did` | `Verifier` (unchanged), `BmffHashCheck::check()` |
| AC8 | `AC8: a location outside the tree does not fill a place in it` | `BmffHashCheck::checkFragment()` (the range check); `bin/make-fragmented-variants.php` |
