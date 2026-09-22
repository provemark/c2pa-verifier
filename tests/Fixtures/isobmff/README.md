# ISOBMFF variants (SPEC-026)

Built by `bin/make-isobmff-variants.php` from `../fixture-signed.mp4`, each
breaking exactly one thing so that a test failing on it names one cause.
They are committed because they are small and deterministic — unlike
SPEC-024's 16 MiB stores, which are generated inside the test.

`c2patool` 0.27.22's answer is recorded in `../c2patool/isobmff/` for the
two variants it could produce a report for; for the rest it exits with an
error, and that error is quoted below rather than stored.

| variant | what is broken | `c2patool` 0.27.22 |
|---|---|---|
| `two-c2pa-boxes` | the C2PA `uuid` box appears twice | `Error: more than one manifest store detected` |
| `purpose-merkle` | the purpose says `merkle` | `Error: invalid type: integer 0, expected struct BmffMerkle` — it tries to read merkle data |
| `purpose-unknown` | the purpose says `nonsense` | `Error: No claim found` — **ignored**, where this verifier refuses by name |
| `purpose-unterminated` | no null byte ends the purpose string | `Error: asset could not be parsed: UUID box purpose field mis…` |
| `size-below-header` | the box size is 4, smaller than its own header | `Error: asset could not be parsed: Box size extends beyond asset` |
| `size-past-end` | the box size runs past the end of the file | the same |
| `largesize-missing` | `size == 1` promises a 64-bit length that is not there | the same |
| `size-zero-not-last` | `size == 0` ("to the end of the file") on a box that is not last | `Invalid` — **read anyway**, where this verifier refuses |
| `size-zero-last` | the same declaration on the last box: legal | `Invalid` (the bytes moved, so its BMFF hash no longer matches) |
| `uuid-not-c2pa` | a `uuid` box carrying another UUID | `Error: No claim found` — no manifest, as here |

Two of these are places where SPEC-026 is deliberately stricter than
`c2patool`, and both are recorded in `docs/comparison.md` when the spec is
implemented: an unknown purpose (`c2patool` treats it as "no manifest";
here it is an error, because a box that says C2PA and then says something
we cannot read is not the same as a file with no credentials), and
`size == 0` on a box that is not the last one (`c2patool` reads it; here it
is a contradiction, since the declaration means "to the end of the file").
