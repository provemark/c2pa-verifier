# Step 501 — The test suite says how much memory it runs with

*2026-10-28.* Step 500 recorded a finding: `composer check` failed three
times in a row, each time with PHP's 128 MB `memory_limit` exhausted in
`HostileInputSecondRoundTest` AC2 (SPEC-045: one assertion referenced a
thousand times is hashed once, within two seconds), allocating 8.5 MB in
`crc32()`, and then passed twice without a change. This step finds where,
and why it came and went. Tests and test configuration only; nothing in
`src/` changes.

## What was measured

- Step 449 raised the limit to 512 MB around the verification AC2 times.
  The file it verifies was built by `spec045References()` on the line
  before, still under the caller's limit. Building it makes several copies
  of the 8.5 MB store: `str_repeat()`, the `substr_replace()` calls, and
  the concatenation handed to `crc32()` when the `caBX` chunk is put back
  (the helper's line 74, the allocation step 500 saw).
- AC2 alone (`php -d memory_limit=… vendor/bin/pest --filter=…`): Pest
  itself holds about 40 MB; the test fails at 50 MB (line 146), 60 and 70
  MB (line 87), 80 MB (line 74, in `crc32()`), and passes at 90 MB.
- With the file built under the raised limit, AC2 alone passes at 80, 60
  and 50 MB. But `composer check` three times: two failed, one passed —
  now in `HostileInputTest.php:145` (SPEC-043 AC2), which builds a 20 MB
  input under the caller's limit and runs right after it. The failure had
  moved to the next large test.
- The whole serial suite under a lower limit fails at whichever large
  test comes first: `PackageTest.php:239` at 100 MB, `GifTest.php:89` at
  110 MB. A serial run holds 60–90 MB by its end (step 449: 37 MB after
  loading, 67 MB at the end; step 500: a peak of 88–90 MB). Under 128 MB
  the large tests ran on what happened to be left.
- CI never failed because `shivammathur/setup-php` ships its own
  `php.ini` with `memory_limit=-1` (`src/configs/ini/php.ini` in that
  repository, which its own workflow asserts). Locally, Homebrew's PHP has
  128 MB. Step 449 had left this unchecked.

## What was done

- `phpunit.xml` sets `memory_limit` to 512 MB for the suite, so the
  suite no longer depends on the host's `php.ini`: locally it rises from
  128 MB, in CI it falls from unlimited to the same 512 MB. The tests of
  the memory budget (SPEC-024) are untouched: `ResourceBoundsTest` starts
  a process of its own with `-d memory_limit=…`, and
  `BmffExclusionLimitsTest` sets 128 MB itself for the call it measures.
- AC2 keeps the change made first: the file is built under the limit the
  test raises, and the file and the report are let go before the limit
  goes back. With them still held PHP refuses to lower it ("Failed to set
  memory limit … current memory usage is 80019456 bytes", seen at 60 MB),
  and `phpunit.xml`'s `failOnWarning` makes that a failure.
- The assertions still bite: AC2's expected count set to 999 by hand
  fails ("Failed asserting that 1000 is identical to 999"), then restored.
- Before: the whole suite with `php -d memory_limit=100M vendor/bin/pest`
  fatal in `PackageTest.php:239`; `composer check` red twice in three.
  After: the same command passes (1,661 passed, 1 skipped), since
  `phpunit.xml`'s setting applies over `-d`; `composer check` three times
  in a row, exit 0 each; `vendor/bin/pest --parallel` exit 0;
  `bin/package-check.php` classifies every path (640 files).

## Not done

The memory the serial suite gathers over a run. With 512 MB it costs
nothing now, but finding where the suite holds it is a step of its own.
