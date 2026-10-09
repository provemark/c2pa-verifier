# Step 313 — Probes for the assertion candidates (L2, L4–L8, L11, P08-3)

*2026-10-09. Measurement only; no change to `src/`.*

Step 312 left thirteen candidates where this verifier may be more lenient
than `c2patool`, all read and none measured. This step measures those
that need a changed assertion or manifest label in a PNG.

## How the probes were made

In a scratch directory, a throw-away P-256 hierarchy (a root and a leaf on
the C2PA profile; keys deleted afterwards) and a settings file holding the
root.

1. `c2patool` 0.28.1 signs `fixture-unsigned.png` from a manifest
   definition that adds the assertion under test.
2. `c2patool` refuses to write most of the shapes that matter, with the
   same checks it makes when reading:
   - "missing field `@context`";
   - "references a hard binding assertion";
   - "invalid size (must be >= 1)";
   - "soft binding assertion could not be decoded";
   - "expected a string".
   So it signed a well-formed version instead.
3. A small tool then changed a few bytes inside one assertion box (or the
   manifest label) without changing their length. It re-hashed the
   claim's hashed URI for that box, signed the claim again with the same
   throw-away leaf (the COSE keeps its length), and fixed the PNG chunk's
   CRC. The store keeps its length, so the data hash still holds.
4. A run that changes nothing and only signs again stays `Trusted` in
   both versions and here.

## Result

| probe | `c2patool` 0.28.1 | 0.27.22 | this verifier |
|---|---|---|---|
| control; the same signed again | `Trusted` | `Trusted` | `Trusted` |
| data hash: `pad` renamed `paX` | error: missing field `pad` | error | **`Trusted`** |
| metadata: `@context` renamed `@contexX` | error: could not decode the assertion | error | **`Trusted`** |
| `c2pa.time-stamp` whose value is text | `Trusted` | `Trusted` | `Trusted` |
| certificate status: `ocspVals` renamed | error: missing field `ocspVals` | error | **`Trusted`** |
| cloud data whose `label` is `c2pa.hash.data` | `Invalid` | `Trusted` | **`Trusted`** |
| cloud data whose `size` is 0 | `Invalid` | `Trusted` | **`Trusted`** |
| soft binding: `blocks` renamed | `Invalid` | `Trusted` | **`Trusted`** |
| manifest label `urn:c2pa:` changed to `urx:c2pa:` | `Invalid` | `Invalid` | **`Trusted`** |
| an action's `when` written as the integer 120 | error | error | **`Trusted`** |

- **Confirmed: L2, L4, L6, L7, L8, L11 and P08-3.** In each case this
  verifier is `Trusted` where `c2patool` 0.28.1 is `Invalid` or refuses
  the file. L7 and L8 are new in 0.28.1; 0.27.22 agrees with this
  verifier.
- **Not confirmed: L5.** A time-stamp assertion that holds text is
  `Trusted` in both versions, as here. It is removed from the list.
- **Not done here: L13.** It needs a claim of another length; it moves to
  the ISOBMFF probes, which need a length-changing edit anyway.

A metadata assertion with its `@context` is `Invalid`
(`assertion.metadata.disallowed`) in 0.27.22 alone, an old difference.

## Next

L3 (two timestamp tokens) in the timestamp matrix. Then the ISOBMFF and
structure probes (L1, L9, L10, L12, L13). Then the fixes, each with its
probes as fixtures. The scratch tool that edits and signs again becomes a
`bin/` builder at that point.
