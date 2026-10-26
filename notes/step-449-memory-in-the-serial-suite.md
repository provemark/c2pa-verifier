# Step 449 — A timing test that leaned on the memory left by the tests before it

*2026-10-26.* `HostileInputSecondRoundTest` AC2 (SPEC-045: one assertion
referenced a thousand times is hashed once, within two seconds) failed in
step 448's full serial runs, and once in step 440's. On its own it passed.

## What was measured

- In a full serial run the test found no `assertion.hashedURI.*` status at
  all. Dumping the report showed why: `general.error`, "caBX chunk length
  8522610 does not fit this host: 33259520 bytes of memory remain". The
  memory budget (SPEC-024) lets a store claim a quarter of what is left;
  33 MB left allows 8.3 MB, and the test's store is 8.5 MB.
- The same on the code of step 447, in a worktree: not a change of step 448.
- Memory after every test of a serial run (a temporary `afterEach` in
  `tests/Pest.php`, removed): about 37 MB once Pest has loaded the 110 test
  files, 67 MB by the end, the growth spread over many files (CrJson 5 MB,
  Package 3 MB, ConformanceTestRoot 3 MB, the rest under 2 MB each).
  `gc_collect_cycles()` before the test freed almost nothing. No single
  test holds it.
- Locally `memory_limit` is 128 MB. CI runs `setup-php`'s default, which
  this step did not check, and the test has passed there on every push.

## What was done

The test now raises `memory_limit` to 512 MB for the verification it
times, and restores it, as `PlainTextTest` AC12 already does for the same
reason: it measures the time and the single hash, not the budget, which
SPEC-024's own tests measure in a process of their own
(`ResourceBoundsTest`). Before: the full serial run red on this test,
twice. After: `composer check` green, 1,410 passed, 1 skipped.

## Not done

The 30 MB the serial suite gathers over a run. It costs nothing but
margin; a test that needs room says so, as this one now does.
