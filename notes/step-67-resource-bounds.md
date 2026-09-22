# Step 67 — Bounds that fit the host, tests first

*2026-09-22.* SPEC-024 was approved the same day, with both of its
blocking questions answered: the bound becomes **16 MiB**, and
`memory_limit` is read so that a host too small gives a refusal in the
report rather than a fatal error. This step is the red phase — five
failing tests and the harness they need, no implementation.

```
Tests: 5 failed, 369 passed (7279 assertions)
```

## Two decisions the tests forced

**The hostile files are generated, not committed.** A store of 16 MiB is a
file of 16 MiB. The fixture corpus is already 63 MB that every clone
carries forever, and step 62 spent a whole step keeping that weight out of
the published package. These files are deterministic — a PNG chunk with a
declared length and filler — so nothing is lost by building them in the
temporary directory and deleting them at shutdown. A fixture earns its
place by being *evidence*, and a block of `0x41` is not evidence; it is a
shape the test can state in four lines.

**Each verification runs in a process of its own.** `memory_limit` cannot
be lowered reliably from inside a running PHP process, and the limit is the
whole subject of this spec. `tests/Support/verify-probe.php` does one
verification and prints a line of JSON — verdict, failure codes,
explanations, peak memory, time — and the tests spawn it with `-d
memory_limit=…`. This is the second group allowed to start a process, after
SPEC-023's, and for the same reason: the thing under test only exists
outside this process.

## The harness taught one thing immediately

The first run failed two tests with `JsonException` rather than the
assertion they were written for. A process killed by the memory limit does
not print *nothing*: PHP writes

```
PHP Fatal error:  Allowed memory size of 33554432 bytes exhausted …
```

and on the CLI that goes to **stdout**, beside whatever the probe had
already written. So "the process died" cannot be read as "stdout was
empty". The probe's own line is the last one that parses as JSON, and no
such line means the process did not reach the end. Worth writing down
because it is exactly the trap a test for fatal errors falls into, and a
harness that mistakes a crash for a parse failure reports the wrong thing.

## What each criterion catches, and why it is red

| AC | red because, today |
|---|---|
| AC1 (the constants) | the three bounds are 64 MiB, not 16 MiB |
| AC1 (refused unread) | a 20 MiB store is read, then fails in the parser; the explanation names neither the length nor the bound, and the peak is far above a file with no manifest |
| AC2 | a 15 MiB store under `memory_limit=32M` ends the process; the test requires an `Invalid`, a code, and an explanation saying the file was **not judged** |
| AC3 | with `memory_limit=-1` a 20 MiB store is read, where the absolute bound must still refuse it |
| AC5 | nothing is thrown today, but no refusal arrives either: the 20 MiB case reaches `Invalid` for the wrong reason and the 15 MiB case never returns at all |

**AC4 is green from birth, and that is said plainly rather than hidden.**
It verifies eight signed fixtures under `memory_limit=128M` and requires
the same `validation_state`, the same failure codes and a peak under 64 MB
as with a generous limit. Nothing about that is broken today — it is a
regression alarm, and its job begins the day a change makes an ordinary
file expensive. A test that was never red proves nothing about the past;
this one is there for the future, and it is the only one of the five in
that position.

## The number this step must still measure

SPEC-024's Open question 2 proposes `DEFAULT_SHARE = 0.25` of the
remaining limit, reasoned from the PNG path costing about 2.1× the store
today and about 1.1× once its double copy is gone. That is a judgement, not
a measurement. 67b measures the real peak at 16 MiB instead of assuming it,
and the share with it.

## Red on purpose after this step

`composer check` does not pass: Pest reports 5 failed, 369 passed. Pint and
PHPStan are clean — unlike SPEC-023's red phase, nothing here refers to a
symbol that does not exist yet, because the constants being asserted about
are already there and merely hold the wrong value.
