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

---

# 74b — the extractor, and a criterion that could not be true

All nine green, **390 passed** in all, `composer check` exit 0.

```
$ php bin/c2pa-verify tests/Fixtures/fixture-signed.mp4
 state: Invalid   format: isobmff   has_manifest: True
   signingCredential.untrusted | …
   general.error | the hard binding c2pa.hash.bmff.v3 is not supported yet: BMFF, box and…
```

That is the answer SPEC-026 promised: an MP4 is now *read* — the store is
found, the claim signature is checked, the certificate is judged — and then
refused by name, because the hard binding it declares is one this verifier
cannot check yet. `Invalid`, with the assertion named, and not a silent
`Valid`. The refusal was already there from step 47's rule about a signed
manifest without a hard binding; ISOBMFF walked into it, which is the whole
reason that rule exists.

## AC7 was not true, and the implementation is what said so

The criterion asked for a `size == 0` box that is **not** the last one to
be refused. That case cannot occur. `size == 0` means "to the end of the
file", so the declaration is what *makes* a box the last one: a reader that
honours it consumes everything after it, and a reader that does not has
stopped honouring the format. The variant built for it reads as one long
box, exactly as `c2patool` reads it.

Amendment 1 says what is true instead, and names the cost rather than
wishing it away: a box declaring `size == 0` **can** swallow the boxes that
followed it, and nothing in the container betrays that. What catches it is
the hard binding — the bytes it swallowed are the bytes the hash covers —
which is why `c2patool` answers `Invalid` on that variant rather than
refusing to parse it. The test now asserts exactly that: the store comes
back *longer* than the real one, and `c2patool`'s recorded verdict on the
same file is `Invalid`.

This is the third time in this project that writing the code has shown an
approved criterion to be wrong rather than merely awkward (SPEC-023 AC6,
SPEC-025 AC3, and now this). Each time the amendment was written before the
test was touched.

## A wrong assumption about `StreamReader`, caught in one run

The first extractor passed absolute offsets to `readExactly()` and walked
off into the middle of a box:

```
box mp41 at offset 544 declares 1635148593 bytes and runs past the end of the file
```

`StreamReader` is **sequential**: the `$offset` argument is for the error
message, not a seek. Every other extractor in this project knew that; this
one was written from the shape of the format instead of from the shape of
the reader. Rewritten to read forward and `skip()` what it does not want,
it walks correctly — and the bug never reached a commit because the happy
path is a real file whose boxes it had to get right.

## The two drift alarms, and why only one rang

Both were named in SPEC-026's open questions before the tests were written.

**SPEC-025 AC2** did not ring, because the new class was written with its
`@internal` line from the first draft. The alarm exists to catch what
somebody forgets; nothing was forgotten.

**SPEC-024 AC1** did not ring either, and that is the less comfortable
answer: its test names three constants and the fourth was invisible to it,
while the spec's own words said "all three containers". A test that
enumerates what it knows cannot notice what it does not. Amendment 1 to
SPEC-024 widens both — the criterion now says "every container this
verifier reads", and its test carries `IsobmffManifestStoreExtractor`'s
bound alongside the other three.

**Not a fourth alarm, but worth the same note:** PHPStan rang instead.
`Match expression does not handle remaining value: 'isobmff'` is what
forced the wiring decision into the open, at level max, the moment the
detector learned a fourth answer. That is the analyser doing what the tests
could not.

## Nine per cent more of the world

`docs/comparison.md` now carries ISOBMFF on its own row rather than among
"not read at all", and one more named strictness: an ISOBMFF `uuid` box
whose purpose this verifier cannot read is an error where `c2patool`
reports "no claim found". The README says the reader takes JPEG, PNG, WebP
or ISOBMFF.

What is still not true, and is written everywhere it matters: **there is no
BMFF hard-binding check**. A video whose pixels were replaced after signing
is `Invalid` here for the right reason — nobody checked — rather than for
the reason that would matter. That is the next spec.
