# Step 142 — SPEC-041: a first APP11 piece with Z = 0

*2026-09-25. SPEC-041 approved the same day. Open question 1 was answered
narrow: only a first Z = 0 is accepted. Questions 2 and 3 adopted their
proposals.*

## 142a — the fixtures, and the tests seen red

**The real file.** `tests/Fixtures/writers/microsoft-20260609-bing-fast-heartbeat.jpg`
is Wikimedia Commons' `File:Fast heartbeat.jpg`, a Bing Image Creator
image uploaded on 2026-06-09 and marked public domain. It was copied
unchanged from step 141's probe. Its sha1 equals the one the MediaWiki API
reports for the upload (`cf21a619…`). Its row in the writers README names
the writer, the signer, the EKU and the certificate's end date
(2026-10-01). It is deliberately not in SPEC-013 AC13's list of writer
files. Adding it there would change that spec.

**The variants.** `bin/make-spec041-variants.php <c2patool-0.28.0>
<c2patool-0.27.22>` rewrites only the Z fields:
- `zero-two`, `zero-one` and `seven-eight` come from `fixture-signed.jpg`;
- `one-piece-seven` comes from `public-testfiles/adobe-20220124-C.jpg`.

The script then records both `c2patool` versions' answers for the four
variants and the Bing file, and copies the 0.27.22 answer for the Bing
file to `c2patool/writers/`, as its neighbours have. A second run wrote
the same bytes, so the output is reproducible. Every answer equals step
141's measurement:

| file | 0.27.22 | 0.28.0 |
|---|---|---|
| `zero-two.jpg` | `Valid` | `Valid` |
| `zero-one.jpg` | `Error: invalid embedded file box` | the same |
| `seven-eight.jpg` | `Valid` | `Valid` |
| `one-piece-seven.jpg` | `Valid` | `Valid` |
| the Bing file | `Valid` | `Invalid` (the EKU, step 112) |

**The tests.** `tests/Unit/Container/FirstPieceSequenceTest.php`, run as
`vendor/bin/pest --group=SPEC-041`: **3 failed, 2 passed.**

- AC1 fails: *"piece out of order at offset 2: packet sequence number
  expected 1, found 0"*. That is the refusal step 141 found.
- AC2 fails: `Invalid` where `Valid` is expected, for the same reason.
- AC3 fails too, which the explanation before this step did not predict.
  The error comes at the first piece (*"expected 1, found 0"*), not at
  the second (*"expected 2, found 1"*). The file is refused today and
  stays refused, but for the wrong reason, and the test pins the right
  one.
- AC4 (a first Z of 7) and AC5 (SPEC-001's swapped pieces) are green
  before and after. They pin the strictness that must stay.

AC1 asserts on the extracted bytes (length, sha256, `LBox`/`TBox`), on
`claimSignature.validated` and on the absence of `general.error`, not on
`validation_state`. The Microsoft signer expires on 2026-10-01 (open
question 3).

One test was wrong on the first run. `toContain($needle, $message)` in
Pest takes both arguments as needles. The suite's own rule (`SpecCheckTest`,
*"every toContain … takes exactly one needle"*) caught it, and the check
now uses `in_array(…)` with `toBeTrue($message)`.

`composer check` is otherwise clean: 500 passed.

Committed locally, not pushed.
