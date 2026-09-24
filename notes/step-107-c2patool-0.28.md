# Step 107 — c2patool 0.28.0 and the shared settings file

*2026-09-24. Measurement only: no specification, test or code changed.*

## Why this was measured

One of this project's fixed design rules is that **one trust settings file
serves `c2patool`, the sister library's signing service and this
verifier**: `verify.verify_trust`, `trust.trust_anchors`,
`trust.trust_config` and `trust.allowed_list`, each holding file contents
as a string. Every verdict comparison so far pinned `c2patool` 0.27.22
(`c2pa` 0.90.22).

While issue #9 was being measured, the `c2pa-rs` source showed that
**`c2pa` 0.91.0 (released 2026-09-21) deprecates `trust.trust_anchors`**
and `trust.user_anchors` in favour of a list, `trust.anchors`. The
deprecation note says: *"Will be removed in 0.92.0 (scheduled for
mid-November 2026)."* `c2patool` 0.28.0 followed on 2026-09-22. This step
measures what that release does with the settings files this repository
already has.

## What was run

- `c2patool` 0.28.0, `universal-apple-darwin` release asset. Its SBOM
  names `pkg:cargo/c2pa@0.91.0`.
- `c2patool` 0.27.22, the version pinned until now.
- This verifier, `bin/c2pa-verify`, at `01049f6`.
- Two files, `tests/Fixtures/fixture-signed.jpg` and `fixture-signed.mp4`,
  each without settings and with each of the 15
  `tests/Fixtures/trust/*.settings.json`: 32 runs per tool. For each run
  the comparison took `validation_state` and the set of status codes from
  `validation_status` and `validation_results.activeManifest`.

## Measured

### 1. `trust.trust_anchors` still works in 0.28.0

Every anchor-based verdict is the same in 0.27.22 and 0.28.0: Trusted where
it was Trusted, Valid where it was Valid. That holds for `full`,
`ec-root-only`, `intermediate-anchor`, `anchors-no-config`,
`anchors-wrong-eku` and the rest. Nothing on stderr warns about the
deprecated field. The reason is in `c2pa` 0.91.0's `settings/mod.rs`:
`merge_legacy_trust_anchors()` wraps a legacy `trust_anchors` string into
a `TrustAnchor` with `trust_uri: "system_anchors"`, so it keeps working
until 0.92.0 removes it.

### 2. `trust.allowed_list` is silently ignored in 0.28.0

This is a verdict change. It happens on both files:

| settings | 0.27.22 | 0.28.0 | this verifier |
|---|---|---|---|
| `allowed-only` | Trusted | **Valid** + `signingCredential.untrusted` | Trusted |
| `allowed-plus-wrong-root` | Trusted | **Valid** + `signingCredential.untrusted` | Trusted |

The reason: in 0.91.0 the `Trust` struct no longer has an `allowed_list`
field. It moved inside each `TrustAnchor` entry of the new `trust.anchors`
list, and the struct does not deny unknown fields, so a top-level
`trust.allowed_list` is dropped without an error or a warning. The new
field's own documentation adds: *"When used for C2PA this will not be C2PA
trust list recognized or acknowledged certificates and should only be used
for non-C2PA conformant cases."*

So **the same settings file now gives two different verdicts** depending on
the `c2patool` version, and this verifier agrees with the old one. The
difference runs in the fail-closed direction for `c2patool`: it trusts
less. From the point of view of the newest oracle, this verifier is now the
more lenient of the two.

### 3. Codes that changed but are not new to this project

- `signingCredential.ocsp.skipped` appears in 0.28.0 on every run and is
  absent from every 0.27.22 run. This verifier has emitted it on every file
  since SPEC-030, so on this code the two now agree.
- `assertion.bmffHash.additionalExclusionsPresent` appears in 0.28.0 on
  every `fixture-signed.mp4` run. This verifier has no such code. It has the
  data-hash counterpart, `assertion.dataHash.additionalExclusionsPresent`.
- `claimSignature.insideValidity` appears in both `c2patool` versions and
  not here. That is the known, pre-existing difference (steps 35 and 40).
- `unknown-key.settings.json`: this verifier refuses it (exit 2, no
  report), and both `c2patool` versions ignore the key. This is a departure
  by design (SPEC-019).

## What this means

- The shared-file rule still holds for anchors, until 0.92.0.
- It **no longer holds for `allowed_list`** against `c2patool` 0.28.0.
- When 0.92.0 lands, a file with only `trust.trust_anchors` will lose its
  anchors in `c2patool`. Judging by how `allowed_list` behaves here, that
  will probably also happen silently. That is reasoned, not measured.
- `docs/comparison.md`, `README.md` and `docs/conformance.md` still name
  0.27.22 as the oracle. They are true as written, because they say which
  version they measured against, but they now describe an oracle that is
  no longer current.

Deciding what to do is the maintainer's call and is not part of this step.
