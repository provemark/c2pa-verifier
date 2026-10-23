# `c2patool --crjson`, the oracle for SPEC-074 AC7

`c2patool` 0.28.2 (`c2pa-rs` 0.91.2), run on 2026-10-23 as

```sh
c2patool <file> --crjson > crjson/<path of the file under tests/Fixtures>.json
```

over `fixture-signed.*` and the `c2pa-rs/`, `writers/` and
`public-testfiles/` corpora. A file without a manifest gave no output and
has no answer here. Three files whose store this verifier does not read
(`c2pa-rs/cloud.jpg` and the two remote-manifest writer files) were left
out. 59 answers.

The output is kept as `c2patool` wrote it. It is not schema-valid
(step 370: every manifest carries `isUpdateManifest` and
`isCompressedManifest`), and `validationTime` is the moment of the run.
SPEC-074 compares only the assertion values.
