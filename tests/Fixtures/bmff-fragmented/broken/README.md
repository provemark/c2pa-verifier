# Broken fragmented streams (SPEC-028)

Built by `bin/make-fragmented-variants.php` from the stream one directory
up, each with exactly one thing wrong.

| file | what is wrong | criterion |
|---|---|---|
| `init-byte-changed.mp4` | one byte of the init segment's `moov`, which `initHash` covers | AC2 |
| `seg_3-byte-changed.m4s` | one byte of `seg_3`'s `mdat`: a leaf | AC3 |

AC4 uses `../foreign-seg_3.m4s`, which is `seg_3` of the seven-fragment
stream of step 82 — correctly signed material from a real stream, belonging
to a different tree. AC5 needs no file at all: the test offers four
fragments, and then five with one repeated, which is what taking an
iterable is for.

`c2patool` 0.27.22's answers are in `../../c2patool/bmff-fragmented/`, as
**text** rather than JSON: when segment validation fails it prints

```
Error validating segments: ValidationStatus { code: "assertion.bmffHash.mismatch", … }
0 Init manifests validated
```

and emits no report. It gives the same code for all three broken cases and
**does not say which file failed**. SPEC-028 AC2, AC3 and AC4 each require
the failing file to be named, so this verifier is more specific here than
its oracle — recorded in `docs/comparison.md` when the spec is implemented.
