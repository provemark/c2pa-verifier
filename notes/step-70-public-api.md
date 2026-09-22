# Step 70 — The public API, tests first

*2026-09-22.* SPEC-025 was approved the same day, with both of its
decisions taken: `VerificationReport::$store` is `@internal`, and so are
the seven exception types a caller can never meet, leaving
`TrustException` as the ninth class of the contract. This step is the red
phase — seven failing tests and the recorded surface they compare against,
no implementation.

```
Tests: 7 failed, 374 passed (7294 assertions)
```

## The recorded surface is a file

`tests/Fixtures/api/public-surface.txt`, ninety-one lines, one per public
symbol of the nine contract classes:

```
Cli\Command :: const USAGE
Cli\Command :: method __construct
Cli\Command :: method run
…
```

It is a file rather than an array inside the test for the same reason
SPEC-023 put the shipped-paths decision in `.gitattributes`: a promise that
changes should change **visibly**, as a diff somebody reads, not as an
assertion somebody quietly edits. Ninety-one symbols against the six
hundred the library exposes — the sixteen per cent step 69 measured, now
written down.

It was generated from today's code, which means it *records* the contract
rather than dictating it. That is the right direction for a first
snapshot: the argument about whether a member belongs in the contract is a
separate one, and pretending to settle it here would hide it.

## What each criterion catches, and why it is red

| AC | red because, today |
|---|---|
| AC1 (the snapshot) | `apiSurface()` does not exist |
| AC1 (not internal) | `apiPublicClasses()` does not exist |
| AC2 (the real run) | `apiCheck()` does not exist; once it does, about sixty classes are in neither set |
| AC2 (the error path) | the same, on synthetic input: a class in neither set, and a contract class marked `@internal`, must each be a finding naming it |
| AC3 | `$store` carries no `@internal` and its docblock says nothing about changing |
| AC4 | `apiCompare()` does not exist |
| AC5 | the README has no `## Public API` section and never says what may change |

AC3 and AC5 are worth pointing at: they fail on **what is missing from the
code and the README**, not on a missing function. They would be red even
if the checker existed, which is what makes them tests of the decision
rather than of the tooling.

## The red phase says something, instead of printing a wall

AC2's real run will report roughly sixty findings once the checker exists —
correct, and unreadable. The test therefore asserts on the **count** and
puts the first three names in the failure message:

```php
expect(count($result->findings))->toBe(0, sprintf(
    '%d classes are in neither set; first three: %s', …
));
```

A red phase that prints sixty lines teaches nothing; one that says "sixty,
starting with these three" tells you the shape of the work.

## Red on purpose after this step

- Pest: 7 failed, 374 passed.
- PHPStan: 27 errors, every one of them following from the four undefined
  functions in the new test file.
- Pint and `bin/spec-check.php` (26 specs, 31 test files) are clean.

70b writes `bin/api-check.php`, marks about sixty classes `@internal`,
marks `$store`, and gives the README its `## Public API` section. It will
be a large diff and a small change: sixty docblocks, no behaviour.

---

# 70b — the line drawn

All seven green, **381 passed** in all, `composer check` exit 0.

```
$ php bin/api-check.php
69 public classes: 9 in the contract, 60 marked @internal
contract surface: 91 symbols
every public class is either in the contract or marked @internal
the recorded surface matches
```

Sixty classes gained an `@internal` line. No behaviour changed anywhere:
the diff is sixty docblocks, one property's docblock, a README section and
a checker.

## The snapshot was made to catch something

AC4 asks for more than a recorded file — a snapshot that has never caught
anything is a file, not a test. So a public method was added to
`Verifier` by hand and the suite run against it:

```
FAILED  it AC1: the recorded surface is exactly what the contract classes expose…
$ php bin/api-check.php
  Verifier\Verifier :: method reset: public but not recorded
```

and after reverting, seven green and *"the recorded surface matches"*. The
same holds in the other direction: removing `verify()` from the record
reports *"recorded but no longer public"*.

## AC3 was wrong, and the README is why

The criterion said the README "does not document `$store`". Writing the
section showed that silence is the wrong rule. A reader whose IDE offers
`$report->store` will reach for it; what protects them is being told
plainly that it is unsupported, not being left to infer it from an
omission. So the README names it:

> `$report->store` is the parsed manifest store, and it is `@internal`. It
> works, it will keep working, and it is not part of the promise — reading
> it reaches the whole parse model, which will change as this verifier
> gains formats. If you need something from it that the report does not
> give you, that is worth an issue rather than a workaround.

Amended in the spec (SPEC-025 amendment 1) before the test was changed:
the criterion now forbids presenting `$store` as part of the report and
requires any mention of it to say what it is. Nothing about the property or
its docblock changed — only the rule about how the README may speak of it.

The last sentence is deliberate. A library that marks something internal
and then ignores the people who needed it has solved its own problem, not
theirs.

## Two traps, both old friends

**Pest's `toContain()` is variadic.** `expect($readme)->toContain($class, $short)`
reads `$short` as a second needle, not as a failure message, and the
failure then names the wrong thing — the eighth time this project has hit
it. `str_contains(...)->toBeTrue($message)` is the shape that works.

**A wrapped line is not the string you asserted on.** The README said
"may change in any\nrelease" across two lines, and the test looking for
"may change in any release" failed with the whole README printed as
"expected". The sentence was reflowed rather than the assertion loosened:
the promise should read as one line anyway.

## What the marking did not do

Nothing was renamed, moved or removed. A member in the wrong place is
still in the wrong place; `@internal` records where the line is today, it
does not redraw the code behind it. That was the scope decision in the
spec and it held: the diff contains no change a caller could observe at
runtime.

And the version number of a first tag is still open. This step makes
either answer honest — `0.1.0` promising nothing, or `1.0.0` promising
these ninety-one symbols — which is all a spec can do about a decision
that is the maintainer's.
