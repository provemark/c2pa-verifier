# Step 47 — The wrong `Valid`: a signed manifest without a hard binding, shown, then refused

*2026-09-22.* Step 46 asked what the verifier does with a manifest that
carries no `c2pa.hash.data` at all, and found by reading the Verifier
that the data-hash check ran "only if `hash.data`'s hashed URI matched"
— so with no `hash.data` it never ran, and the one code that refuses such
a manifest, `claim.hardBindings.missing`, was never said. This step
builds the file that proves it, sees the test red on the verdict itself,
and closes the hole (SPEC-013 amendment 10).

## Why a hard binding matters (for the reader)

A manifest says what the signer claimed — the actions, the ingredients,
the thumbnail — and the signature proves the *manifest* is what the
signer wrote. Nothing in that ties the manifest to the *pixels* next to
it. The hard binding does: `c2pa.hash.data` holds a hash over the
asset's bytes (everything but the manifest store and the exclusions the
assertion names), and only when that hash matches does "this manifest
belongs to this image" hold. A manifest without one can be copied onto
any image and still verify: the signature is genuine, every assertion
hashes, and the file it sits in is anybody's. That is why C2PA 2.4
§15.10.1.2 makes a standard manifest without a hard binding invalid,
and why c2pa-rs refuses it outright.

## The variant (measured)

`bin/make-no-hard-binding-variant.php <scratch>`: the PNG fixture's
store with the `c2pa.hash.data` assertion box removed, the claim's
`created_assertions` rewritten to hold the actions assertion (a v2
claim must create at least one) and `gathered_assertions` the
thumbnail, and the claim re-signed with a throw-away P-256 hierarchy in
the profile step's manner (keys outside the repository, deleted at the
end; the public root into `no-hard-binding-root.pem` and a settings
file). The script checks its own work: the store parses, the claim names
exactly the two assertions, no `c2pa.hash.data` remains, and the new
signature verifies under its leaf through `SignatureVerifier`. 45 743
bytes; a new hierarchy per run.

| | c2patool 0.27.22 | this verifier, before |
|---|---|---|
| no settings | `Error: claim missing hard binding`, exit 1, no report | **`Valid`** — `claimSignature.validated`, `signingCredential.untrusted`, two `assertion.hashedURI.match`; `checks_performed` ends at `hashedUris` |
| its root as anchor | the same refusal | **`Trusted`** |

`Trusted` on a manifest that binds to nothing. The brief's first risk,
in one file.

## Red, then the fix

SPEC-013 AC15 (`VerifierTest`): the variant must be `Invalid` with
`claim.hardBindings.missing` on the manifest's url, its signature still
`validated` (that is the point), `checks_performed` ending in
`dataHash`; the same with the root as anchor (`signingCredential.trusted`
*and* `Invalid`); the step-26 `hard-binding-missing` variant must name
the code beside its broken signature; and a `hash.data` whose hashed URI
mismatches must still skip the data hash. First run: red on the first
line — `Valid` where `Invalid` was expected.

The gate in `Verifier::check()` is turned around: the data-hash check
runs **unless** the claim declares a `c2pa.hash.data` whose hashed URI is
`assertion.hashedURI.mismatch` — the one case where reading the asset
would be reading through an assertion the signer did not vouch for, and
the file is already refused. Absent, present, or refused for another
reason (`algorithm.unsupported`), the check runs and `DataHashCheck`
says what SPEC-012 specified: `claim.hardBindings.missing`,
`assertion.multipleHardBindings`, `general.error` for a bmff binding.

Two things the first green run showed:

- `hard-bindings-two` briefly lost its `multipleHardBindings` when the
  first gate counted `assertion.undeclared` on the duplicate box as
  "declared and failed"; narrowed to the mismatch code only.
- `claim-alg-sha1` now runs the data hash (its `hash.data` entry is
  `algorithm.unsupported`, not a mismatch) and reports
  `assertion.dataHash.mismatch` — exactly c2patool's set; it leaves
  `SPEC013_SUBSET_ONLY`, which shrinks to four names.

`composer check` green, 295 tests; a fuzz replay (seed 100, 40 rounds,
3 890 runs, the new variant included) 0 faults, 16 survivors.

## What this says about the method

The three corpora, the fuzzing and the drift alarms all measure against
what writers *do*; a writer that omits the binding does not exist,
so nothing measured could show this. The hole was found by asking a
question of the code ("what if there is none?") while reading a
manifest that carried more bindings than usual. The lesson is written
into the corpus policy: for every "the verifier does X when Y matches",
a variant where Y is absent — and the profile step's throw-away signing
is the tool that makes such variants, because a valid signature is what
turns "invalid for the wrong reason" into the real test.
