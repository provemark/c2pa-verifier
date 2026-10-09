# Step 330 — Appendix C row by row, and the urn:uuid label (C.1, SPEC-007 amendment 8)

*2026-10-09.*

The reading of step 308 could not check Appendix C construct by construct:
the text it used had lost the table's version columns. This step reads
the table from the specification's HTML: 52 constructs, each marked per
version (1.3 to 2.4) as supported, DEPRECATED or UNDEFINED. C.1 sets the
rules. A deprecated construct is one *"validators are encouraged to
accept"*. An undefined one validators *"are required to ignore"*. A
supported one they must accept.

## What the table showed

- **Deprecated constructs are accepted here.** That covers `sigTst`, claim
  and actions version 1, ingredient versions 1 and 2,
  `c2pa.hash.bmff.v2`, the old field names (`role`, `actors`, `changed`,
  `instanceID`) and action names (`c2pa.color_adjustments`,
  `c2pa.watermarked`), `specVersion` and the data boxes. There is one
  exception, below.
- **What 2.4 marks undefined is ignored or refused with the same verdict.**
  A Time-Stamp manifest box is skipped as unknown. `c2pa.hash.bmff` without
  a version is `general.error`; ignoring it would give
  `claim.hardBindings.missing`, `Invalid` either way. Box hashes for TIFF
  fail closed.
- **Constructs undefined for version 1 claims** (`sigTst2`,
  `c2pa.hash.bmff.v3` and the like in a version 1 claim) are read here, not
  ignored, as `c2pa-rs` reads them. This is by design: no such file has
  been seen.

## The exception: urn:uuid labels in version 2

Step 318 (SPEC-007 amendment 7) refused a version 2 label that is not a
C2PA URN, from §8.1 alone. Appendix C marks the `urn:uuid` namespace
deprecated from 2.1 on, not undefined, and `c2pa-rs` accepts it on a
version 2 claim. The new probe `manifest-probes/label-urn-uuid.png` is
the label of `control.png` with `urn:c2pa:` made `urn:uuid:` (the same
length), re-signed. It is `Trusted` in both `c2patool` versions and was
`Invalid` here. A version 2 label may now also be `urn:uuid:<UUID>`,
optionally after a claim generator's prefix. Anything else stays
`claim.malformed` (AC18, `label-not-urn`).

While rebuilding, the probe builder hit step 324's own rule: reading
`x5chain-unprotected-too` back through `CoseSign1` now throws. The builder
builds the Sig_structure itself (RFC 9052 §4.4). Every other probe gave
the same answers as before.

## Measured

- **Tests first.** AC20 in `tests/Unit/Manifest/ManifestProbesTest.php`:
  red, then green.
- **The corpus.** 330 runs moved. `label-urn-uuid` went from `Invalid` to
  `Valid`, or `Trusted` under its own settings. `label-not-urn` stays
  `Invalid`, with only its reason reworded. No real file moved.
- **The fuzzer.** 0 faults. With `--trust`, 534 and 1,765 suspects, each
  judged by `c2patool` 0.28.1, none more lenient here.
- `composer check`: 973 passed.

The reading document now has 298 covered, 63 partial, 78 by design, 101
n/a and 21 candidates.
