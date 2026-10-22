# Step 366 — 0.7.1 released

*2026-10-22.*

- The annotated tag `v0.7.1` is on `fe6906d` (steps 363–365 and the CI fix)
  and pushed.
- CI on `main` (run 39830766540) and on the tag (run 39831217386): 8/8
  green each. On the tag, `requirements.php (PHP 7.4)` showed
  `in_progress` in the API for a few minutes after the run reported
  success, with every step already green; it then completed with
  success.
- Packagist lists `v0.7.1`. A fresh `composer require
  provemark/c2pa-verifier:^0.7.1` installed it; under the fixtures' settings
  `acknowledged-kind/recorded-failure.jpg` read `Trusted` (exit 0) and
  `recorded-success.jpg` `Invalid` (exit 1).
- No GitHub release, as before.

The first push of steps 363–365 failed CI (run 39830291243) on a test that
called a helper from another test file; see the addendum to step 365.
