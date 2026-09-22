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

---

# 83b — M8 closed

All seven green, **404 passed** in all, `composer check` exit 0. A
fragmented DASH stream now verifies: the init segment against its
`initHash`, and every fragment against the Merkle root.

## The shape that made it small

`Verifier` already routes an ISOBMFF file to the BMFF hard binding, and it
already takes its `BmffHashCheck` as a constructor argument. So
`FragmentedVerifier::verify()` builds a `Verifier` whose check knows this
call's fragments, and hands it the init segment:

```php
return (new Verifier(bmffHash: new BmffHashCheck(fragments: $fragments)))
    ->verify($init, $settings);
```

`Verifier` learns nothing about DASH, no status is stripped or patched
afterwards, and every other rule — the signature, the certificate, the
trust list, the hashed URIs, the actions — runs exactly as it does for a
whole file. The report is the same `VerificationReport`.

The work is in `BmffHashCheck`, which already had the digest: `initHash`
and every leaf are that same function applied to another file. What is new
is the climb and the tree shape, thirty lines of it.

## PHPStan found a seam that was not one

The first draft took a `Verifier` in the constructor and then built its
own inside `verify()`, because the injected one could not carry this
call's fragments. PHPStan said what that is:

```
Property FragmentedVerifier::$verifier is never read, only written.
```

An injected dependency that is ignored reads as a seam — somewhere to
substitute a double — and there was none. The constructor is gone; the
class has no dependencies, and the comment says why the `Verifier` is
built where it is rather than handed in.

That also shrank the contract: 96 symbols became 95, and the recorded
surface says so.

## Both contract alarms rang, and neither had to be remembered

`ApiSurfaceTest` AC2 failed the moment `FragmentedVerifier` landed — in
neither the contract nor marked `@internal`. AC5 failed until the README's
Public API table named it. The snapshot then showed the promise growing in
two lines:

```
+Verifier\FragmentedVerifier :: method merkleMapOf
+Verifier\FragmentedVerifier :: method verify
```

This is the first class *added* to the contract since SPEC-025 drew it, and
it went in as a diff somebody reads. SPEC-025 amendment 2 records it.

## Where this verifier is more specific than its oracle

`c2patool` answers a changed init segment, a changed fragment and a foreign
fragment with the same code and no indication of which file failed. Ours
names the file:

> `foreign-seg_3.m4s` does not reach the merkle root: climbed to … from
> location 2

`docs/comparison.md` carries that row now, in the column that has always
said where the two differ.

## M8

| | |
|---|---|
| MP4, MOV, AVIF, HEIC, whole | done |
| Fragmented streams, Merkle trees | **done** |
| `subset`, `length`, `version`, `flags` filters | refused by name |
| Nested exclusion paths | refused by name |
| More than one `merkle` map — several renditions | refused by name |

The three refusals are all the same shape: `c2pa-rs` supports them, no file
this project can reach uses them, and implementing against no fixture is an
untested branch that looks tested. Each says so in its own message.

**M8 is closed.**
