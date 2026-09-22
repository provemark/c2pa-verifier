# SPEC-029: `c2pa.hash.bmff.v2` — nested paths and subsets

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-22                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Step 85 re-checked every public fixture against its source and found one
real file this verifier refuses: `c2pa-rs`'s own `video1.mp4`, which
`c2patool` 0.27.22 calls `Valid` and which this verifier answers with

> the hard binding **c2pa.hash.bmff.v2** is not supported yet

SPEC-027 put v2 out of scope in as many words — *"if a file with one ever
turns up"*. One has, in the reference implementation's fixtures, and it is
not a curiosity: it carries the only trusted RFC 3161 timestamp of any
ISOBMFF file this project holds, and an ingredient.

Step 86 measured what v2 is, by instrumenting c2pa-rs rather than reasoning
about it. **The digest is identical to v3's.** What differs is the
exclusion list:

```
/uuid                                   data: [{offset: 8, value: <C2PA UUID>}]
/ftyp
/meta/iloc
/mfra/tfra
/moov/trak/mdia/minf/stbl/stco          subset: [{offset: 16, length: 0}]
/moov/trak/mdia/minf/stbl/co64          subset: [{offset: 16, length: 0}]
/moof/traf/tfhd                         subset: [{offset: 16, length: 8}]  flags: 01 00 00
/moof/traf/trun                         subset: [{offset: 16, length: 4}]  flags: 01 00 00
```

Six of the eight paths are **nested**; four carry a **`subset`**. v3, as
`c2patool` writes it today, excludes five whole top-level boxes and nothing
finer. These are not the same instruction with a different number: v2 names
the bytes precisely, v3 names whole boxes, and no single default list
serves both — `free` is hashed under v2 and excluded under v3.

The instrumented run shows the digest is untouched by any of this:

```
PROBE marker offset=30686
PROBE range 30686..=31736      ← moov, up to the first stco subset
PROBE range 31761..=33309      ← after it; no marker
PROBE range 33334..=33391      ← after the second; no marker
PROBE marker offset=33392      ← the next top-level box
```

A nested exclusion punches a hole inside a box. It does not make a new box,
so the offset marker stays one per included top-level box, exactly as
SPEC-027 implements it.

## Scope

**In scope**

- Accepting `c2pa.hash.bmff.v2` as a hard binding alongside v3.
- Resolving a **nested** `xpath` — `/moov/trak/mdia/minf/stbl/stco` — to
  the boxes it names, bounded in depth.
- The **`subset`** filter, narrowing an excluded range, with `length: 0`
  meaning to the end of the box (measured, step 86).
- Keeping the digest, the marker rule, the streaming reader and the report
  exactly as they are.

**Out of scope** (each needs its own spec before it may be built)

- **`flags` and `exact`.** `video1.mp4` carries two, on `/moof/traf/tfhd`
  and `/moof/traf/trun` — and `moof` exists only in a fragmented file, so
  neither exclusion can match in this fixture. Implementing them here would
  be writing a branch no test can reach, which this project has refused
  three times already. They stay refused by name until a **fragmented v2**
  stream exists; this project has a fragmented v3 one it built itself, and
  no v2.
- The `length` and `version` filters, for the same reason: no reachable
  file carries one.
- `c2pa.hash.bmff` without a version suffix, if such a thing exists.
- Several renditions, which SPEC-028 already refuses.

## Behavior

- **AC1 — `video1.mp4` verifies** *(happy path; oracle: `c2patool` 0.27.22)*
  - Given `tests/Fixtures/c2pa-rs/video1.mp4`
  - When it is verified without trust settings
  - Then `assertion.bmffHash.match` is reported and the failure codes equal
    those in `tests/Fixtures/c2patool/c2pa-rs/video1.json` — which are
    `signingCredential.untrusted` and nothing else, so the binding, the
    timestamp and the ingredient all pass.

- **AC2 — a changed byte in a hashed region is a mismatch** *(required: the error path)*
  - Given that file with one byte of `mdat` altered, **as a stream rather
    than a fixture**: the file is 828 kB and a copy of it to change one
    byte would be 828 kB more in every clone, so the test writes the
    altered bytes to `php://memory` and verifies that
  - When it is verified
  - Then `assertion.bmffHash.mismatch`.

- **AC3 — a nested path resolves to the boxes it names**
  - Given the exclusion `/moov/trak/mdia/minf/stbl/stco` and this file
  - When the included ranges are computed
  - Then they are exactly the ranges the instrumented c2pa-rs printed in
    step 86: `30686–31736`, `31761–33309`, `33334–33391`, `33392–37953`,
    `37954–92141`, `92142–828570`, with markers before the first, the
    fourth, the fifth and the sixth and before no other.

