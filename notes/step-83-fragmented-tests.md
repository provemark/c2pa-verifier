# Step 83 — Fragmented BMFF, tests first

*2026-09-22.* SPEC-028 was approved the same day, with its blocking
question answered: a `FragmentedVerifier` of its own, taking one fragment
stream at a time. This step measures what AC4 needed, builds the broken
streams, and leaves six tests red.

```
Tests: 6 failed, 398 passed (7452 assertions)
```

## AC4 needed a measurement before it could be asserted

The criterion drops a fragment of one stream into another: correctly
signed material from a real stream, belonging to a different tree. The
spec said `c2patool`'s answer was unmeasured and had to be recorded first.
It is `assertion.bmffHash.mismatch`, and two things about *how* it says so
matter.

**It answers in text, not JSON.** When segment validation fails, `c2patool`
prints

```
Error validating segments: ValidationStatus { code: "assertion.bmffHash.mismatch", … }
0 Init manifests validated
```

and emits no report at all. Every other oracle in this repository is a
recorded JSON; these four are recorded as text, which is what the tool
gives.

**It does not say which file failed.** The same code comes back for a
changed init segment, a changed fragment and a foreign fragment. A stream
is many files, and a verdict that does not name one leaves the caller to
bisect by hand. SPEC-028 AC2, AC3 and AC4 each require the failing file to
be named, so this verifier will be **more specific than its oracle** —
which goes in `docs/comparison.md` when the spec is implemented, in the
column that has always said where the two differ.

## What the fixtures are, and what needed none

| what | how |
|---|---|
| `broken/init-byte-changed.mp4` | one byte of the init's `moov`, which `initHash` covers |
| `broken/seg_3-byte-changed.m4s` | one byte of `seg_3`'s `mdat`: a leaf |
| `foreign-seg_3.m4s` | `seg_3` of the seven-fragment stream of step 82 |
| AC5's two cases | **no file at all** |

AC5 is worth pausing on. A withheld fragment and a repeated one are not
broken files — they are a different *set*, and the test simply offers one.
That is what taking an iterable buys, and it is the first criterion in this
project whose input is the shape of the call rather than the content of a
file.

## AC7 is green from birth, and that is said rather than hidden

It verifies the four whole-file ISOBMFF fixtures and requires their answers
to be exactly what they were. Nothing about that is broken today; its job
begins the day a second kind of input moves the answer for the first. This
project has one other alarm of that shape — SPEC-024 AC4 — and the note for
that one said the same thing.

## Red on purpose

- Pest: 6 failed, 398 passed. All six on the missing `FragmentedVerifier`.
- PHPStan will follow from the same.
- Pint and `bin/spec-check.php` are clean.

**One alarm will ring in 83b and it is the contract's**: SPEC-025's
recorded surface grows from nine classes to ten, and the snapshot from 93
symbols. That is the point of the snapshot — a promise that grows should
appear as a diff — and the milestone's own rule says a class named in the
README must be a class the tests hold.
