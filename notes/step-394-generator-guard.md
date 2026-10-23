# Step 394 — SPEC-078 amendment 1: the generator knows its repository by inode

*2026-10-23.* The seventh finding of the review of 2026-10-23. Amendment
approved by Maurice. Tooling only; nothing on the verification path.

## The finding

`bin/make-conformance-test-variants.php` copies the public test key into
a directory under the scratch directory it is given, and refuses one
inside the repository. It compared `realpath()` strings. On a volume that
ignores case (macOS by default), `realpath()` keeps the letters as typed:
measured, `/Users/…/C2PA_VERIFIER/tests` passed the guard and stopped only
at the c2patool check, so the key could have been written inside the
repository. `.gitignore` named `*.key`, not `*.pk8`, the key's name here.

## The fix

- `ctInside()` compares the scratch directory, and every directory above
  it, with the repository by device and inode.
- `.gitignore` gains `*.pk8` and `conformance-test-keys-*/`.
- Not fixed: a Ctrl-C between signing and the shutdown handler can still
  leave the key in the scratch directory, which lies outside the
  repository.

## Measured

- The first test asserted on "outside the repository", which the usage
  line also holds, and passed on the first build. Tightened to the guard's
  own line, it failed, and passes after.
- Mutations, each failing: names compared as written, `*.pk8` removed,
  the key directory removed.
- The test is skipped where the volume tells letters apart.
- `composer check`: 1,293 passed, 1 skipped.