- **AC4 — `subset` narrows, and `length: 0` runs to the end of the box**
  - Given `subset: [{offset: 16, length: 0}]` on a 40-byte `stco`
  - When the excluded range is computed
  - Then it is offset 16 to the end — 24 bytes — measured against the two
    holes in this file; and with an explicit `length`, exactly that many
    bytes, clipped to the box.

- **AC5 — the data filter tells two `uuid` boxes apart**
  - Given this file, which has a C2PA `uuid` box at 24 **and another at
    33392**
  - When the included ranges are computed
  - Then the first is excluded and the second is hashed, marker and all.
    Every fixture this project made itself had one `uuid` box and nothing
    to tell it apart from; this is the first that proves the filter does
    work rather than ceremony.

- **AC6 — `flags` is still refused by name** *(malformed input)*
  - Given an exclusion carrying `flags`, `exact`, `length` or `version`
  - When it is resolved
  - Then it is refused with the filter named, exactly as SPEC-027 AC5
    requires today. `video1.mp4` carries two `flags` exclusions on `moof`
    paths, and **this must not make it fail**: a filter that matches no box
    in the file is never consulted, so the refusal has to come from
    resolving a path that exists, not from reading the list.

- **AC7 — v3 files are unchanged** *(the drift alarm)*
  - Given every ISOBMFF fixture of SPEC-026, SPEC-027 and SPEC-028, and the
    four corpora
  - When each is verified
  - Then every state and failure code is what it was.

## References

- Measured, step 86 (`notes/step-86-bmff-v2.md`): v2's exclusion list; the
  instrumented ranges and markers; the two `stco` holes pinning `subset`
  with `length: 0`; the second `uuid` box being hashed.
- Measured, step 85 (`notes/step-85-public-fixtures.md`): that this file is
  `Valid` at `c2patool` and `Invalid` here, and why.
- Measured, step 77: the digest rule, which this spec does not touch.
- Oracle: `c2patool` 0.27.22, `tests/Fixtures/c2patool/c2pa-rs/video1.json`.
- Read, not measured: `bmff_to_jumbf_exclusions()` for the `flags`/`exact`
  semantics, which is why AC6 refuses rather than implements them.

## API sketch

No new class and no new public symbol. `BmffHashCheck::matches()` grows
from "top-level type, optional data" to the full path walk, and the label
list beside `LABEL` gains v2.

```php
// src/Hash/BmffHashCheck.php

/** The hard bindings this check answers to, newest first. */
public const LABELS = ['c2pa.hash.bmff.v3', 'c2pa.hash.bmff.v2'];

/**
 * The boxes an xpath names, nested paths included, with their offsets.
 *
 * @param  list<array{offset: int, length: int, type: string, children: list<...>}>  $tree
 * @return list<array{offset: int, length: int}>
 */
private static function resolve(array $tree, string $xpath): array;
```

The descent lives in the extractor (Open question 2, decided), so nothing
parses a box in two places:

```php
// src/Container/IsobmffManifestStoreExtractor.php

/** Boxes deeper than this are refused, never read short (SPEC-029). */
public const DEFAULT_MAX_BOX_DEPTH = 8;

/**
 * The box tree to the configured depth, in file order.
 *
 * @param  resource  $stream
 * @return list<array{offset: int, length: int, type: string, path: string}>
 *
 * @throws ContainerException when a box nests deeper than the bound
 */
public function boxTree($stream): array;
```

`path` is what an `xpath` is matched against — `/moov/trak/mdia/minf/stbl/stco`
built as the walk descends — so resolving an exclusion is a comparison
rather than a second parse.

## Open questions

1. **Whether `LABELS` changes the dispatch in `Verifier`.** Today it reads
   `array_key_exists(BmffHashCheck::LABEL, …)`. With two labels it becomes
   a loop, and `DataHashCheck::OTHER_HARD_BINDINGS` must stop claiming
   `c2pa.hash.bmff` as unsupported for these two. Non-blocker, but it is
   the one place a mistake would make a v2 file fall through to the wrong
   check.
2. **Where the nested walk lives.** SPEC-026's extractor walks the top
   level and `Hash` already depends on `Container`. Either the extractor
   grows a depth-bounded child walk, or this check does its own — which
   would be a second truth about box parsing, and this project has refused
   that before. **Decided by Maurice van Loon, 2026-09-22: the extractor,
   with a bound.** `IsobmffManifestStoreExtractor` grows a depth-bounded
   child walk and this check asks it; nothing parses a box in two places.
   The walk stays in `Container`, which `Hash` already depends on, so no
   Deptrac arrow moves.
3. **How deep is deep enough.** `/moov/trak/mdia/minf/stbl/stco` is six
   segments. **Decided with question 2: eight**, named here rather than
   left in the code. Six is what the deepest real path needs, eight leaves
   room for a container this project has not met, and a file nested deeper
   is **refused by name** rather than silently under-read — a walk that
   stops early and reports what it found would hash bytes the signer
   excluded and call the result a match.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
