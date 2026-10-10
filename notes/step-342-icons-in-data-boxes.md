# Step 342 — Icons in data boxes, read and checked (SPEC-067)

*2026-10-10.*

## The probes

`bin/make-databox-variants.php` (new) edits only the active manifest's
`c2pa.databoxes` store of `c2pasign-logo.png`, in place and at the same
length, so the claims, their signatures and the PNG's hard binding stay
valid and each variant differs in one thing:

| variant | edit | `c2patool` 0.28.1 |
|---|---|---|
| `databox-byte-changed` | the box's last byte flipped | `Trusted` |
| `databox-label-renamed` | `c2pa.data` → `c2pa.datb` | `Trusted` |
| `databox-store-renamed` | `c2pa.databoxes` → `c2pa.databoxez` | `Invalid` (`claim.multiple`) |
| `databox-child-not-superbox` | the box's type `jumb` → `jumx` | `Trusted` |

So `c2pa-rs` checks neither the hash nor the presence of the box an icon
names. The trust settings are the C2PA test roots
(`tests/Fixtures/databox/c2pa-test-roots.settings.json`).

## What changed

- **`JumbfParser`** walks the `c2db` store (`UUID_DATABOX_STORE`).
- **`IconReferenceCheck::dataBox()`** (internal) resolves only
  `self#jumbf=/c2pa/<this manifest's label>/c2pa.databoxes/<label>`, as a
  whole url; one store of the `c2db` type and one box under the label, or
  nothing. **`checkDataBox()`** reports `assertion.missing` when it
  resolves nothing and `assertion.hashedURI.mismatch` when the payload does
  not hash to the icon's hash (with the icon's `alg`, or the claim's).
- `docs/comparison.md` and `docs/reading-c2pa-2.4.md` (§5.1, §7,
  §11.1.4.6, C.1, §18.15.6.3): data boxes are read where an icon names one.
- The CHANGELOG gains an *Unreleased* entry.

## Measured

- Tests red first: 8 failed (every data-box url `assertion.missing`, in
  both manifests), then green; 11 tests in `DataBoxIconTest`.
- Mutations watched failing: the store not walked, the hash not checked,
  any manifest's url accepted, the first of two boxes taken, the store's
  UUID not checked, the first of two stores taken. A deeper path accepted
  survived: `JumbfParser` refuses a label holding `/`, so that guard was
  dead and was removed.
- **The corpus before and after**
  (`tools/verifier-scratch/corpus-norm241.php`, no settings and
  `trust/full.settings.json`), in a worktree at `e4a59e4` with today's
  fixtures and a copied `vendor/`: 1,792 runs each; 10 move, all on the
  five data-box fixtures. No other file moves. A first attempt with
  `vendor/` symlinked compared this tree with itself (Composer resolves
  `baseDir` through the link) and showed nothing moving; it is recorded so
  the comparison is not repeated that way.
- `composer check`: 1,019 passed. `bin/api-check.php`: the contract is
  unchanged (135 symbols), so this can be a patch release.
