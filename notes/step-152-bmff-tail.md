# Step 152 — The bytes after the last ISOBMFF box are hashed (SPEC-027 amendment 4)

*2026-09-25. Found by the security review of the same day. A wrong
`Trusted`, present in 0.1.0 to 0.2.1, and its mirror image: a false
`Invalid` on genuine files.*

## The flaw

The ISOBMFF box walk stops when fewer than eight bytes remain, which is
too few for a box header. Those last 1 to 7 bytes were in no range, so
they were never hashed:

- a file with bytes appended or changed there after signing stayed
  `Trusted`;
- a file that `c2pa-rs` signed with such a tail was `Invalid` here,
  because `c2pa-rs` does hash it.

## 152a — the formula measured, probes, oracles, the test seen red

`bin/make-bmff-tail-variants.php` builds the files of
`tests/Fixtures/bmff-tail/` (see its README). It signs through `c2patool`
0.28.0 with the c2pa-rs ES256 test pair, which stays outside this
repository.

**How `c2pa-rs` hashes the tail** was found by computing candidates
against the hash `c2patool` wrote into files it signed with a 7-byte
tail. Candidates that did not match:

- the digest without the tail;
- the tail with its own 8-byte offset marker.

What matched, for files signed by both 0.27.22 and 0.28.0: **the tail
after every included range, with no marker**. That held even when the
last box was an excluded `free`. The untailed `fixture-signed.mp4`
matched both "without the tail" and "tail without a marker", as it
should.

| file | this verifier before | c2patool 0.27.22 and 0.28.0 |
|---|---|---|
| `appended.mp4` | **`Trusted`** | `Invalid`, `assertion.bmffHash.mismatch` |
| `signed-tail.mp4` | **`Invalid`** | `Trusted` |
| `signed-free-tail.mp4` | **`Invalid`** | `Trusted` |
| `signed-free-tail-changed.mp4` | `Invalid` | `Invalid`, `assertion.bmffHash.mismatch` |
| fragments with `broken/seg_3-tail.m4s` | **`Trusted`** | `Invalid`, `assertion.bmffHash.mismatch` |

The first idea, refusing any tail, was dropped on this measurement: it
would have kept rejecting genuine files.

`tests/Unit/Hash/BmffHashCheckTest.php`, AC8: red on `appended.mp4`
(`Trusted` where `Invalid` was expected). The two signed files
(`Invalid`) and the fragment case (`Trusted`) were shown red apart.
`signed-free-tail-changed.mp4` was already `Invalid`: that part of the
test is a guard and was never red.

## 152b — built

`BmffHashCheck::withTail()` appends the range from the end of the last
top-level box to the end of the stream, with no marker. It is used:

- in `check()`, right after the plan, so that it also counts for a
  fragmented stream's init segment;
- in `checkFragment()`, for a fragment's leaf.

An unreadable stream size is a `HashException`, which the check reports
as a mismatch.

Measured:

- `vendor/bin/pest --group=SPEC-027`: 8 passed; `--group=SPEC-028`: 8
  passed.
- `composer check`: exit 0, 515 passed.
- **Whole files, 19,150 runs, before and after: 150 moved**, exactly the
  three new files under their 50 settings. `appended` went to `Invalid`,
  and the two signed files went to `Valid`/`Trusted`. No other file
  changed.
- The 16,807 five-fragment sets of step 151, before and after: identical.

## Disclosure

The fix is local until the other findings of the review are fixed too.
They go out together as a security release. Pushing a probe or a test
before then would publish the flaw.
