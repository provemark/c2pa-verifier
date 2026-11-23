# Step 562 — 0.11.5 released

*2026-11-23.* Maurice: "bereid patch 0.11.5 voor", "commit en push maar"
(step 561), then "tag en release 0.11.5 als de CI groen is".

## Done

- **Commit:** `193a046` (step 561), pushed. CI run 45131818334, 8 of 8
  jobs `success`.
- **Tag:** `v0.11.5`, annotated, on `193a046`, pushed. CI on the tag: run
  45132300696, 8 of 8 `success` on the first attempt.
- **GitHub release:** `v0.11.5`, its text the CHANGELOG's 0.11.5 entry.
- **Packagist:** `v0.11.5` from `193a046`, 2026-11-23T07:32:09+00:00.

## Checked

A fresh project, `composer clear-cache`, then
`composer require 'provemark/c2pa-verifier:^0.11'`:

- it resolves to `v0.11.5` at `193a046`;
- 702 files: step 561's package count plus its own note;
- `Verifier::VERSION` is `0.11.5`;
- `trust/chain-matrix/leaf-expired` under its own settings is `Invalid`,
  with `signingCredential.expired` and `signingCredential.untrusted`
  (step 558);
- `profile/eku-any` under `profile/throw-away-root.settings.json` is
  `Invalid`, with `signingCredential.invalid` and no `trusted` or
  `claimSignature.validated` (steps 556, 557);
- `profile/expired` under the same root is `Invalid`, with `expired` and
  `untrusted` and no `claimSignature.insideValidity` (steps 555, 558).

## Not done

- **No advisory.** No verdict changes.
- **Downstream:** nothing has to change, since a caller on `^0.11`
  receives the release. The library, the Drupal module and the WordPress
  plugin take or bundle it in their own sessions, on Maurice's word.
