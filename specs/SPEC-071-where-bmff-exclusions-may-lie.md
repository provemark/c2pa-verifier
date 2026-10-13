# SPEC-071: Which boxes a BMFF hash may exclude

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-13 (route (a); in 0.7.0) |
| Supersedes | SPEC-038's "additional exclusions are honoured and reported", except for the boxes this spec allows |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A `c2pa.hash.bmff.v2`/`v3` assertion (C2PA 2.4 §18.6) excludes boxes by
path (`xpath`), optionally narrowed by `data` and `subset`. The
specification lists the exclusions that *shall* appear (the C2PA `uuid`
box, and for some layouts `ftyp`, `mfra` and the offset fields). It does
not limit what else a signer may exclude. `BmffHashCheck` honours every
path beyond `/ftyp`, `/mfra` and the C2PA `uuid` box with the
informational `assertion.bmffHash.additionalExclusionsPresent` (SPEC-038).

Step 356 measured what follows. `bmff-exclusion-coverage/mdat-excluded-edited.mp4`
excludes `/mdat`, which holds every coded sample, and has one mdat byte
changed after signing. `moov-excluded-edited.mp4` does the same with
`/moov`, the track description. Both are `Trusted` here, in `c2patool`
0.28.1 and in 0.28.2. The same edit without the exclusion is
`assertion.bmffHash.mismatch` in both. It is the BMFF twin of the wrong
`Trusted` that SPEC-068 and SPEC-069 close for data hashes. The
informational is no warning: all three verifiers report it on the
unchanged fixture as well, because `c2pa-rs` excludes `/free` and `/skip`
by default. `c2pa-rs` PR #2668 set out to reject zero-coverage BMFF
bindings (CAI-13331), but that part was dropped before the merge.

SPEC-069's reasoning holds here too. A list of what may not be excluded
would have to know every box that changes what is shown or heard (`mdat`,
`moov`, `trak`, `stsd`, `stts`, `meta`, `iinf`, `ipco`, `idat`, …). A list
of what a measured writer excludes fails closed instead.

**What real files exclude** (step 356, all 36 BMFF hash assertions in the
fixtures):

| count | paths | writer |
|---|---|---|
| 31 | `/uuid` (C2PA data filter), `/ftyp`, `/mfra`, `/free`, `/skip` | `c2pa-rs` v3 default |
| 2 | `/uuid`, `/ftyp`, `/meta/iloc`, `/mfra/tfra`, `/moov/trak/mdia/minf/stbl/stco` and `co64` (subset from offset 16), `/moof/traf/tfhd` and `trun` (subset from offset 16) | `c2pa-rs` v2 (`video1.mp4`, step 86) |
| 1 | `/a/be` | synthetic (`bmff/xpath-nested.mp4`, already `Invalid`) |
| 2 | none | synthetic (`bmff-shape/exclusions-*`) |

## Scope

**In scope**

- Every exclusion of a `c2pa.hash.bmff.v2`/`v3` assertion, for the active
  manifest's binding, plain and fragmented.
- The allow-list. An exclusion is allowed when its `xpath` is one of:
  - `/uuid` with the `data` filter that names the C2PA UUID at offset 8
    (the rule `hasAdditionalExclusions()` already applies);
  - `/ftyp`, `/mfra`, `/free`, `/skip`, `/meta/iloc`, `/mfra/tfra`, whole
    or with a `subset`;
  - `/moov/trak/mdia/minf/stbl/stco`, `/moov/trak/mdia/minf/stbl/co64`,
    `/moof/traf/tfhd`, `/moof/traf/trun`, only with a `subset` whose every
    range starts at offset 16 or later. Those are the offset fields
    `c2pa-rs` excludes; the box header, version, flags and count stay
    hashed.
- Any other exclusion is `assertion.bmffHash.mismatch` naming the path, and
  the file is not hashed. That holds whether or not the path matches a box
  in the file, as in SPEC-069, where a range is judged by where it lies,
  not by whether it changes the digest.

**Out of scope** (each needs its own spec before it may be built)

