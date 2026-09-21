# Files from `c2pa-org/public-testfiles`

The C2PA's official test files, copied unchanged from
https://github.com/c2pa-org/public-testfiles at commit `22beccc07570`
(2025-12-05). They are licensed **CC BY-SA 4.0** by the Coalition for
Content Provenance and Authenticity; that licence attaches to these files,
not to this package's code (MIT). Attribution: © C2PA, from the
`public-testfiles` repository, `legacy/1.4/image/jpeg/`.

Why they are here: our own fixtures are all written by c2patool 0.27.22
(c2pa-rs 0.90, claim v2, ES256, no timestamp). These files come from other
writers and other years, and show what our fixtures cannot
(`notes/step-09-manifest-store-inside.md`).

| file | from | what it shows | c2patool 0.27.22 |
|---|---|---|---|
| `adobe-20220124-C.jpg` | `legacy/1.4/image/jpeg/` (c2pa-rs 0.16.1, 2022) | claim **v1** (`c2pa.claim`, `claim_generator` string, one `assertions` list), a `json` content box (`stds.schema-org.CreativeWork`), `x5chain` and `sigTst` in the **unprotected** COSE header, three certificates, a 512-byte RSA (PS256) signature, an RFC 3161 timestamp token | `Valid`, `claim_version 1`, `timeStamp.validated`; our SPEC-001 extractor yields 51,118 bytes, SHA-256 `832268c0e166025247cd7ce6cf5eab6e45fb6d280cc3c3b57b1aa8d6ed2c43d8` |

SHA-256 of the file as committed:

```
75a8da33f6eaf1e16bf3b42cd78913b22b2e6a671fda217a508b1ba4230ce864  adobe-20220124-C.jpg
```

More of the set (`CA`, `CAI` with ingredients; `E-sig-`, `E-dat-`, `E-uri-`,
`E-clm-` error classes) is added when M3, M4 and M7 need it, each with its
measured c2patool result here.

## Added 2026-09-21 (step 36): the whole legacy JPEG set

The other 25 JPEGs of `legacy/1.4/image/jpeg/` at the same commit, copied
unchanged (the repository's own `README.md` there gives each file's
expected verdict; the naming: **C** created, **A** action, **I**
ingredient, **E-sig** / **E-dat** / **E-uri** / **E-clm** a deliberate
fault in the signature / hard binding / an assertion's hashed URI / a
referenced claim, **X** a hash mismatch; `A` and `I` carry no manifest).
Together 20 MB, of which the three Truepic camera files are 10 MB.
c2patool 0.27.22's JSON for every one, with the full test trust
settings, is under `../c2patool/public-testfiles/`.

What they added on first contact (`notes/step-36-public-testfiles.md`):
four camera files carry CBOR floats (`stds.exif`, Truepic odometry)
that SPEC-006 refused; nine files hold more than one manifest, and one
of them (`E-uri-CIE-sig-CA`) is tampered only in an *ingredient*
manifest — which this verifier does not validate until M7, so a store
with more than one manifest is `Invalid` until then (SPEC-013 amendment
5, Maurice's decision).
