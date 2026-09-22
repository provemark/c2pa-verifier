# Step 87 — bmff v2, tests first

*2026-09-22.* SPEC-029 was approved the same day with both questions
answered: the descent lives in SPEC-026's extractor, bounded at eight.
This step leaves six tests red.

```
Tests: 6 failed, 1 passed — 404 passed elsewhere (7480 assertions)
```

## The assertions are a transcript, not a construction

AC3 is the sharpest criterion in the spec, and its expected value is
copied from what the instrumented c2pa-rs printed in step 86:

```php
expect(spec029Plan())->toBe([
    ['offset' => 30686, 'length' => 1051,   'marker' => true],   // moov, to the first stco subset
    ['offset' => 31761, 'length' => 1549,   'marker' => false],  // after it — no new box, no marker
    ['offset' => 33334, 'length' => 58,     'marker' => false],  // after the second
    ['offset' => 33392, 'length' => 4562,   'marker' => true],   // the other uuid box
    ['offset' => 37954, 'length' => 54188,  'marker' => true],   // free, hashed under v2
    ['offset' => 92142, 'length' => 736429, 'marker' => true],   // mdat, to the end
]);
```

Nothing in that list was reasoned. If the nested resolution places one box
wrongly, or emits a marker where a hole should be, the diff says which
number moved.

The `marker` field is new and it is why the plan is a list of ranges rather
than a list of boxes: a nested exclusion splits a box into several ranges
that share one marker. SPEC-027's `included()` returned one range per box
and could not say that.

## No new fixture, and one deliberately avoided

`video1.mp4` has been here since step 85. AC2 needs it with one byte
changed, and the file is 828 kB — so the test writes the altered bytes to
`php://memory` and verifies the stream. A stream is a stream, and a second
copy of that file in every clone forever is a real cost for a single flipped
byte.

## AC6 is the one that could go wrong quietly

It asserts two things that pull against each other. `flags` must be refused
— and `video1.mp4` itself carries two `flags` exclusions, on
`/moof/traf/tfhd` and `/moof/traf/trun`. A non-fragmented file has no
`moof`, so neither can ever match.

If the refusal fires while *reading* the exclusion list, this very fixture
fails on exclusions that touch nothing, and AC1 goes red. If it fires only
when a path *resolves to a box that exists*, both criteria hold. The spec
says so in words; the test says so in two halves, and getting it backwards
would show up as AC1 failing rather than AC6 passing — which is the right
way round for a mistake to appear.

## The eleventh time

`expect($codes)->toContain($needle, $file)` reads `$file` as a **second
needle**, not as a failure message. It made AC7 — an alarm that should be
green from birth — fail for a reason that had nothing to do with the
criterion.

This project has now hit that eleven times, and this is the first time it
disguised a green test as a red one rather than the other way round. The
honest fix each time has been `str_contains(...)->toBeTrue($message)` or
`in_array(...)->toBeTrue($message)`. Eleven occurrences is no longer bad
luck; it is a shape the test suite invites, and it is worth a rule of its
own rather than another comment.

## Red on purpose

- Pest: 6 failed on `boxTree()` and `plan()`, which do not exist; AC7
  passes.
- Pint and `bin/spec-check.php` (30 specs, 35 test files) are clean.

87b writes the depth-bounded walk in the extractor and grows
`BmffHashCheck::matches()` into the full filter set — and must decide, in
the open, how the dispatch handles two labels where it handles one today.
