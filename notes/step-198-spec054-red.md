# Step 198 — SPEC-054's tests, red

SPEC-054 adds `requirements.php`: one file, readable by PHP 7.4 and later,
that tells a host whether the verifier can run before the host loads any of
`src/`. This step writes the tests and shows them failing. No
`requirements.php` exists yet.

## Amendment 1, before the tests

The draft named PHP 7.4 only. Maurice pointed out that the check must hold on
every PHP the verifier cannot run on. The amendment widens AC4, the scope and
the CI job to 7.4, 8.0, 8.1 and 8.2.

## Measured: what each older PHP cannot parse

`php -l` on every file in `src/`, in the official `php:<v>-cli` Docker images:

| PHP | files that do not parse (of 83) | on what |
|-----|---------------------------------|---------|
| 7.4 | 70 | 62 on `readonly` classes; the rest on promoted constructor properties |
| 8.0 | 70 | the same |
| 8.1 | 62 | all on `readonly` classes |
| 8.2 | 4  | typed class constants: `Manifest/Manifest.php`, `Manifest/ManifestGraph.php`, `Manifest/UpdateManifestCheck.php`, `Cli/Command.php` |

8.2 is the near miss: it reads almost everything, but `Verifier` imports
`Manifest\Manifest`, so every verification hits a file 8.2 cannot parse. The
draft's own count ("68 places", from a grep) was replaced by this measurement
in the spec's Problem section.

## The tests

- `tests/Unit/RequirementsTest.php`, group `SPEC-054`: AC1, AC2, AC3, AC5,
  AC6 and AC7. The closure's two optional arguments (version and extension
  list) let one PHP exercise every branch.
- `tests/Support/requirements-probe.php`, in PHP 7.4 syntax: it requires the
  file twice in a clean process (a second spelling of the path, as two
  bundled copies would) and reports what it returned and what it declared.
  RequirementsTest runs it through `PHP_BINARY` for AC6. With
  `--assert-unsupported` it checks AC4 itself, because Pest 4 does not run
  below 8.3.
- `.github/workflows/ci.yml`: a job `older-php` on 7.4, 8.0, 8.1 and 8.2
  runs `php -l` on both files and the probe with `--assert-unsupported`.
  `all green` now needs it too.

## Red, measured

- `vendor/bin/pest --group=SPEC-054` on PHP 8.5.8 and 8.3.33: 14 failed.
  AC1–AC5 fail with `RuntimeException: requirements.php is not there`; AC6
  because the probe exits 2 with the same reason; AC7 because the archive
  has no `requirements.php`.
- The probe with `--assert-unsupported` in `php:7.4-cli`, `8.0`, `8.1` and
  `8.2`: `php -l` passes on the probe, then exit 2, "requirements.php is not
  there".
- PHPStan (level max) and Pint pass on both new files.

AC7 packs `HEAD` with `git archive`, so it turns green only once the file is
committed, not merely written.
