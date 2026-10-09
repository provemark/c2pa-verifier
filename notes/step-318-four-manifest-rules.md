# Step 318 — Four manifest rules (fix F3, SPEC-007 amendment 7)

*2026-10-09.*

The reading of C2PA 2.4 left four candidates about the manifest itself
(L9, L10, L11, L13). Step 315 measured them with scratch tools. This step
makes the probes fixtures and fixes all four.

## The probes as fixtures

`bin/make-manifest-probe-variants.php` (new) has `c2patool` 0.28.1 sign
`fixture-unsigned.png` with a throw-away P-256 hierarchy, once alone and
once with that file as its parent. A probe `c2patool` will not write is
made by editing the store. The bytes are replaced and every enclosing box
resized. The data hash's exclusion is re-lengthened to the new chunk, its
hashed URI recomputed, and the claim signed again with the same
throw-away leaf. `cgi-shorter.png` runs that edit on a change that keeps
the file valid; it stays `Trusted` everywhere, so the edit itself breaks
nothing.

| probe | `c2patool` 0.28.1 | 0.27.22 | here before | here after |
|---|---|---|---|---|
| `type-c2md` (L9) | `Trusted` | `Trusted` | **`Invalid`** | `Trusted` |
| `duplicate-label-last`, `[X, Y, X']` (L10) | `Invalid` | `Invalid` | **`Trusted`** | `Invalid` |
| `duplicate-label-middle`, `[X, X', Y]` | `Trusted` | `Trusted` | `Trusted` | `Invalid` (stricter on purpose) |
| `label-not-urn` (L11) | `Invalid` | `Invalid` | **`Trusted`** | `Invalid` |
| `cgi-empty` (L13) | error | error | **`Trusted`** | `Invalid` |
| `x5chain-unprotected-too` (C1) | `Trusted` | `Trusted` | `Trusted` | `Trusted` |

The label probe renames the claim's own reference to its signature as
well. Otherwise it would also test a dangling reference.

## What changed

- **`JumbfParser::UUID_MANIFEST_C2MD`**, read by `ManifestStore` as a
  manifest (§11.2.2: consumers shall accept it).
- **`ManifestStore::fromTree()`** refuses a second manifest with a label
  already seen: `claim.malformed`. §8.1 makes a label identify one
  manifest. `c2patool` takes the last box as active. In `[X, X', Y]` that
  is `Y`, and it says `Trusted`. Here neither shape is guessed at, because
  which manifest an ingredient's URI means is not known.
- **`Manifest::read()`** checks a version 2 manifest's label against
  §8.1's ABNF (`urn:c2pa:`, a UUID, an optional generator of up to 32
  visible ASCII characters, an optional `<version>_<reason>`), as
  `c2pa-rs` does ("claim box label invalid"). Version 1 labels are not
  checked, as in `c2pa-rs`.
- **`Claim::fromMap()`** reads an empty `claim_generator_info` in version 2
  as the map it must be, and refuses it for want of a `name`. Version 1
  keeps amendment 4's empty list.

C1, label 33 in both headers, is left as measured: §14.5 calls it
malformed, while `c2patool` and this verifier both accept it. It goes to
Maurice with C4, C6 and C7.

## Measured

- **Tests first.** `tests/Unit/Manifest/ManifestProbesTest.php`: 4 failed
  and 1 passed (the control), then 5 passed.
- **The corpus.** 860 files under no settings and 160 settings files,
  before and after: 805 runs moved, all of them the five probes. No real
  file moved; no real version 2 label fails the URN grammar.
- **The fuzzer.** Seed 20261005 × 60 without settings: 0 faults, the same
  238 suspects. With `--trust` (245 pairs): 20261005 × 60 gave 534
  suspects and 20261009 × 200 gave 1,765. Each was judged by `c2patool`
  0.28.1 under the same settings, and none is more lenient here.
- `composer check`: 962 passed. PHPStan in Docker `php:8.3-cli`: no
  errors.
