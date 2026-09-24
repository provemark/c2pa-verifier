# Step 137 — SPEC-040: `assertion.outsideManifest`

*2026-09-24. SPEC-040 approved the same day; both open questions adopted
their proposals.*

## 137a — the fixtures, and the tests seen red

`bin/make-spec040-variants.php <c2patool-0.28.0> <c2patool-0.27.22>` is
step 136's probe, made permanent. It takes the signed PNG fixture and
rewrites its actions entry in `created_assertions`, in three ways:
- absolute, naming its own manifest;
- naming a manifest that is not in the store;
- with no manifest label.

The claim is re-signed under a throwaway root, and the COSE is padded, so
the store keeps its length and the data hash needs no rebinding. The keys
are shredded. Both versions answered as in step 136.

`tests/Unit/Manifest/OutsideManifestTest.php`, run as
`vendor/bin/pest --group=SPEC-040`: **3 failed, 3 passed.**
- AC1 fails because this verifier says `assertion.missing` on the store
  where both `c2patool` versions say `assertion.outsideManifest` on the
  entry.
- AC4 fails the same way. It rewrites `c2pa-rs/CACA.jpg`'s active claim in
  memory to name the ingredient manifest's label, and the reader's code is
  `assertion.missing`.
- AC6 fails because the case does not exist.
- AC2 (own label, absolute: `Trusted`), AC3 (no label: `assertion.missing`)
  and AC5 pin behaviour that must not change. They are green before and
  after.

`composer check` is otherwise clean: 495 passed.

Committed locally, not pushed.
