# Step 150 — A standard manifest is not adjusted as an update manifest (SPEC-022 amendment 6)

*2026-09-25. Found by the security review of the same day. A wrong
`Valid` or `Trusted`, present in 0.1.0, 0.2.0 and 0.2.1.*

## The flaw

An update manifest has no hard binding of its own. It borrows the one
of the first standard manifest up its `parentOf` chain (C2PA 2.4
§15.12). That parent's exclusion was written when the store was
shorter, so §15.12.1.1 says to treat it as the store's current range.

This verifier decided both things per store instead of per manifest.
When the store held any update manifest, even one nothing referenced,
the active manifest's binding was looked up up `parentOf` and its
exclusion was adjusted. A standard active manifest without a binding of
its own then borrowed its parent's binding, the stale exclusion was
widened to the grown store, the hash matched, and the file was `Valid`.

## 150a — probes, oracles, tests seen red

`bin/make-standard-binding-variants.php` builds two variants of
`c2pa-rs/update_manifest.jpg`. Nothing is signed: only the active
manifest's box UUID changes from `c2um` to `c2ma`, and that UUID lies
outside the claim.

| variant | this verifier before | c2patool 0.27.22 and 0.28.0 |
|---|---|---|
| `standard-no-binding` | `Invalid`, `claim.hardBindings.missing` | `Invalid`, `assertion.dataHash.mismatch` on the parent's hash |
| `standard-borrows-with-update` (plus an unreferenced copy of the update manifest) | **`Valid`** | `Invalid`, `assertion.dataHash.mismatch` on the parent's hash |

The measurement shows that `c2pa-rs` also follows `parentOf` for a
standard manifest without a binding, with or without an update manifest
in the store, but does not adjust that exclusion. It then no longer
covers the store and the hash fails.

Two ways to close the hole were weighed:

- the literal §10.2.2, where a standard manifest never borrows
  (`claim.hardBindings.missing`);
- `c2pa-rs`'s reading.

Both give `Invalid`. Under ADR-0005 this verifier follows `c2pa-rs`,
because the stricter reading would change a code and protect nothing
more. Decided by Maurice van Loon.

`tests/Unit/Verifier/UpdateManifestTest.php`, AC10, red on both probes:

- `standard-no-binding` on the code: `claim.hardBindings.missing`
  where `c2patool` says `assertion.dataHash.mismatch`;
- `standard-borrows-with-update` on the state: `Valid` where `Invalid`
  was expected.

## 150b — built

`Verifier::bindingOf()`:

- the active manifest answers for itself when it is a standard manifest
  with a hard-binding assertion of any kind (`hasOwnHardBinding()`, so a
  BMFF or unsupported binding is never replaced by a parent's);
- otherwise the binding is looked up up `parentOf`. A standard manifest
  with nothing up the chain still answers for itself, so its missing
  binding is reported exactly as before.
- `DataHashCheck`'s §15.12.1.1 adjustment now runs only when the
  **active** manifest is an update manifest. The cover rule (SPEC-012
  amendments 5 and 7) makes the unadjusted exclusion fail closed.
- Step 149's guard in `drop()` gets the borrowed manifest's label
  whenever the binding is borrowed.

Measured:

- `vendor/bin/pest --group=SPEC-022`: 10 passed.
- `composer check`: exit 0, 513 passed.
- **Every signed fixture under no settings and under every settings file,
  18,950 runs, before and after: 100 moved.** They are exactly the two
  probes under their 50 settings. `standard-borrows-with-update` goes from
  `Valid`/`Trusted` to `Invalid`, and `standard-no-binding` goes from
  `claim.hardBindings.missing` to `c2patool`'s code. No other file
  changed state or status list.

## Disclosure

The fix is local until the other findings of the review are fixed too.
They go out together as a security release. Pushing a probe or a test
before then would publish the flaw.
