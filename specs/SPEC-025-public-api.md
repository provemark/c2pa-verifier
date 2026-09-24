# SPEC-025: The public API — what a version number promises

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-22                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

There is no tagged release. The moment there is one, every public symbol in
`src/` becomes a promise: removing it, renaming it, or changing a parameter
is a breaking change, whatever the README says. Step 69 measured what that
promise would cover today.

| | |
|---|---|
| public classes, interfaces and enums | **69** |
| public methods / constants / properties | 192 / 137 / 202 |
| **total public symbols** | **600** |
| marked `@internal` | **0** |

Against that, the README's example names **two** classes and four
accessors, and the CLI shim uses five classes. The DER reader, the CBOR
item limits, every JUMBF box type, the ASN.1 tag constants and
`MemoryBudget::parseLimit()` are all reachable and unmarked.

That those layers are public is not itself wrong: this library is built so
that each layer can be tested on its own, and the tests reach into them by
design. What is missing is any statement of **which of them a stranger may
build on** — and without it, the first tag promises all six hundred by
default, which is the opposite of what a careful library does.

Drawing the line where the README, the CLI and the sister library's adapter
put it gives nine classes and about **a hundred symbols, a sixth** of the
surface. One property undoes most of that: `VerificationReport::$store` is
a `?ManifestStore`, which exposes `$manifests` and `$active`, which are
`Manifest` objects exposing claim, assertions and signature — the whole
parsed model, through one property on the report. Freezing it would freeze
the parse model of a library that has not yet met ISOBMFF (M8) or the
formats after it.

**The maintainer decided on 2026-09-22 that `$store` is `@internal`**: an
escape hatch, useful and unsupported, which is how it has been used so far
— the tests reach through it, the README never mentions it.

This is also what keeps the promise honest in the other direction. A caller
who needs the parse model is not blocked: the property stays, it works, and
its docblock says what it is. Nothing is taken away; what changes is that
the project stops implying support it never gave.

## Scope

**In scope**

- Naming the contract: the classes and members a caller may build on,
  written where a caller will read it.
- `@internal` on every public class in `src/` that is not in the contract,
  and on `VerificationReport::$store`.
- A snapshot of the public surface, so that adding, removing or renaming a
  public symbol is a deliberate act with a diff.
- The README's statement of what may change without notice.

**Out of scope** (each needs its own spec, or is not a rule)

- **Which version number the first tag carries.** `0.1.0` or `1.0.0` is the
  maintainer's decision and is not a property of the code. This spec makes
  either honest; it does not choose.
- Enforcement beyond docblocks: no `@internal` checker in CI beyond what
  PHPStan already does, no runtime restriction, no sealing of classes
  beyond the `final` they already carry.
- Semantic-versioning tooling, BC-break detectors, or API diffs between
  releases.
- Renaming, moving or removing anything. This spec documents the surface;
  it does not reshape it. A member that is in the wrong place stays in the
  wrong place until a spec moves it.

## Behavior

- **AC1 — the contract is a list, and the list is exact**
  - Given the ten classes named as the contract (`Verifier\Verifier`,
    `Verifier\FragmentedVerifier`, `Verifier\VerificationReport`,
    `Report\ValidationResult`, `Report\ValidationStatus`,
    `Report\ValidationState`, `Report\StatusCode`, `Trust\TrustSettings`,
    `Trust\TrustException`, `Cli\Command`) *(amended 2026-09-22, see
    Amendments 2)*
  - When the public surface of each is read by reflection
  - Then it equals the recorded snapshot, symbol for symbol; and none of
    the ten carries `@internal` on its class docblock.

- **AC2 — everything else says so** *(required: the error path)*
  - Given every other public class, interface and enum in `src/`
  - When its class docblock is read
  - Then it carries `@internal`. A class in neither the contract nor the
    `@internal` set is a finding naming it — not a default either way, for
    the same reason SPEC-023 refuses to let an unclassified top-level path
    ship by accident.

- **AC3 — the escape hatch is marked, and unmentioned**
  - Given `VerificationReport::$store`
  - When its docblock is read, and the README searched
  - Then the docblock carries `@internal` and says in words that the parse
    model may change in any release; the README documents `$result`,
    `$hasManifest`, `$remoteManifestUrl`, `$format`, `$signatureInfo`,
    `toArray()` and `toJson()` as the report, and **where it names `$store`
    it names it as unsupported** — never in the list of what the report
    offers *(amended 2026-09-22, see Amendments 1)*. The property itself
    still works and is still public: nothing is taken away.

