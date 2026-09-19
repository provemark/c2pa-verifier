# SPEC-###: <title>

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft \| approved \| implemented \| superseded    |
| Author     | <name>                                            |
| Approved   | <name + date, or — while draft>                   |
| Supersedes | <SPEC-### or —>                                   |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

<Why this exists. The user/domain need. What breaks or is impossible without
it. Point at the governing rules under `docs/` and at the C2PA specification
by version and section number, never at a file that is not in the repository.>

## Scope

**In scope**

- <bullet>

**Out of scope** (each needs its own spec before it may be built)

- <bullet>

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-###')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

- **AC1 — <happy path name>**
  - Given <precondition>
  - When <action>
  - Then <observable, verifiable outcome>

- **AC2 — <error path name>** *(required: error / malformed input)*
  - Given <invalid precondition>
  - When <action>
  - Then <specific failure: exception type / error value, no partial side effects>

## References

<What this spec is measured against, kept apart from what it is reasoned from.>

- Specification: <C2PA Technical Specification version and section(s), e.g.
  "C2PA 2.4 §15.x"; ISO/IETF documents by number>
- Oracle: <the tool and pinned version, the fixture, and the command whose
  output the acceptance criteria must match — e.g. "c2patool 0.27.22,
  `tests/Fixtures/...`, `c2patool <file> --detailed`">
- Reasoned: <claims made from reading rather than running, if any>

## API sketch

<Illustrative only — not binding implementation. Signatures, value objects,
interfaces. Note `final`, `readonly`, `strict_types=1` intentions. Show the
shape a caller sees.>

```php
// namespace Provemark\C2paVerifier\...;
```

## Open questions

- <unresolved decision; blocker vs. non-blocker>

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
