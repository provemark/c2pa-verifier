# Step 515 — What a DER parse holds does not grow with depth

*2026-10-29.* Finding C3 of the security review before 0.10.0, a resource
finding. SPEC-016 amendment 8, approved before the test was written.

## The finding

`DerReader::element()` gave every element its own copy of its content
octets, constructed elements included. A value n levels deep was
therefore held n + 1 times: once by itself and once by each enclosing
element. The reader's bounds (1 MiB of input, depth 32) stopped this from
growing without end, but not from being expensive: about 33 MB for one
parse. A time-stamp token sits in the COSE unprotected header, so anyone
can put one into a signed file without breaking its signature.

## What was measured

- `memory_get_usage()` around `read()` of a 1 MB OCTET STRING, on
  `71af85e`: held 2.2 MB at depth 1, 9.4 MB at depth 8, 17.8 MB at depth
  16 and 33.6 MB at depth 31. After: 0.1 MB at depth 31.
- `verify()` on `fixture-signed.png` with that string as its `sigTst2`
  token: peak 44.9 MB at depth 31 and 13.8 MB at depth 1, on `71af85e`.
  After: 13.8 MB at both. Both before and after the verdict is
  `timeStamp.malformed`, in under 20 ms.
- More tokens add little: a header of more than eight is refused before
  any is read (SPEC-016 AC8). Four deep tokens peaked at 59.9 MB before.
- AC14 on `71af85e`: the deep parse held 33,566,248 bytes, against a bound
  of 2 MB, and `verify()`'s peak was 29.8 MB above the shallow file's,
  against a bound of 3 MB. Both tests failed. After: both pass.
- The corpus before (`71af85e`, worktree, `vendor/` copied) and after:
  2,188 runs, none differs.
- `composer check` (1,705 passed, 1 skipped), `vendor/bin/pest
  --parallel` and `bin/package-check.php` pass.

## What was done

A `Der` no longer carries its content octets. It keeps the input it was
read from, one string that every element of the parse shares (PHP strings
are reference-counted, so nothing is copied), plus the length of its
contents, which start at `$offset + $headerLength`. `contents()` copies
them out when asked and replaces the public property `$contents`.
`encoded()` is now the slice of the input instead of a header rebuilt
from the length, which is the same bytes because the reader accepts only
minimal DER lengths. `length()` reads the stored length. The 27 reads of
`->contents` in `src/` and `tests/` became `->contents()`.

`Der` and `DerReader` are `@internal` (SPEC-025), so the public API does
not change.

The cost: an element kept after the parse, such as a certificate's
extension, keeps the whole input alive with it. That input is at most
1 MiB, once, where before there was a copy at every level.
