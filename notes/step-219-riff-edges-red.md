# Step 219 — The edges of the leniency approved; their tests, red

*2026-10-05. SPEC-003 amendment 4, SPEC-055 amendment 4 and SPEC-013
amendment 17 approved by Maurice van Loon.*

## The tests

Built in memory from the fixtures, as in step 215.

- **SPEC-003**: AC18's dataset now expects `storeReached` true for
  `riff-size-plus-one` and `truncated-in-c2pa`, false for
  `truncated-between-chunks`; AC19 (new): a 3-byte tail and an
  overrunning chunk after `C2PA` leave the store of AC1 in a signed file
  and give `null` in an unsigned one; an overrunning `VP8L` before `C2PA`
  and 3 stray bytes before it give `null`; a header size of 0 is refused
  before any chunk, with `storeReached` false.
- **SPEC-055** AC20 (new): the four WAV faults with their new
  `hasManifest`; a 3-byte tail in the unsigned and the signed WAV.
- **SPEC-013** AC7: the amendment-16 test follows amendment 17
  (`riff-size-plus-one` and `truncated-in-c2pa` move to the files that
  report a manifest).

## Measured: red

```
vendor/bin/pest --group=SPEC-003   4 failed, 27 passed
vendor/bin/pest --group=SPEC-055   3 failed, 52 passed
vendor/bin/pest --group=SPEC-013   1 failed, 20 passed
vendor/bin/pest                    8 failed, 679 passed
```

They fail on `storeReached` still false where the scan will find the
store, a size of 0 not refused, and a short tail still a container fault.
PHPStan, Pint and `bin/spec-check.php` are clean.
