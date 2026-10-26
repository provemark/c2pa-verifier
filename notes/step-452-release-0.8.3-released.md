# Step 452 — 0.8.3 released

*2026-10-26.* Maurice: "akkoord, push en tag v0.8.3".

- `main` pushed (`fa6ff01..ba9d2ff`); CI green on it.
- Tag `v0.8.3` (annotated) on `ba9d2ff`; CI green on the tag.
- Packagist lists `v0.8.3`.
- A fresh `composer require provemark/c2pa-verifier:^0.8.3` in an empty
  directory installed `v0.8.3`; `Verifier::VERSION` is `0.8.3`. With
  their settings, `mdat-merkle/six-blocks.mp4` is `Trusted`, and
  `mdat-merkle/mdat-whole.mp4`,
  `opened-references/opened-good-unresolvable.jpg` and
  `claim-alg/entries-alg-bmff-no-alg.mp4` are `Invalid`.
  `fixture-signed.jpg` is `Valid` without settings.
- No GitHub release, as before. No advisory: `SECURITY.md` lists the two
  verdicts that followed `c2patool` as not counted among the wrong ones
  (step 451).

Downstream, the same day. Maurice asked that the Drupal module need not
follow every change of the verifier, so 0.8.3 is a patch (step 451) and
two consumers were measured but not changed:

- **Library** `provemark/content-credentials`: no change. It asks for
  `^0.8.2`, which admits 0.8.3; with 0.8.3 installed its `composer check`
  passed (519 passed, 7 skipped). The floor stays at 0.8.2 (Maurice's
  choice).
- **Drupal module** `content_credentials`: no change. It asks for `^0.8`;
  with 0.8.3 installed its unit suite (232), PHPStan and phpcs on its
  tracked files passed. A line for its CHANGELOG waits for the next alpha.
- **WordPress plugin** 0.7.3 bundles 0.8.3 (WordPress.org r3765776, tag
  `v0.7.3`, GitHub release): over the verifier's 623 JPEG, PNG and WebP
  fixtures, only the four files new since 0.8.2 move in the plugin's
  outcome, `Valid` to `Invalid`.
- **Demo** at provemark.github.io/demo/ on 0.8.3; the five samples under
  the three trust choices are unchanged, 15 of 15 checked live.
