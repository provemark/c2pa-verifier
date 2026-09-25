# The update-manifest variants (step 57, SPEC-022)

Five variants of `../c2pa-rs/update_manifest.jpg` — the one corpus file
with a `c2um` update manifest — and one of the PNG fixture.
`bin/make-update-manifest-variants.php <scratch>` builds them; every one
but `pixel-changed` re-signs the claim it edits with a throw-away P-256
hierarchy (keys outside the repository, deleted at the end of the run;
the public root in `throw-away-root.pem` and, together with the C2PA test
anchors, in `throw-away-root.settings.json` — the *parent* manifest keeps
its own Adobe signature, and only the fault under test may make a variant
`Invalid`). Regenerating changes the root and the signatures, so the
c2patool JSON under `../c2patool/update-manifest/` is regenerated with
them. The JPEG's single APP11 segment is rebuilt around the edited store;
the script asserts a byte-exact round trip on the unchanged store first.

| variant | what it is | c2patool 0.27.22 (with the settings) |
|---|---|---|
| `pixel-changed` | one byte of the JPEG **outside** the manifest store flipped; nothing re-signed | `Invalid`: `assertion.dataHash.mismatch` — the adjusted exclusion covers the store and no more |
| `action-not-allowed` | the update manifest's action `c2pa.opened` → `c2pa.edited` (same length) | `Invalid`: `manifest.update.invalid` |
| `hash-in-update` | its `c2pa.time-stamp` assertion renamed `c2pa.hash.data` | **exit 1**, no JSON: `Error: assertion missing: url = c2pa.hash.data` |
| `ingredient-inputto` | its ingredient's relationship `parentOf` → `inputTo` | **exit 1**: `Error: claim missing hard binding` — without a parent the binding chain ends |
| `no-standard-parent` | the *parent* manifest's box UUID `c2ma` → `c2um` (and the reference hashes recomputed), so the chain never reaches a standard manifest | **exit 1**: `Error: claim missing hard binding` |
| `two-parents` | the PNG fixture with two `parentOf` ingredient assertions | `Invalid`: `manifest.multipleParents` — where this verifier says `Trusted` before SPEC-022 |

Two more, built by `bin/make-standard-binding-variants.php <dir>` (step
150, SPEC-022 amendment 6). Nothing is signed: only the active manifest's
box UUID changes, which lies outside the claim.

| variant | what it is | c2patool 0.27.22 and 0.28.0 (with the settings) |
|---|---|---|
| `standard-no-binding` | the active manifest's box UUID `c2um` → `c2ma`: a standard manifest with no hard binding, and no update manifest left in the store | `Invalid`: `assertion.dataHash.mismatch` on the parent's hash — the binding is found up `parentOf`, its exclusion not adjusted |
| `standard-borrows-with-update` | the same, with an unreferenced copy of the original update manifest (label changed in one character) before it; the store spans several APP11 segments | `Invalid`: the same — where this verifier said `Valid` before amendment 6 |
