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
