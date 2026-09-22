# Step 74 — ISOBMFF, tests first

*2026-09-22.* SPEC-026 was approved the same day. This step is its red
phase: nine failing tests, ten malformed variants, and one criterion
answered by measurement before a line of it could be written.

```
Tests: 9 failed, 381 passed (7344 assertions)
```

## AC9 was a question, and it is now an answer

The criterion said AVIF must be *measured, not assumed*: either it yields a
store the existing stack reads, or what differs is written into the spec as
an amendment before any claim of AVIF support is made.

The sister repository's `fixture.avif` was signed with the same test
certificates and c2patool 0.27.22, and it is the same container in every
respect:

```
@0      ftyp  32
@32     uuid  13579   C2PA   purpose='manifest'  merkle=0  store=13534 B
@13611  meta  235
@13846  mdat  448
```

Same box, same twenty-one-byte preamble, same `c2pa.hash.bmff.v3`, and the
existing stack reads the store into one claim v2 manifest. c2patool calls
it `Valid` with `assertion.bmffHash.match`. **AVIF comes along for free**,
and now it is a fixture with its oracle beside it rather than an
assumption in a scope list. No amendment was needed.

## The ten variants, and what the oracle says about them

`bin/make-isobmff-variants.php` cuts one thing at a time out of
`fixture-signed.mp4`. Running `c2patool` over each is what proves the
surgery did what was intended, and it turned up two places where SPEC-026
is deliberately stricter:

| variant | `c2patool` 0.27.22 |
|---|---|
| `two-c2pa-boxes` | `Error: more than one manifest store detected` — agrees |
| `purpose-merkle` | tries to read merkle data and fails on the type |
| `purpose-unknown` | **`No claim found`** — ignores it |
| `purpose-unterminated` | `UUID box purpose field mis…` — agrees |
| `size-below-header`, `size-past-end`, `largesize-missing` | `Box size extends beyond asset` — agrees |
| `size-zero-not-last` | **`Invalid`** — reads it anyway |
| `size-zero-last` | `Invalid`; the bytes moved, so its BMFF hash no longer matches |
| `uuid-not-c2pa` | `No claim found` — agrees: not our box, no manifest |

**An unknown purpose.** c2patool treats a C2PA box whose purpose it does
not recognise as a file with no manifest. SPEC-026 AC5 makes it an error. A
box that announces itself as C2PA and then says something the reader cannot
read is not the same thing as a file that carries no credentials, and
reporting it as the latter is the silent skip this project refuses to
build.

**`size == 0` on a box that is not the last.** ISO/IEC 14496-12 defines
that as "to the end of the file", which only the last box can honestly
declare. c2patool reads it regardless. AC7 makes it an error, because a box
that claims everything after it while something follows is a contradiction,
and a reader that resolves a contradiction quietly has chosen for the file.

Both belong in `docs/comparison.md` when the spec is implemented; the
fixture README already carries them.

## Why these variants are committed and SPEC-024's were not

Ten files of about 16 kB, deterministic, each the evidence for one
criterion. SPEC-024's hostile stores were 8 to 63 MiB of filler and were
generated inside the test instead. The rule that separates them is whether
the bytes are *evidence somebody can open*: a signed MP4 with one field
cut is, a block of `0x41` is not.

## What is red, and what will be red next

All nine fail for their own reason: five on the missing class, three
because instantiating it throws `Error` rather than the
`ContainerException` the criterion expects, and one — AC3 — on real
missing behaviour, `FormatDetector` returning `null` where `isobmff`
belongs.

PHPStan reports 25 errors, every one following from the unknown class.
Pint and `bin/spec-check.php` (27 specs, 32 test files) are clean.

Two more will go red in 74b, and they are the drift alarms working rather
than faults:

- **SPEC-024 AC1** asserts the store bound "in all three containers" and
  counts three constants. A fourth extractor makes that literal wrong.
- **SPEC-025 AC2** requires every public class outside the contract to
  carry `@internal`. The new extractor lands outside it.

Both were named in SPEC-026's open questions before the tests were
written, so that neither is discovered as a surprise.
