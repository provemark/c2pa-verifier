# Step 223 — `storeReached` for JPEG, PNG and ISOBMFF: approved; tests red

*2026-10-05. SPEC-001 amendment 5 (held as AC17), SPEC-002 amendment 3
(AC15), SPEC-026 amendment 3 (AC10) and SPEC-013 amendment 18 (AC7)
approved by Maurice van Loon. The amendments named no criterion; each was
given the next free number on approval.*

## The tests

- SPEC-001 AC17: the three JPEG fixtures whose fault precedes the APP11
  JUMBF piece, and an unsigned JPEG with an APP1 length past the end
  (step 221), report `storeReached` false; three faults in the pieces
  report true.
- SPEC-002 AC15: `png/truncated-between-chunks.png`, an unsigned PNG cut
  in half and one with a chunk length past the end: false; `crc-wrong`,
  `truncated-in-cabx`, `two-cabx`: true.
- SPEC-026 AC10: `isobmff/largesize-missing.mp4`, an unsigned MP4 cut in
  half, with a box past the end and with garbage appended: false;
  `size-past-end` (a C2PA box running past the end), `two-c2pa-boxes`,
  `purpose-unknown`: true.
- SPEC-013 AC7: the five fixtures report `hasManifest` false, three faults
  after the store true.

## Measured: red

```
vendor/bin/pest --group=SPEC-001   1 failed, 17 passed
vendor/bin/pest --group=SPEC-002   1 failed, 15 passed
vendor/bin/pest --group=SPEC-026   1 failed, 9 passed
vendor/bin/pest --group=SPEC-013   1 failed, 20 passed
```

Each on *Failed asserting that true is false*: the extractors still throw
with the default flag. Pint and `bin/spec-check.php` clean. PHPStan first
reported three errors, all in the new tests (a closure's untyped `$stream`
passed to `extract()`); this note had said "clean" before that result was
read, and the first fix (an inline `@param` on an arrow function) was
committed before PHPStan was run again, and did not work. The closures
now take `mixed` and assert a resource; PHPStan is clean, checked before
this commit was amended.