- Indexed paths such as `/moov[1]/trak[3]` (`c2pa-rs` PR #2434). They are
  not resolved today, so such an exclusion matches nothing and the box is
  hashed. Under this spec it is refused by path. See Open questions.
- Whether excluding the offset tables (`stco`/`co64`, `iloc`) can make a
  player read other, hashed bytes as samples. Not examined. They stay
  allowed because `c2pa-rs` writes them.
- The data-hash formats (SPEC-069) and the informational's wording for
  `/free` and `/skip`.

## Behavior

- **AC1 — c2pa-rs's own exclusions are unchanged**
  - Given `fixture-signed.mp4`, `bmff-exclusion-coverage/unchanged-resigned.mp4`,
    `c2pa-rs/video1.mp4` and every BMFF file of the drift-alarm corpora
  - When verified
  - Then the state and status list are what they are today, the
    informational included.

- **AC2 — the media excluded is a mismatch** *(required: error path)*
  - Given `mdat-excluded.mp4`, `mdat-excluded-edited.mp4`,
    `moov-excluded.mp4` and `moov-excluded-edited.mp4`
  - When verified, with and without `probe-root.settings.json`
  - Then exactly one `assertion.bmffHash.mismatch` for the binding, whose
    explanation names the path (`/mdat`, `/moov`) and says that this
    verifier allows a BMFF exclusion only for the C2PA box, `ftyp`,
    `mfra`, `free`, `skip`, and `c2pa-rs`'s offset fields. The file is not
    hashed, and the state is `Invalid`.

- **AC3 — an offset table excluded whole, or from its header** *(error path)*
  - Given two new variants of `fixture-signed.mp4`, made by
    `bin/make-bmff-exclusion-coverage-variants.php`: an extra exclusion of
    `/moov/trak/mdia/minf/stbl/stco` with no `subset`, and one with
    `subset: [{offset: 8, length: 0}]`. The digest is left as signed,
    because the step-133 port covers top-level paths only and the path is
    refused before anything is hashed.
  - When verified
  - Then `assertion.bmffHash.mismatch` naming the path and the subset rule.

- **AC4 — a path that matches nothing is still judged**
  - Given a variant whose extra exclusion is `/zzzz` (no such box)
  - When verified
  - Then `assertion.bmffHash.mismatch`, as AC2.

- **AC5 — nothing else moves**
  - Given the corpora and every fixture
  - When verified before and after
  - Then only the files named under *Changed answers* move.

**Changed answers:** `bmff/xpath-nested.mp4` (`/a/be`) stays `Invalid`, but
its mismatch now names the path instead of the digest. Whether any other
fixture moves is measured in AC5 before the step is committed.

## References

- Specification: C2PA 2.4 §18.6 (the BMFF hash assertion and its
  exclusion list), §15.12 (hard bindings).
- Oracle: `c2patool` 0.28.1 and 0.28.2 under
  `tests/Fixtures/c2patool/bmff-exclusion-coverage/`: `Trusted` for all
  five files. **This spec diverges from them deliberately**, as SPEC-069
  does. The divergence goes into `docs/comparison.md`.
- Measured: step 356 (`notes/step-356-bmff-exclusion-coverage.md`).
- Reasoned: from `c2pa-rs` PR #2668 (its BMFF zero-coverage part dropped)
  and PR #2434 (indexed paths, which Pixel writes per a linking commit).

## API sketch

```php
// Hash\BmffHashCheck: the allow-list next to hasAdditionalExclusions(), checked
// right after the assertion is read, before a byte of the asset is hashed.
/** @return string|null  null when allowed, else why not (for the explanation) */
public static function refusedExclusion(array $exclusion): ?string;
```

## Open questions

- **Pixel videos (blocking for approval).** A commit that links to
  `c2pa-rs` PR #2434 says Pixel writes `/moov[1]/trak[3]` to exclude its
  timed-metadata track. Under this spec such a file is refused by path.
  Today it probably fails anyway, since the path resolves to nothing and
  the excluded track is hashed (reasoned, no Pixel video measured). Choose:
  (a) build this spec now and treat a Pixel video, once one is found, as
  the trigger for a spec that resolves indexed paths and allows exactly
  the track Pixel excludes (fail closed meanwhile); or (b) find a Pixel
  video first and resolve indexed paths before this spec. Proposal: (a),
  because the wrong `Trusted` is open now and a Pixel video is likely
  refused either way.
  *Status 2026-10-13 (step 358):* decided by Maurice: (a).
- **Release (non-blocking).** In 0.7.0 with SPEC-068, SPEC-069 and SPEC-070?
  Proposal: yes.
  *Status 2026-10-13 (step 358):* decided by Maurice: in 0.7.0.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Hash/BmffExclusionAllowListTest.php :: leaves c2pa-rs's own exclusions as they were (3 files) / SPEC-071 | src/Hash/BmffHashCheck.php :: refusedExclusion(), ALLOWED_WHOLE, ALLOWED_OFFSET_FIELDS |
| AC2 | tests/Unit/Hash/BmffExclusionAllowListTest.php :: reports an exclusion of the media as a mismatch, without hashing (5 files × 2 modes) / SPEC-071 | src/Hash/BmffHashCheck.php :: check() (the refusal, before the box walk) |
| AC3 | tests/Unit/Hash/BmffExclusionAllowListTest.php :: refuses an offset table excluded whole or from its header (2 files); names exactly what c2pa-rs writes as allowed, and nothing more (19 cases) / SPEC-071 | src/Hash/BmffHashCheck.php :: refusedExclusion() |
| AC4 | tests/Unit/Hash/BmffExclusionAllowListTest.php :: reports an exclusion of the media … ("a box the file does not have") / SPEC-071 | src/Hash/BmffHashCheck.php :: check() |
| AC5 | the corpus before and after (notes/step-358-bmff-allow-list.md); tests/Unit/Hash/BmffShapeTest.php :: AC5 (`xpath-nested.mp4`, the changed answer) | bin/make-bmff-exclusion-coverage-variants.php (`stco-*`, `zzzz-excluded`) |

The tests were run red first: 31 failed (`refusedExclusion()` did not exist
and every variant was honoured). The 3 AC1 cases pin behaviour that was
already there. After the change, SPEC-038 AC5 moved on `xpath-nested.mp4`,
the named changed answer, and its test names it. Mutations watched
failing: the refusal never applied (12), the offset fields from offset 8
(2), an empty subset allowed (1), `/mdat` on the list (5), any uuid box
allowed (1). `composer check` green (1,098 passed).

**Pixel, measured the same day (step 358).** A Pixel Camera video, the
attachment to `c2pa-rs` issue #2373 (sha256 `2f1c2bd8…`, kept outside the
repository: no licence), excludes `/ftyp`, the C2PA `/uuid`, `/mfra`,
`/free`, `/moov/free`, `/moov/udta`, `/moov/meta` and `/moov[1]/trak[3]`.
`c2patool` 0.28.1/0.28.2 verify its hash. Here it is `Invalid` before this
spec, because the box walk reads its QuickTime-style `/moov/meta` (no
version and flags) as an ISO FullBox. It stays `Invalid` after this spec,
now refused at `/moov/free`. This is the trigger route (a) named: a
follow-up spec for the QuickTime `meta`, indexed paths and Pixel's
exclusions.
