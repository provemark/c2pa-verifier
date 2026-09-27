# Hostile input, the second round (step 161, SPEC-045)

Three files for the criteria of SPEC-045 that use a file, made by
`bin/make-hostile-input-2-variants.php`. Each is `../fixture-signed.png`
with one unreferenced assertion added to the active manifest's assertion
store. Nothing is re-signed. The inputs larger than about 1 MB (a 4 MB
JSON assertion, one 8 MB assertion named 1,000 times, 2 million CBOR
chunks in the COSE header) are built by the tests in memory.

| file | the added assertion | this verifier |
|---|---|---|
| `json-string-200k.png` | a JSON object of one 200 KiB string: within `MAX_JSON_BYTES` | `Invalid` (`assertion.undeclared`) |
| `json-numbers-2x150k.png` | two JSON arrays of 150 KiB of `10,`, about 51,200 items each | `Invalid`, the store's item limit named |
| `bfdb-empty.png` | an embedded file whose `bfdb` box is empty | `Invalid`, *"no media type"* (was a `ValueError`) |

Both `c2patool` versions refuse all three before a report, for the
undeclared assertion (step 160).
