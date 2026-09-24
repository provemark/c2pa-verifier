# Step 126 — SPEC-034: icon references

*2026-09-24. SPEC-034 approved the same day. On open question 2 the
maintainer took the proposal (refuse an icon that names a data box). When
§10.2.3.2's *should* for data boxes came to light, he kept that choice
(option A); questions 1, 3 and 4 adopted their proposals.*

## 126a — the probes, and the tests seen red

`bin/make-spec034-variants.php <c2patool-0.28.0> <c2patool-0.27.22>`
builds **nine PNG probes** under a throwaway root, intermediate and leaf.

**Five come from `c2patool` 0.28.0's builder:**
- an icon in `claim_generator_info`, `softwareAgents`, an action's
  `softwareAgent`, and `templates`;
- a `claim_generator_info` icon pointing to `https://example.com/icon.png`.

**Four are made from those by a same-length patch in the `caBX` chunk and
a new signature from the same leaf:**
- the `claim_generator_info` icon's hash with one bit flipped;
- its url turned from `c2pa.icon` into `c2pa.icoX`;
- the icon hash inside the actions assertion flipped (for `templates` and
  for an action's `softwareAgent`), with the claim's hashed URI for that
  assertion recomputed.

For each patched probe:
- no box changes length;
- the chunk CRC is recomputed;
- the new signature is checked under its own leaf before the file is
  written.

Both `c2patool` versions then judge every probe like any other file. The
keys are shredded, and a surviving key is an error.

What both oracles said, with the root as anchor:

| probe | 0.27.22 | 0.28.0 | the fault 0.28.0 names |
|---|---|---|---|
| `generator-icon`, `templates-icon`, `action-agent-icon`, `agents-icon` | `Trusted` | `Trusted` | — |
| `generator-icon-hash-changed` | `Invalid` | `Invalid` | `assertion.hashedURI.mismatch` on `self#jumbf=c2pa.assertions/c2pa.icon` |
| `templates-icon-hash-changed`, `action-agent-icon-hash-changed` | `Invalid` | `Invalid` | the same |
| `generator-icon-unresolved` | `Invalid` | `Invalid` | `assertion.missing` on `…/c2pa.icoX`, plus *"Failed to load manifest"* |
| `generator-icon-external` | `Invalid` | `Invalid` | `assertion.missing` on the https url, plus *"Failed to load manifest"* |

Three findings before any test, recorded as SPEC-034 amendments 1 and 2:

- **An external icon is `assertion.missing`**, as `c2pa-rs` reports it and
  as §10.2.3.2 requires (*"shall be to an embedded data assertion whose
  label is c2pa.icon"*). The draft had left it unchecked.
- **The builder leaves a `softwareAgents` icon a resource reference.** No
  `c2pa.icon` is embedded for it, and it has no hash to break. The oracles
  do not check it, and neither will this verifier.
- The second *"Failed to load manifest"* is how `c2pa-rs` aborts, not a
  rule, so the tests compare the faults on icon urls.

`tests/Unit/Manifest/IconReferenceTest.php`, run as
`vendor/bin/pest --group=SPEC-034`: **5 failed, 1 passed.** Every failure
is the check not existing yet: no icon fault, and no `icons` in
`checks_performed`. AC6 is a guard (OpenAI's verdict stays `Valid`).

Committed locally, not pushed.
