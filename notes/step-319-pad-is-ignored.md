# Step 319 — A data hash's pad is ignored, by design (F4, SPEC-012 amendment 10)

*2026-10-09.*

The reading of C2PA 2.4 listed candidate L2: §18.5.2's schema requires
`"pad": bstr` in a data hash, and this verifier accepts one without it.
The planned fix was to refuse it. Writing the probe turned up the rule
that decides it, in the section on validating a data hash. §15.12.1.1
(and §15.12.2, §15.12.3 for the other hashes): *"The validator shall
ignore the presence and contents of pad and pad2 fields."* §18.5.2 binds
the claim generator; the validator rule is §15.12.1.1. This verifier
already ignores both, so L2 was not a lenient defect.

## Measured

Two probes from `bin/make-manifest-probe-variants.php` (the same builder as
step 318, run again; all its probes were rebuilt with new throw-away keys,
with the same answers as before):

| probe | `c2patool` 0.28.1 | 0.27.22 | here |
|---|---|---|---|
| `datahash-no-pad` (the key renamed `paX`) | error: cannot decode the assertion | error | `Trusted` |
| `datahash-pad-text` (the pad a text string) | `Trusted` | `Trusted` | `Trusted` |

`c2patool` is stricter than the specification on the first. That comes
from how `c2pa-rs` deserialises the assertion (`DataHash.pad` has no
default), not from a validation rule. A pad lies inside the signed
assertion and outside what the hash covers, so its presence or type
changes nothing that is bound.

## Decided

Maurice chose A: follow §15.12.1.1, change no code, and name the
difference. SPEC-012 amendment 10 records it, with AC12 in
`tests/Unit/Manifest/ManifestProbesTest.php`. That test passed at once,
which is right for a test that pins down unchanged behaviour. It is not a
red-then-green test. `docs/reading-c2pa-2.4.md` moves the §18.5.2 row to
*by design*: 54 by design and 61 candidates now.

To help the builder, it now finds the claim and the signature in the
bytes, instead of reading them back through `ManifestStore`. After step
318, `ManifestStore` refuses some of the probes it builds.

- `composer check`: 963 passed. PHPStan in Docker `php:8.3-cli`: no
  errors.
