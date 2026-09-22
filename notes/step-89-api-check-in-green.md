# Step 89 — `bin/api-check.php` joins the definition of green

*2026-09-22.* Step 87b found `bin/api-check.php` reporting
`FragmentedVerifier: in neither the contract nor marked @internal` — a
finding four commits old. The class went into the contract in step 83, into
`tests/Unit/ApiSurfaceTest.php` and into the recorded surface, and not into
the script. Nothing failed, because the script was something a maintainer
ran by hand.

Two things were wrong, and running the script in CI only fixes one of them.

## The list existed twice

`spec025Contract()` in the test held ten names; the `$contract` array inside
the script held nine. A promise with two definitions has none — whichever
you read, you cannot tell whether the other agrees. Adding the script to
`composer check` would have made both fail loudly, which is better than
silence and still worse than having one list.

`bin/api-check.php` now holds `apiContract()`, and the test reads it. The
same went for the narrowing helper: `apiClass()` lives in the script and
`spec025Class()` calls it. What still makes a change to the contract visible
in review is `tests/Fixtures/api/public-surface.txt` — add a class to
`apiContract()` and the recorded surface grows by every symbol that class
exposes, in the diff, where a reviewer sees it.

## And the script runs

```
"check": ["@spec-check", "@api-check", "@lint", "@analyse", "@deptrac", "@test"]
```

So it runs on PHP 8.3, 8.4 and 8.5 in CI. Measured, on a deliberate drift:

```
$ echo 'Verifier\Verifier :: method verifyEverything' >> tests/Fixtures/api/public-surface.txt
$ composer api-check
  Verifier\Verifier :: method verifyEverything: recorded but no longer public
Script php bin/api-check.php handling the api-check event returned with error code 1
exit: 1
```

A check that cannot be seen failing is not a check, which is why that line
is here rather than an assertion that it would have worked.

## What it does not fix

With one list, the script and `ApiSurfaceTest` now assert the same two
things about the same names, so as a guard the script is a second copy of a
test that already runs. What it adds is that the command the README tells a
reader to run is a command CI proves still works, and a report a person can
read without running Pest. That is worth the fifth of a second; it is not
worth calling it a new alarm.

## Measured

`composer check`: 411 passed (7494 assertions), PHPStan max clean, Deptrac
0 violations, api-check 10 classes / 95 symbols / surface matches.
