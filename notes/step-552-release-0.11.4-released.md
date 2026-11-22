# Step 552 — 0.11.4 released

*2026-11-22.* Maurice: "commit en push maar" (step 551), then "tag en
release v0.11.4 maar". Asked beforehand whether more should go in, he
chose to release now. The status-code moves of `c2patool` 0.29.0 change
no verdict and come later, each as its own amendment.

## Done

- **Commit:** `70d683e` (step 551), pushed. CI run 45011406977, 8 of 8
  jobs `success`.
- **Tag:** `v0.11.4`, annotated, on `70d683e`, pushed. CI on the tag: run
  45013043585, 8 of 8 `success` on the first attempt.
- **GitHub release:** `v0.11.4`, its text the CHANGELOG's 0.11.4 entry.
- **Packagist:** `v0.11.4` from `70d683e`, 2026-11-22T10:13:46+00:00.

## Checked

A fresh project, `composer clear-cache`, then
`composer require 'provemark/c2pa-verifier:^0.11'`:

- it resolves to `v0.11.4` at `70d683e`;
- 692 files, step 551's package count plus its own note;
- `Verifier::VERSION` is `0.11.4`;
- `tsa-profile/tsa-leaf-ca-true` is `Trusted` under its own settings,
  where 0.11.3 said `Invalid`;
- `tsa-profile/expired-signer-tsa-leaf-ca-true` stays `Invalid`.

## Not done

- **No advisory.** No verdict becomes wrong. The verdicts that move were
  stricter than `c2patool` 0.29.0 and the specification.
- **Downstream:** nothing has to change. A caller on `^0.11` receives it.
  The library, the Drupal module and the WordPress plugin take or bundle
  it in their own sessions, on Maurice's word.
