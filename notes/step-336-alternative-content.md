# Step 336 — The original preservation image is checked (SPEC-065)

*2026-10-09.*

SPEC-065 was approved with its two proposals. The rules apply in
ingredient manifests too, except the index. A url that names nothing is
`hashMismatch`. This step built the probes and the check.

## The probes

`bin/make-manifest-probe-variants.php` grows by eight `acr-*` probes.
`c2patool` 0.28.1 signs `fixture-unsigned.png` with a
`c2pa.alternative-content-representation` assertion. For the embedded
reference, the url names the manifest's own `c2pa.actions.v2` and the hash
starts as a 32-character placeholder. That placeholder is then made the
32-byte hash the claim records for that assertion, or another one, or its
key is renamed. Both `c2patool` versions call every probe `Trusted`:
`c2pa-rs` 0.91.1 has no code for this assertion. (The builder first missed
the actions hash: `c2patool` puts the actions in `gathered_assertions`.)

## What changed

- **`Hash\AlternativeContentCheck`** (new). It lives in the Hash layer
  because it hashes the named box through `HashedUriCheck::checkEntry()`;
  the Manifest layer may not depend on Hash. It checks the count, then
  the shape, then the index (active manifest only, against the parts of
  its `c2pa.hash.multi-asset`), then the embedded reference's hash.
  `Verifier` calls it for the active manifest, naming the check
  `alternativeContent` only where the assertion is present, and
  `IngredientManifestCheck` for every ingredient manifest.
- **`StatusCode`**: `assertion.alternativeContentRepresentation.malformed`
  and `.hashMismatch` (failures), `.match` (a success). The API surface,
  the two counters and the two lists of success codes grow with them.

## Measured

| probe | `c2patool` 0.28.1 | here before | here after |
|---|---|---|---|
| `acr-embedded-ok` | `Trusted` | `Trusted` | `Trusted`, `match` |
| `acr-embedded-mismatch` | `Trusted` | `Trusted` | `Invalid`, `hashMismatch` |
| `acr-embedded-no-hash`, `acr-both`, `acr-neither`, `acr-index-no-multi-asset`, `acr-two` | `Trusted` | `Trusted` | `Invalid`, `malformed` |
| `acr-generic` | `Trusted` | `Trusted` | `Trusted`, not checked |

- **Tests first.** `tests/Unit/Manifest/AlternativeContentCheckTest.php`: 9
  failed (AC6 and AC7 held already), then 11 passed.
- **The corpus.** 1,400 runs moved, all the `acr-*` probes. No real file
  moved, because none carries the assertion.
- **The fuzzer.** 0 faults. With `--trust`, 534 and 1,765 suspects, each
  judged by `c2patool` 0.28.1, none more lenient here.
- `composer check`: 997 passed.

`docs/reading-c2pa-2.4.md`: four candidates covered; 11 remain, all in
§15.9 (CA revocation, three) and the ISOBMFF details (eight). Issue #5 can
be closed with the release.
