# SPEC-025: The public API — what a version number promises

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
  - Given the nine classes named as the contract (`Verifier\Verifier`,
    `Verifier\VerificationReport`, `Report\ValidationResult`,
    `Report\ValidationStatus`, `Report\ValidationState`,
    `Report\StatusCode`, `Trust\TrustSettings`, `Trust\TrustException`,
    `Cli\Command`)
  - When the public surface of each is read by reflection
  - Then it equals the recorded snapshot, symbol for symbol; and none of
    the nine carries `@internal` on its class docblock.

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
    `toArray()` and `toJson()`, and does not document `$store`. The
    property itself still works and is still public: nothing is taken away.

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
