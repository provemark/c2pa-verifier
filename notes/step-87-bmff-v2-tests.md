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

---

# Step 87b — green, and three criteria that had to move first

*2026-09-22.* All seven criteria pass, and the suite is 411 green
(7494 assertions), PHPStan max clean, Deptrac at zero violations.

```
Tests:    411 passed (7494 assertions)
```

## What was built

`IsobmffManifestStoreExtractor::boxTree()` walks into the container boxes —
`moov`, `trak`, `mdia`, `minf`, `stbl`, `moof`, `traf`, `mfra`, `edts`,
`dinf`, `udta`, `mvex`, and `meta` with its four-byte version/flags preamble
— and gives every box a path as it goes, refusing past a depth of eight.
`BmffHashCheck::plan()` turns that tree and an exclusion list into ranges
with markers; `ranges()` and `remaining()` do the `subset` arithmetic, where
`length: 0` means *to the end of the box*.

`matches()` was turned inside out. It used to refuse a filter it could not
honour as soon as it read one; it now resolves the path first and refuses
only when the path names a box the file actually has. That order is the
whole of AC6, and `video1.mp4` is the proof: it carries two `flags`
exclusions on `/moof` paths and has no `moof` at all.

The dispatch reads two labels where it read one. `BmffHashCheck::labelOf()`
answers which of `c2pa.hash.bmff.v3` and `c2pa.hash.bmff.v2` a manifest
carries, newest first; `Verifier` routes on that, and `DataHashCheck` has
stopped claiming the two as "not supported yet".

## AC1 was comparing two verifiers that did not have the same anchors

The criterion asked for `video1.mp4` to be verified **without** trust
settings and for the failures to equal the recorded
`c2patool/c2pa-rs/video1.json`. They did not, by one code:
`signingCredential.expired`, on the ingredient.

Measured, both answers are right about their own inputs. `c2patool` falls
back to the operating system's trust store for a timestamp authority — step
40 §5 already measured that its `timeStamp.trusted` for the DigiCert 2023
responder does not depend on the anchor configured — so it trusts both of
this file's DigiCert stamps, judges its 2022 signers at the moment they were
stamped, and says `claimSignature.insideValidity`. This verifier has no
system trust store **by design**: trust comes from the settings file and
from nowhere else. Without one it cannot trust the responder, so it judges
the ingredient's certificate (valid 2022-04-04 to 2023-04-04) at *now*, and
it has expired.

The fix is not to relax the check but to ask both sides the same question.
`trust/full-plus-digicert-g4.settings.json` — the C2PA test anchors plus the
cross-certificate that signs those stamps — has been a fixture since M6.
Under it, status for status, in both scopes:

```
state: Valid
 active: timeStamp.validated, timeStamp.trusted, signingCredential.trusted,
         claimSignature.validated, 5 × assertion.hashedURI.match,
         assertion.bmffHash.match
 ingredient: ingredient.manifest.validated, timeStamp.validated,
         timeStamp.trusted, claimSignature.validated,
         4 × assertion.hashedURI.match, signingCredential.untrusted
```

One failure on each side, the same one: the ingredient's chain ends at
`Media Publisher Company Intermediate CA`, which no anchor signs. The oracle
is recorded as `c2patool/timestamp/video1-full-plus-digicert-g4.json`, and
SPEC-029 amendment 1 carries the reason.

## Two older criteria stopped being true

Making v2 work changed what two earlier specs promised, and both were
amended before a test was allowed to move:

- **SPEC-027 AC5** listed `subset` and "an `xpath` with more than one
  segment" among the things this verifier refuses. Both are implemented
  now — v2 cannot be verified without them. The fixture
  `bmff/xpath-nested.mp4` answers `assertion.bmffHash.mismatch` where it
  answered a refusal: its `/a/b` names nothing, so `free` is hashed after
  all and the digest differs. `Invalid` either way, which is what the
  criterion exists to protect. The refusal list is now `length`, `version`,
  `flags`, `exact`.
- **SPEC-012 AC8** asked for the word "M8" in what `DataHashCheck` says
  about a manifest whose hard binding is `c2pa.hash.bmff.v2`. M8 is
  finished. The status stays `general.error` — asked about a binding it
  does not own, that check answers rather than falling silent — and its
  message now names `BmffHashCheck` instead of a milestone that has passed.

## A drift nothing was watching

`php bin/api-check.php` reported `FragmentedVerifier: in neither the
contract nor marked @internal`. The class went into the contract in step 83
and into `tests/Fixtures/api/public-surface.txt`, but the nine-name list
inside the script itself was never updated — and the script is not part of
`composer check`, so nothing failed. `tests/Unit/ApiSurfaceTest.php` has the
right ten and is the check that actually guards; the script had drifted
away from it for four commits. Fixed: 10 classes, 95 symbols, the recorded
surface matches.

Worth deciding separately: whether `bin/api-check.php` belongs in
`composer check`, so that the convenience and the guard cannot drift again.
