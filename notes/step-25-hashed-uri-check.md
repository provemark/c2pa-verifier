# Step 25 — `HashedUriCheck`: the first half of M4, ten tests red → green

*2026-09-21.* SPEC-011 implemented. After this step the verifier knows
not only that the claim was signed (M3) but that every assertion the
claim names is the one the signer saw: the hash in the claim equals the
hash of the box. An edited assertion under an intact signature — the
attack M3 alone cannot see — is now `assertion.hashedURI.mismatch`.

## What was built

`src/Hash/HashedUriCheck.php`, 116 lines, the first file in the `Hash`
layer:

- `check(Manifest): list<ValidationStatus>` — every entry of
  `created_assertions` then `gathered_assertions` (v1: `assertions`), in
  claim order, through `entry()`; then every child of the assertion
  store that no entry resolved to (`Superbox` → `assertion.undeclared`
  with its URI; `UnknownBox` → the same code with the store's URI and
  the box's type, UUID, label and offset); then a non-empty
  `redacted_assertions` → `general.error` on the claim box.
- `checkEntry(Manifest, HashedUri): ValidationStatus` — the seam AC10
  needs, the counterpart of `ClaimSignatureCheck::checkBytes()`.
- `entry()` (private) does the work once and hands back the box as
  well, so that `check()` knows which boxes are spoken for without
  resolving twice: resolve (a `ManifestException` becomes its own
  status); `alg` from the entry, else the claim, else
  `algorithm.unsupported`; not on §13.1's list → `algorithm.unsupported`;
  a hash whose length is not the algorithm's digest length →
  `.mismatch` naming both lengths; else `hash($alg, payload(), true)`
  against the claim's bytes with `hash_equals()`.

"Undeclared" is decided by **identity**, not label: `in_array($child,
$resolved, true)`. That is what makes the duplicate-label variant
(`assertion-duplicate-label`, a second `c2pa.actions.v2`) report the
second box, which the claim's entry never reached.

Three small changes around it: `StatusCode` grew by the three codes
(`isSuccess()` now true for two); `Manifest::$assertionStore` is public
(SPEC-007 amendment 2); `deptrac.yaml` lets `Hash` see `Jumbf`
(`Superbox::payload()`, `UnknownBox`). SPEC-010's AC10 test asserted the
enum's exact twelve values; it now asserts that those twelve are present
and leaves the exact fifteen to SPEC-011 AC9 — recorded as SPEC-010
amendment 1.

## Measured

- Before: `vendor/bin/pest --group=SPEC-011` → `10 failed (8
  assertions)` — step 24b.
- After: `composer check` → Pint passed, PHPStan `[OK] No errors`,
  Deptrac `Violations 0, Allowed 147`, Pest **`163 passed (1101
  assertions)`**: the 153 of M3 and step 23, plus SPEC-011's ten, on the
  first run of the implementation.
- What the ten tests compare with c2patool 0.27.22's recorded JSON
  (steps 14, 23, 24): the code/url pairs of every `assertion.hashedURI.match`
  on the four fixtures (3+3+3+4); the `assertion.hashedURI.mismatch`
  pair on `exclusions-overlap`, `hashed-uri-changed` and both pairs on
  `hashed-uris-two-changed`. The urls are c2patool's absolute form,
  `self#jumbf=/c2pa/<label>/c2pa.assertions/<name>`, whatever relative
  form the claim's own entry used.
- Kept divergences, all measured in steps 23 and 24, all failures on
  both sides: `assertion.undeclared` where c2patool exits with `Error:
  assertion missing` (undeclared, duplicate, unknown UUID);
  `algorithm.unsupported` per entry where c2patool says `.mismatch`
  (claim `alg` `sha1`) or exits (`unknown algorithm`); `general.error`
  for a claim with `redacted_assertions` where c2patool checks one rule
  (no actions) and misses a "redacted" box that is still present.

## Reasoned, not measured

- Constant-time comparison (`hash_equals`) is habit, not a threat model:
  nothing here is secret. It costs nothing and removes a question.
- The explanation of a mismatch prints both digests in hex. That is the
  added value over c2patool's terse line, and it is our own text, not a
  second vocabulary: the code is the word.

## Next

SPEC-012, the second half of M4: exactly one `c2pa.hash.data`, its shape
checked, the streaming hash of the asset with the exclusions skipped,
the store exclusion checked to be the store plus the format's framing
(step 23 measured 32/12/8 bytes for JPEG/PNG/WebP). M4's "done when" —
one changed pixel byte → `assertion.dataHash.mismatch`, the untouched
file `Valid` — is measurable only when both halves are in.
