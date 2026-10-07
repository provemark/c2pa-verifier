# Step 269 — a review of plain text; SPEC-060 amendment 2

*2026-10-07. A review of steps 265–268, then amendment 2 built with its
tests seen red first.*

## The review

An independent read of `PlainTextManifestStoreExtractor`, `SelectorReader`
and the routing against c2pa-rs 0.91.1's `plain_text_io.rs`, with
differential scripts.

- **No wrong `Valid` found** (read and run): when a store is returned it is
  the one wrapper c2pa-rs's `locate_all` finds, with the same start, length
  and payload; `DataHashCheck` requires an exclusion equal to that range, so
  extra padding, a selector right after the wrapper or a moved wrapper all
  end in `assertion.dataHash.mismatch`.
- **The readers agree with a whole-string decoder** (run): 4,000 random mixes
  of selectors, marks and emoji at piece sizes 1–64, and 3,900 UTF-8 cases
  around a piece boundary.
- **Time** (measured): one selector at a time cost about 0.5 µs: a 4 MiB
  store 2.2 s, a 16 MiB store about 9 s, with the largest padding about
  18 s — long enough to meet a shared host's `max_execution_time` and end
  without a report.
- Three gaps in the documents and one in the message: two candidate cases
  stricter than the oracle but not named; AC12's wording; edge cases (an
  empty file, a text that begins as another format, an extended LBox, a
  length under 8) not written down; the unknown-format message silent about
  text.

## Amendment 2 (approved)

- **A** — a run is matched per buffered stretch with one anchored,
  possessive expression and translated with one `strtr()`. A backtracking
  repeat exhausted PCRE's JIT stack on a long run (measured: `preg_match`
  returned false on 20,000 selectors), which would have read as "no
  selector"; a failed match is now a `ContainerException`, never a silent
  end of the run. A 16 MiB store with as much padding: 15.8 s → 0.40 s.
- **B, C, D** — named in the spec, the README and `docs/comparison.md`.
- **E** — with text on, the unknown-format message adds "and it is not
  UTF-8 text".
- Found while building A: with pieces under 4 bytes the reader could not
  hold one 4-byte selector and ended the run early (run: the reviewer's
  differential script, 4,885 mismatches at piece sizes 1 and 2). It now
  buffers at least 4 bytes. Real use reads 64 KiB pieces, so no verdict
  was affected; a test with pieces of 1–7 bytes now holds it (3 red before
  the fix).

## Measured after the build

- `composer check`: 910 tests.
- Against the oracle, every text file with and without the test trust
  settings: 40 of 48 equal; the 8 others are the four named refusals.
- Fuzzing: three text seeds of 400 rounds (23,100 runs), 0 faults, 23
  `Valid`, each `Valid` in the oracle; the release set with seed 20261005,
  10,704 runs, 0 faults, 118 `Valid`, each `Valid` in both `c2patool`
  versions.
- The corpus: 0 of 1,446 measurements moved.