- **AC4 — the snapshot notices a change nobody meant**
  - Given the recorded public surface
  - When a public symbol is added to, removed from or renamed in a contract
    class
  - Then the test fails naming the symbol and the class. Shown by applying
    such a change and watching it fail, as step 65b did with its mutants —
    a snapshot that has never caught anything is a file, not a test.

- **AC5 — the README says what may change**
  - Given the README
  - When its API section is read
  - Then it names the contract, says that everything else is marked
    `@internal` and may change in any release, and says which of the two a
    reader is looking at when they open a class.

## References

- Measured, step 69 (`notes/step-69-api-surface.md`), by reflection over
  every class in `src/`: the table in the Problem section; the README
  naming two classes and four accessors; the CLI using five; the contract
  at 99 of 600 symbols; eight exception types, of which SPEC-013 turns
  seven into statuses before the public boundary.
- The shape of the snapshot follows SPEC-023's classification of top-level
  paths: a declared list, checked against reality, with "in neither" as a
  finding rather than a default.
- Reasoned: that `@internal` is documentation rather than enforcement, and
  that this is the right weight — the layers are deliberately testable on
  their own, so sealing them would cost more than the promise is worth.
- No C2PA section applies. This is the third spec here with no
  specification behind it, after SPEC-000 and SPEC-023.

## API sketch

Nothing in `src/` gains a symbol. The snapshot and its checker are tooling,
outside the Deptrac layers, in the shape `bin/spec-check.php` and
`bin/package-check.php` already use.

```php
// bin/api-check.php — illustrative, not binding

/** @return array<string, list<string>> class => its public symbols, sorted */
function apiSurface(string $sourceDirectory): array;

/** @param array<string, list<string>> $recorded */
function apiCheck(array $recorded, string $sourceDirectory): ApiCheckResult;

final readonly class ApiCheckResult
{
    /** @param list<string> $findings one sentence each, naming class and symbol */
    public function __construct(public array $findings) {}

    public function exitCode(): int;
    public function render(): string;
}
```

The recorded surface is a file rather than an array in a test, so that a
change to it appears as a reviewable diff.

## Open questions

The one blocker was decided on 2026-09-22, the day the draft was written;
the answer is recorded in place below rather than removed, so that the
reasoning that led to it stays readable.

1. **The seven layer exceptions.** `Asn1Exception`, `CborException`,
   `ContainerException`, `CoseException`, `JumbfException`,
   `ManifestException` and `TimestampException` never reach a caller:
   SPEC-013 turns each into a status in the report. Only `TrustException`
   escapes, from `TrustSettings::fromJson()`, and the CLI catches exactly
   that one. The proposal is that the seven are `@internal` and
   `TrustException` is in the contract — the same reasoning as `$store`, in
   a smaller shape. **Decided by Maurice van Loon, 2026-09-22: yes.** The
   seven are `@internal`; `TrustException` joins the contract as the ninth
   class, because it is the one exception a caller can actually meet and
   therefore the one they may need to catch. AC1's list is nine classes,
   not eight.
2. **Where the contract is written.** The README is where a caller looks,
   but it is also the file most likely to drift from the code. The
   alternative is a `docs/api.md` that the snapshot test can read, with the
   README pointing at it. Non-blocker.
3. **Whether `Cli\Command` belongs in the contract at all.** It exists for
   `bin/c2pa-verify`; a caller who wants the CLI runs the binary, and a
   caller who wants the library calls `Verifier`. Including it promises a
   shape that has no second user. Non-blocker, but it is one of the eight.

## Amendments

1. **2026-09-22, step 70b, found while writing the README** — AC3 said the
   README "does not document `$store`". Silence is the wrong rule. A reader
   whose IDE offers `$report->store` will use it; what protects them is
   being told plainly that it is unsupported, not being left to guess from
   an omission. The criterion now forbids presenting it as part of the
   report and requires that any mention of it says what it is, which is
   what the README does: *"it works, it will keep working, and it is not
   part of the promise"*. The test asserts on that shape instead of on
   absence. Nothing about the property or its docblock changed.
   Confirmed by Maurice van Loon, 2026-09-22 (step 75).

