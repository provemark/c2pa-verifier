# SPEC-054: A requirements check that older PHP can read

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The verifier requires PHP `^8.3` with `ext-openssl` and `ext-mbstring`
(`composer.json`). A host that runs on older PHP too cannot find out
whether it may use the verifier without loading it, and loading it is
what goes wrong: `src/` uses syntax from PHP 8.1 to 8.3 (`readonly`
classes, enums, typed class constants; 68 places, counted with
`grep -rn "readonly class\|^enum \|const string\|const array" src | wc -l`).
PHP 7.4 cannot parse such a file. Including one is a fatal parse error for
the whole request, not an exception the host can catch. A version check
inside the verifier's classes is therefore too late: the check has to sit
in front of them, in a file older PHP can read.

The case is concrete. The WordPress AI plugin supports PHP 7.4 (its
`composer.json`: `"php": ">=7.4"`, `"platform": {"php": "7.4"}`) and runs
its tests on 7.4 to 8.4. It has an issue asking for media verification
(WordPress/ai#1071). A host like that would bundle the verifier as files
(Composer refuses a `php ^8.3` package on a 7.4 platform) and use it only
where it can run. Today every such host has to write that check itself,
and the one detail it is likely to miss is that a single `require` of the
wrong file on 7.4 takes the site down.

There is a second, softer requirement. Ed25519 signatures need
`ext-sodium` on PHP 8.3; on 8.4 and later OpenSSL verifies them too
(SPEC-009 amendment 1, measured on CI). Without either, the verifier still
runs, and an Ed25519 manifest fails closed: `Cose\SignatureVerifier`
throws a `CoseException` with `algorithm.unsupported`, which `Verifier`
catches, so the caller gets `Invalid`. That is not a reason to refuse the verifier, but a host
should be able to say so before a user meets it.

This spec adds one file that answers "can the verifier run here, and if
not, why" and loads nothing else. It is the one deliberate exception to the
fixed design decision "pure PHP `^8.3`": this file, and only this file, is
written for PHP 7.4.

## Scope

**In scope**

- *One file, `requirements.php`, at the package root*, shipped in the dist
  archive (SPEC-023). It declares nothing: no namespace, class, function,
  constant or global. It returns a closure, so it can be required any
  number of times, by any number of bundled copies, without a clash.
- *The closure* takes two optional arguments, the PHP version and the list
  of loaded extensions, which default to `PHP_VERSION` and
  `get_loaded_extensions()`. The arguments exist so every branch can be
  tested on one PHP.
- *It returns an array* with:
  - `supported` (bool): true only when PHP is 8.3 or later and both
    `openssl` and `mbstring` are loaded;
  - `missing` (list of strings, stable codes): `php>=8.3`, `ext-openssl`,
    `ext-mbstring`, in that order, only those that fail;
  - `ed25519` (bool): whether Ed25519 signatures can be verified here
    (`sodium` loaded, or PHP 8.4 or later with `openssl`).
- *Syntax PHP 7.4 can parse*, checked on PHP 7.4 in CI (`php -l`) and by
  running the file there.
- *A CI job on PHP 7.4* that lints the file and runs a plain PHP script
  (Pest does not run on 7.4) asserting the result.
- *README*: a short section "Before loading" with the three lines a host
  needs, and a line in "Public API" naming the file and its return shape as
  part of the contract (SPEC-025).

**Out of scope** (each needs its own spec before it may be built)

- Making any of `src/` run on PHP older than 8.3.
- A bundle for vendoring (a prefixed namespace, a zip, trust lists);
  that waits on the WordPress AI maintainers' answer.
- Checking a stream, a file format or trust settings; this file checks
  the platform only.
- Changing `composer.json`'s requirements.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-054')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

- **AC1 — a supported platform**
  - Given PHP 8.3 (or 8.4, 8.5) in CI with `openssl`, `mbstring` and
    `sodium`
  - When `(require 'requirements.php')()` is called
  - Then `supported` is true, `missing` is empty, `ed25519` is true; and a
    `Verifier::verify()` of a fixture afterwards gives the same verdict as
    without the check

- **AC2 — each reason on its own**
  - Given the closure called with a version and an extension list
  - When the version is `7.4.33`, or `openssl` is absent, or `mbstring` is
    absent, or all three
  - Then `supported` is false and `missing` names exactly the failing
    codes, in the fixed order

- **AC3 — Ed25519 apart from support**
  - Given version `8.3.0` with `openssl`, `mbstring` and without `sodium`;
    and version `8.4.0` with the same list
  - When the closure is called
  - Then both are `supported`; `ed25519` is false for 8.3.0 and true for
    8.4.0

- **AC4 — on PHP 7.4 it answers and loads nothing** *(required: error path)*
  - Given PHP 7.4 in CI
  - When `php -l requirements.php` runs, and a script requires the file,
    calls the closure and lists the declared classes before and after
  - Then the lint passes, `supported` is false with `php>=8.3` in
    `missing`, the script exits 0, and no class, function or constant was
    declared by the file

- **AC5 — an unreadable version fails closed** *(required: malformed input)*
  - Given a version string that `version_compare()` cannot place (`''`,
    `'abc'`) or an extension list that is not a list of strings
  - When the closure is called
  - Then `supported` is false and `missing` contains `php>=8.3` (for the
    version) or the extensions it could not confirm; it does not throw

- **AC6 — twice is harmless**
  - Given the file required from two paths (two bundled copies)
  - When both closures are called
  - Then both return the same array and nothing is redeclared

- **AC7 — it ships**
  - Given `bin/package-check.php` (SPEC-023)
  - When the dist archive is built
  - Then `requirements.php` is in it

## References

- Specification: none in C2PA; this is packaging. PHP's own rules:
  `version_compare()`, https://www.php.net/manual/en/function.version-compare.php ;
  `get_loaded_extensions()`,
  https://www.php.net/manual/en/function.get-loaded-extensions.php .
- Oracle: PHP 7.4's parser (`php -l` on 7.4 in CI) for AC4; the existing
  CI matrix (8.3, 8.4, 8.5 with openssl, mbstring, sodium) for AC1; SPEC-009
  amendment 1 for the Ed25519 boundary in AC3.
- Measured: the 68 places with 8.1–8.3 syntax in `src/` (command above);
  the WordPress AI plugin's `composer.json` (`>=7.4`, platform 7.4) and its
  CI matrix (`test.yml`: 7.4 to 8.4), read with `gh api` on 2026-09-30.
- Reasoned: that Composer refuses a `php ^8.3` package on a 7.4 platform,
  so such a host bundles the files; that a returned closure avoids clashes
  between bundled copies where a declared function would not.

## API sketch

```php
<?php
// requirements.php — PHP 7.4 syntax, declares nothing.

return static function (?string $version = null, ?array $extensions = null): array {
    // ...
    return ['supported' => false, 'missing' => ['php>=8.3'], 'ed25519' => false];
};
```

A host:

```php
$check = (require __DIR__ . '/lib/c2pa-verifier/requirements.php')();
if ($check['supported']) {
    // only now register the autoloader for src/
}
```

## Open questions

- *PHPStan and Pint* (non-blocker): the file is outside `src/`, `tests/`
  and `bin/`, so PHPStan does not analyse it today. Proposal: add it to the
  paths (level max still applies; PHPStan reads 7.4 syntax) and let Pint
  format it, with the 7.4 lint in CI as the guard against a formatter rule
  that introduces newer syntax.
- *Is the file part of the public API?* (blocker): proposal yes, listed in
  README "Public API" beside the classes, so a change to its return shape
  is a promise broken, not a detail.

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
