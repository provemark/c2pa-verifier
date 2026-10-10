# Step 341 — SPEC-067 drafted: icons in data boxes, read and checked

*2026-10-10.*

SPEC-034 refused an icon whose hashed URI names a data box, against the
*should* of C2PA 2.4 §10.2.3.2, because no file had one and `c2pa-rs`
accepts one without checking its hash. It named its own trigger: *"A real
file with one would be its own spec."*

A downstream measurement found the file. The Drupal module C2PA Sign
1.4.11 signs every upload with `c2patool` 0.9.12; with a site logo set,
every manifest it writes carries a `c2pa.databoxes` store (`c2db`) holding
the logo as `c2pa.data`, and its `claim_generator_info` icon is a hashed URI
to that box. Measured on the fixture (copied as
`tests/Fixtures/databox/c2pasign-logo.png`):

- the store layout: `c2db` "c2pa.databoxes" → `cbor` "c2pa.data", beside
  the assertion store;
- the url `self#jumbf=/c2pa/<own label>/c2pa.databoxes/c2pa.data`, with
  `alg: sha256` and a hash;
- that hash covers the `c2pa.data` superbox without its 8-byte header, the
  range §8.4.2.3 gives an assertion (`Superbox::payload()`), on both
  manifests;
- this verifier: `Invalid` (`assertion.missing` on both urls). `c2patool`
  0.28.1 under the C2PA test anchors: `Trusted`.

SPEC-067 reads the store and checks the hash: more lenient than SPEC-034,
stricter than `c2pa-rs`, within ADR-0005. Six acceptance criteria.

Maurice approved going ahead during the consolidation period (it lifts a
refusal on the trigger SPEC-034 named, rather than adding a capability),
then approved the spec.