2. **2026-09-22, step 83b, defined in SPEC-028 and approved with it** — the
   contract grows to ten classes: `Verifier\FragmentedVerifier`, which
   takes a DASH init segment and its fragments. This is the first time a
   class has been *added* to the promise rather than the surface merely
   being recorded, and it is what the snapshot is for: the diff is two
   lines, `method merkleMapOf` and `method verify`, in review rather than
   discovered by a consumer.

   The alarm rang on its own, twice. `ApiSurfaceTest` AC2 failed the moment
   the class landed — in neither the contract nor marked `@internal` — and
   AC5 failed until the README's Public API table named it. Neither had to
   be remembered.

   Confirmed by Maurice van Loon, 2026-09-22 (step 84).



3. **2026-09-22, step 92b, with SPEC-030's implementation** — `StatusCode`
   grows by four cases: `signingCredential.ocsp.revoked`, `.notRevoked`,
   `.unknown` and `.skipped`. The enum is one of the ten contract classes,
   so the recorded surface goes from 95 symbols to **99**, and a caller
   matching exhaustively on `StatusCode` now has four more arms to cover.

   **Weight B: the report's shape grew, no verdict of an existing file
   changed** — SPEC-030 AC9 asserts exactly that, over twelve verdicts
   measured the step before. What is new is that every file now carries one
   `signingCredential.ocsp.*` line, because a check that was skipped has to
   be visible.

   The alarm rang on its own again: `bin/api-check.php`, which joined
   `composer check` in step 89 after drifting for four commits, refused the
   run with "public but not recorded" on all four cases before any of this
   was written down.

   Confirmed by Maurice van Loon, 2026-09-22 (step 93).

4. **2026-09-24, step 111b, with SPEC-031's implementation** — the
   contract grows to **eleven classes**: `Trust\TrustAnchorSet`, one
   `trust.anchors` entry, which `TrustSettings::$anchorSets` holds.
   `TrustSettings` gains that property and `MAX_ANCHOR_ENTRIES`. The
   recorded surface goes from 99 symbols to **111**.
   - Kept out on purpose: the four helpers that combine the lists live on
     the `@internal` `ChainCheck`, because every public method of a
     contract class is a promise.
   - The README's table names the new class (AC5).

   **Weight B for the API, nothing removed.** `bin/api-check.php` refused
   the run on its own: first with *"in neither the contract nor marked
   @internal"* for the new class, then with *"public but not recorded"*
   for each of its twelve symbols.

   Confirmed by Maurice van Loon, 2026-09-24 (step 120).


5. **2026-09-24, step 122b, with SPEC-032's implementation** — `StatusCode`
   grows by one case, `AssertionExternalReferenceMalformed =
   'assertion.external-reference.malformed'` (C2PA 2.4 §15.10.3.2.2). The
   recorded surface goes 111 → **112** symbols, and a caller matching
   exhaustively on the enum has one more arm to cover. The API check
   refused the run with *"public but not recorded"* until the snapshot
   had the line.

   **Weight B: the vocabulary grew, verbatim.**

   Confirmed by Maurice van Loon, 2026-09-24 (step 122).

6. **2026-09-24, step 125b, with SPEC-033's implementation** — `StatusCode`
   grows by two cases, `AssertionActionIngredientMismatch =
   'assertion.action.ingredientMismatch'` and
   `AssertionActionSoftBindingMissing = 'assertion.action.softBindingMissing'`
   (C2PA 2.4 §15.10.3.2.3). The recorded surface goes 112 → **114**. Specs'
   own tests no longer assert the total (SPEC-032 amendment 2, SPEC-033
   amendment 2); `ApiSurfaceTest` does.

   **Weight B: the vocabulary grew, verbatim.**

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

All tests are in `tests/Unit/ApiSurfaceTest.php`, group `SPEC-025`.

| Acceptance criterion | Test (name) | Source (file/symbol) |
|---|---|---|
| AC1 | `AC1: the recorded surface is exactly what the contract classes expose today`; `AC1: no class of the contract is marked internal` | `bin/api-check.php :: apiSurface(), apiPublicClasses()`; `tests/Fixtures/api/public-surface.txt` (91 symbols, 9 classes) |
| AC2 | `AC2: every public class outside the contract says it is internal`; `AC2: a class in neither set, and a contract class marked internal, are both findings` | `bin/api-check.php :: apiCheck(), ApiCheckResult`; the `@internal` docblock of 60 classes in `src/` |
| AC3 | `AC3: the escape hatch is marked and unmentioned` | `src/Verifier/VerificationReport.php :: $store` (docblock); `README.md` (Public API) |
| AC4 | `AC4: the snapshot catches a symbol nobody recorded` | `bin/api-check.php :: apiCompare()` |
| AC5 | `AC5: the README says what the contract is and what may change` | `README.md :: ## Public API` |
