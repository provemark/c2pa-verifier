# Step 424 — 0.8.2 released

*2026-10-25.* Maurice: "akkoord, push en tag v0.8.2".

- `main` pushed (`51f380b..3ee8e69`); CI 8/8 green on it.
- Tag `v0.8.2` (annotated) on `3ee8e69`; CI 8/8 green on the tag.
- Packagist lists `v0.8.2` at `3ee8e69`.
- A fresh `composer require provemark/c2pa-verifier:^0.8.2` in an empty
  directory installed `v0.8.2`; `Verifier::VERSION` is `0.8.2`. With
  their settings, `ecdsa-pairing/es256-p384.png` and
  `profile-ed25519/leaf-signature-params.png` are `Invalid`, and
  `ecdsa-pairing/es256-p256.png` and `profile-ed25519/control.png` are
  `Trusted`. `fixture-signed.jpg` is `Valid` without settings.
- No GitHub release, as before. No advisory: `SECURITY.md` lists the two
  signatures as not counted among the wrong verdicts (step 423).

Downstream, each on Maurice's word, the same day:

- **Library** `provemark/content-credentials` 0.19.5: the verifier floor
  at 0.8.2 (`require-dev` `^0.8.2`, `conflict` `<0.8.2 || >=0.9`, its
  SPEC-042 Amendment 9); a fresh install resolves verifier 0.8.2, and
  0.8.1 beside it is refused by the conflict.
- **Drupal module** `content_credentials`: no requirement change (`^0.8`
  admits 0.8.2); a Changed entry in its CHANGELOG, drupal.org issue
  3633006, fixed.
- **WordPress plugin** 0.7.2 bundles 0.8.2 (WordPress.org r3764366): over
  the verifier's 598 JPEG, PNG and WebP fixtures, only the six files new
  since 0.8.1 move in the plugin's outcome.
- **Demo** at provemark.github.io/demo/ on 0.8.2; the five samples under
  the three trust choices are unchanged, 15 of 15 checked live.
