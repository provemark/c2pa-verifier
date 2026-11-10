# Step 522 — An icon that is not a hashed URI is `hashedURI.missing`

*2026-11-10.* Step 521 found one corpus file on which this verifier had
become more lenient than `c2patool`: `icons/agents-icon.png`, `Valid`
here, `Invalid` in 0.28.6. Maurice approved SPEC-034 amendment 3 with the
new code `hashedURI.missing` ("akkoord, amendement approved met
hashedURI.missing, bouw 522"), and amendment 4 after the probes were
measured ("akkoord, amendement 4 approved, schrijf de tests").

## What it is

C2PA 2.4 §10.2.3.2: the value of an icon *"shall be a hashed URI"*. An
icon may sit in four places: `claim_generator_info`, an actions
assertion's `softwareAgents` and `templates`, and an action's
`softwareAgent`. SPEC-034 amendment 2 (step 126a) let an icon map without
a `url` through unchecked, because `c2pa` 0.91.0 did. `c2pa` 0.91.11
(CAI-13410, upstream #3003) reports such an icon as `hashedURI.missing`
on the claim or the actions assertion that holds it. An icon that is a
resource reference binds nothing the signature covers; a reader that shows
it shows something unsigned.

## Measured

- **The probes** (`bin/make-icon-form-variants.php`): three controls signed
  by `c2patool` 0.28.6 and five probes, each one icon replaced by CBOR of
  the same length and the claim signed again with the throw-away leaf.
  The answers of 0.28.6 and 0.28.3 are in
  `tests/Fixtures/icon-forms/README.md`. In short:
  - the three resource references are `Invalid` in 0.28.6 with
    `hashedURI.missing` on `…/c2pa.claim.v2` or
    `…/c2pa.assertions/c2pa.actions.v2`, and `Trusted` in 0.28.3;
  - a hashed URI with `format` and `identifier` added is `Trusted` in
    both. Amendment 3 had read it as a resource reference. That reading
    missed a type: `ResourceRef`'s `hash` is a text, so a map with a
    byte-string hash decodes as a hashed URI (amendment 4);
  - an icon that is a text: neither version can decode the claim
    (exit 1, no report).
- **Before the change** this verifier said `Trusted` for all eight.
- **Red, then green.** The new tests (AC4 changed, AC7 new) and the
  contract test were red before the source change: six failures in the
  SPEC-034 group, and `ApiSurfaceTest` (211 symbols where the recorded
  surface had 212). After the change all 15 SPEC-034 tests pass. The
  fixtures were then built again by the final script, the source change
  taken out once more (six red), and put back (green).
  `CertificateProfileCheckTest` AC10, which counts every status code,
  went red only after the enum case was added; it is a count and was
  moved from 71 to 72.
- **The corpus** (`corpus-norm241.php`, before in a worktree on `de5f05f`
  with `vendor/` copied, after on this tree; 2,214 runs each, no settings
  and `trust/full.settings.json`): ten runs differ, the five files below,
  each under both settings. `icons/agents-icon.png` and the four failing
  probes of `icon-forms/`. Nothing else.
- `composer check` (1,718 passed, 1 skipped), `vendor/bin/pest --parallel`,
  `bin/package-check.php` (the new script is export-ignored with the
  other `bin/make-*.php`).

## What it means (reasoned)

- The verdict now equals `c2patool` 0.28.6 on every icon probe it can
  read, in code and url.
- One code difference is named: an icon that is a text. `c2patool` gives
  no report at all; here it is `hashedURI.missing` on the claim and
  `Invalid`, with the rest of the report kept (amendment 4).
- One difference stays reasoned and unprobed: a map with a text `url`, a
  `format` and an `identifier` but no `hash` is a resource reference in
  `c2pa-rs` (`hashedURI.missing`) and a hashed URI without a hash here
  (`assertion.hashedURI.mismatch`). Both are `Invalid`.
- A v1 claim's `claim_generator_info` icon is reported on `…/c2pa.claim`,
  built the way `HashedUriCheck` builds that url; no v1 probe exists
  (SPEC-034 open question 4).
- The contract gains `StatusCode::HashedUriMissing`, so the release that
  carries this is a minor. It waits on `main` under *Unreleased*.

## What changed

- `src/Report/StatusCode.php`: `HashedUriMissing`.
- `src/Manifest/IconReferenceCheck.php`: each icon carries the url of
  what holds it; an icon that is not a map with a text `url` is
  `hashedURI.missing` there, its explanation naming the form, never its
  contents.
- `specs/SPEC-034-icon-references.md`: amendments 3 and 4, Traceability.
- `tests/Unit/Manifest/IconReferenceTest.php` (AC4, AC7),
  `tests/Unit/ApiSurfaceTest.php`, `tests/Fixtures/api/public-surface.txt`,
  `tests/Unit/Trust/CertificateProfileCheckTest.php` (the count).
- `bin/make-icon-form-variants.php`, `tests/Fixtures/icon-forms/`,
  `tests/Fixtures/c2patool/icon-forms/`.
- `CHANGELOG.md` (Unreleased), `docs/comparison.md`,
  `docs/conformance.md`, `docs/reading-c2pa-2.4.md`.
