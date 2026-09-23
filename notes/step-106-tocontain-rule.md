# Step 106 — A rule for the mistake this project made thirteen times

*2026-09-23.* Pest's `toContain()` is variadic: every argument is another
needle. So this,

```php
expect($codes)->toContain($needle, $message);
```

does not attach a message to a failure. It asserts that the haystack holds
the message too, and the message a failure was supposed to print is gone.

This project hit it **thirteen** times. Twice it mattered beyond the
message: once a test that should have been green read as red (step 87a),
and the note of each occurrence carries the count at the time, which is how
the total is known.

## The rule

`tests/Unit/SpecCheckTest.php`, with the other checks on the way of working
(SPEC-000): **every `->toContain()` in the suite takes exactly one needle.**
For genuinely several, chain them: `->toContain($a)->toContain($b)`. For a
message, `str_contains(...)` or `in_array(...)` with `->toBeTrue($message)`,
which takes one.

## Why it reads the code with a tokenizer

The first version searched the text and reported **five** violations. Three
of them were the comments that warn about this very trap — including two I
had written myself — because they contain the words
`->toContain($needle, $message)` while showing what *not* to write.

A rule that flags its own documentation is worse than no rule: the next
person deletes the warnings to make the build pass. So `spec000ToContainCalls()`
runs `token_get_all()` and counts commas at depth one, only for a `toContain`
preceded by an object operator. The tokenizer knows a comment from a call,
and a comma inside `'OK: 1 spec(s), 1 test file(s)'` from an argument
boundary — the earlier text-based attempt got that wrong too.

## What it found

One live violation, the thirteenth:

```php
expect($codes)->not->toContain(StatusCode::GeneralError, $fixture);
```

`$fixture` is the file being looped over, meant as the message. The absence
of `GeneralError` was still checked correctly — a list of `StatusCode` enums
cannot contain a string — so nothing was wrong with the verdict. What was
lost is the only thing that matters when that loop fails: which of the four
fixtures it was. Now `in_array(...)->toBeFalse($fixture)`.

## Seen red, and seen red again

The rule was red when written, on that real violation. It was then
falsified deliberately: a second needle added to an untouched assertion in
`ReportTest.php` line 160, the rule reported
`tests/Unit/Report/ReportTest.php:160 passes 2 needles`, and the file was
restored. A check that has not been seen failing on a case it was not
written against is not yet a check.

422 green, serial and parallel.
