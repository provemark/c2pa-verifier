# Step 78 — The BMFF hash, tests first

*2026-09-22.* SPEC-027 was approved the same day with one blocking
question. This step answers it by measurement, builds the variants, and
leaves seven tests red.

```
Tests: 7 failed, 390 passed (7385 assertions)
```

## The blocking question, answered — and it had a sharp edge

SPEC-027 asked whether the marker rule is "a `u64` before **every**
included top-level box" or "before every included box that **follows an
excluded one**". Both fixtures begin with `ftyp`, which every exclusion
list excludes, so neither could tell them apart.

The sharp edge: **`ftyp` must be the first box in a valid ISOBMFF file**
(ISO/IEC 14496-12), and `/ftyp` is in every exclusion list `c2patool`
writes. So no file that tool produces can ever have an included first box.
Waiting for one would have been waiting forever.

What settles it instead is making the exclusion miss. Inside the assertion
the exclusion path `/ftyp` is four ASCII bytes; replacing them with `zzzz`
keeps every CBOR length identical and leaves an exclusion that matches no
box, so `ftyp` becomes included **and first**. Run through the instrumented
c2pa-rs of step 77:

```
=== first-box-included.mp4 ===
PROBE marker offset=0
PROBE range 0..=31 (32 bytes)
PROBE marker offset=13610
…
```

`marker offset=0`. The first box gets a marker with nothing before it, so
the rule is **a marker before every included top-level box**, and AC1 and
AC3 stand as written. No amendment.

That file is a probe artefact, not a fixture: its claim signature is broken
by the edit, and it exists only in the scratch directory. What it produced
is a fact, and the fact is what is kept.

## The three variants

`bin/make-bmff-variants.php`, each keeping the manifest intact so that a
failure is the hash and nothing else:

| variant | what changed | `c2patool` 0.27.22 |
|---|---|---|
| `mdat-byte-changed` | one byte of `mdat`, flipped | `assertion.bmffHash.mismatch` |
| `box-moved` | an eight-byte `free` box inserted before `moov` | `assertion.bmffHash.mismatch` |
| `xpath-nested` | `/free` → `/a/b` in the assertion | `assertion.bmffHash.mismatch`, `assertion.hashedURI.mismatch` |

**`box-moved` is the one that matters.** Every byte the hash covers is
byte-for-byte identical — the test asserts that first, before it asserts
anything about a verdict — and only *where* those bytes sit has changed.
`c2patool` calls it a mismatch. Without the offset markers it would verify,
which is why AC3 exists: delete the marker code and every other criterion
in this spec still passes.

`xpath-nested` breaks the claim signature too, because four bytes inside
the assertion moved. The test asserts the nested path is named **in
addition to** that, not instead of it.

## A layer with no exception of its own

AC5 and AC6 name a `HashException`, and there is none. `Hash` is the only
layer here without one: `Asn1`, `Cbor`, `Container`, `Cose`, `Jumbf`,
`Manifest`, `Timestamp` and `Trust` all have theirs, and SPEC-013 turns
each into a status before the public boundary. The existing hash checks
never needed one because they return statuses; reading an assertion whose
filters this verifier cannot honour is a different thing — a parse fault —
and it belongs in the same shape as the other eight.

78b adds it, marked `@internal`, which is what SPEC-025 already says of
every layer exception. Named here so that the new public class is a
decision in the record rather than a surprise in a diff.

## Red on purpose

- Pest: 7 failed, 390 passed. All seven on the missing `BmffHashCheck`.
- PHPStan: 21 errors, every one following from that class and from
  `StatusCode::AssertionBmffHashMatch`, which does not exist yet either.
- Pint and `bin/spec-check.php` (28 specs, 33 test files) are clean.

`docs/comparison.md` still says ISOBMFF has no hard binding. It stays that
way until it is false.

---

# 78b — M8's hard binding, and the alarms that rang

All seven green, **397 passed** in all, `composer check` exit 0.

```
MP4 : Trusted | ['signature','certificate','trust','hashedUris','actions','bmffHash']
AVIF: Trusted
PNG : Trusted | [… ,'dataHash']
```

An MP4 and an AVIF now verify **whole**. A video whose pixels were replaced
after signing is `Invalid` because somebody checked, which is the sentence
this milestone was for.

## Three alarms rang, and all three were right

**SPEC-025's snapshot.** Adding two status codes changed a contract class,
and `ApiSurfaceTest` failed at once. The recorded surface went from 91
symbols to 93, and the diff is exactly two lines:

```
+Report\StatusCode :: const AssertionBmffHashMatch
+Report\StatusCode :: const AssertionBmffHashMismatch
```

That is what that file is for: a promise that grew, visible in review
rather than discovered by a consumer.

**SPEC-015's enum count.** `toHaveCount(39)` failed with 41. It is
deliberate, it names every spec that ever added a code, and it now names
this one too.

**Two success lists in tests.** `DataHashCheckTest` and
`HashedUriCheckTest` each enumerate which codes are successes; both had to
learn `assertion.bmffHash.match`. This is the pattern step 75 warned about
— *an alarm that lists what it knows about is blind to arrivals* — except
here the lists are exhaustive over the enum, so an arrival breaks them.
That is the good version of the pattern, and it is worth noticing that the
difference between the two is whether the list is checked against reality
or merely written down.

## Two bugs of mine, both found by the tests

**The stream was at its end.** `BmffHashCheck` walked the boxes from
wherever the previous checks had left the file pointer, which is EOF, and
reported `unexpected end of file while reading the box size at offset 0`.
One `rewind()`, and the comment that says why it is there.

**The oracle helper read the wrong key.** Ours reported both failures and
the test still failed — because `spec027OracleFailures()` read
`validation_status`, and `c2patool` drops that key when every failure it
has is scoped to a manifest, reporting them under `validation_results`
instead. The helper reads both shapes now. Worth recording because the
failure looked exactly like a verifier bug and was a test bug, which is the
direction that wastes the most time.

## The amendment: `checks_performed` said something untrue

The spec named the statuses and said nothing about `checks_performed`, so
an ISOBMFF file came back listing `dataHash` — a check that never ran. A
caller reading that list would conclude the data hash had been verified.

Amendment 1 makes it say `bmffHash` when the binding manifest carries
`c2pa.hash.bmff.v3`. **Weight B: the report's shape changed, no verdict
did**, and it awaits confirmation. Leaving the older name would have been
shorter and untrue, and a list whose whole job is to say what was done must
not name something that was not.

## What M8 still does not do

Fragmented BMFF, Merkle trees, and the `subset`, `length`, `version` and
`flags` exclusion filters — every one refused **by name**, never ignored.
`docs/comparison.md` says so on its own row. Ignoring a filter would hash
the wrong bytes and call the result a match, and that is the single outcome
this project refuses above all others.

Nested exclusion paths are refused too. `c2pa-rs` resolves them; no file
here has one, and an untested branch that looks tested is worse than a
refusal that says what it is.
