# Step 255 — The signature decoded once per manifest

*2026-10-06. The third clean-up step; no behaviour changes.*

**What.** Per manifest, seven places read the claim signature:
`Verifier::signatureInfo()`, `::unprotectedHeader()`-style reads and
`::chainOf()`, `ChainCheck`, `CertificateProfileCheck` and `TimestampCheck`
through `CoseSign1::ofManifest()`, and `ClaimSignatureCheck` through
`CoseSign1::fromBytes()`. Three of them built the whole x5chain as
`Certificate` objects again. Now:

- `CoseSign1::ofManifest()` remembers its result per manifest in a
  `WeakMap`. `Manifest` and `CoseSign1` are `final readonly`, so a kept
  result cannot go stale, and the map forgets an entry with its manifest;
  a fault is not kept, so it is thrown each time, as before.
- `Certificate::chainOf(CoseSign1)` builds the chain once per signature, in
  `Trust` because Deptrac does not let `Cose` use `Trust`. `ChainCheck`,
  `CertificateProfileCheck` and `Verifier::chainOf()` use it.
- Left as they were, on purpose: `ClaimSignatureCheck`, which decodes the
  bytes itself with its own fault handling, and `Verifier::signatureInfo()`,
  which reads the leaf only — building the whole chain there would lose
  the signer's details when an intermediate cannot be read.

Per manifest: 7 decodes become 2, 3 whole-chain builds become 1.

## Measured

Before (in step 254), the repeated work reproduced on its own: 7 decodes
and 4 chains per manifest took 11.9 ms of `exp-test1.png`'s 41.6 ms, 21.6
of the Adobe six-manifest file's 29.9, 1.9 of `fixture-signed.jpg`'s 2.3 —
upper bounds, as not every check runs for every manifest.

After, the old and the new `src/` timed in the same session, best of five
rounds of ten verifications:

| file | manifests | step 254 | step 255 |
|---|---|---|---|
| `c2pa-rs/exp-test1.png` | 6 | 44.5 ms | 39.7 ms |
| `public-testfiles/adobe-20220124-CAIAIIICAICIICAIICICA.jpg` | 6 | 31.5 ms | 24.9 ms |
| `c2pa-rs/CACAE-uri-CA.jpg` | 3 | 12.3 ms | 9.5 ms |
| `fixture-signed.jpg` | 1 | 2.4 ms | 1.3 ms |

- The new test (the same object twice; a refused signature refused twice)
  red first (`chainOf()` undefined), then green; PHPStan; Deptrac.
- `composer check`: exit 0, 805 tests.
- The corpus against step 254: 0 of 1,400 measurements moved.
- `bin/fuzz.php 20261005 60` over the release set: 16,041 runs, 0 faults,
  the same 118 files `Valid` as in step 254.
