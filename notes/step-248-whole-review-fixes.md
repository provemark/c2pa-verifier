# Step 248 — The whole review's fixes; the check repeated, and a new finding

*2026-10-05. Builds the six amendments of step 247.*

## Built

| amendment | change |
|---|---|
| SPEC-007 6 | `ManifestStore::plain()` renders a non-finite float as `"NaN"`, `"Infinity"`, `"-Infinity"` |
| SPEC-019 2 | `Command::run()` catches a `JsonException` from `toJson()`: one `Error:` line, exit 2 |
| SPEC-043 2 | `FormatDetector::head()` reads the stream's `seekable` flag before `rewind()` |
| SPEC-009 3 | an `id-RSASSA-PSS` key: OpenSSL's check *and* the EMSA-PSS check with the salt fixed, on the same key read as `rsaEncryption` (`PublicKey::asRsaEncryption()`, a strict SPKI rewrite: the `Cose` layer may not use `Asn1`) |
| SPEC-012 9 | `Hash\HardBindings`: base labels; the `Verifier` counts every hard binding before choosing the data-hash or BMFF check; `DataHashCheck` reads the binding under its own label (the first build read `c2pa.hash.data` and printed PHP warnings on `__1`, seen before the commit); the explanation names each binding with its offset, as the old one did |
| SPEC-046 1 | `CertificateExtensions::normalise()` returns bytes that are not UTF-8 as they are |

Also: `docs/comparison.md` (an unknown critical extension is equal to
`c2patool` since SPEC-046; assertions are rendered; the NaN row; three
cases now equal), the `ContainerException` docblock, CHANGELOG 0.3.0
(*Security*, *Fixed*), SECURITY.md (eighteen cases), and `bin/fuzz.php`,
which now calls `toJson()` on every report. With the old `src/` and the
NaN file as its seed, it finds 14 faults in 20 runs; with the new, 0.

## Measured

- `composer check`: exit 0, 799 tests.
- The corpus against step 245: one existing file moved,
  `binding/hard-bindings-two.png`, and only in the wording of its
  `assertion.multipleHardBindings` explanation; twelve new rows are the
  new fixtures.
- `t61-outside` `Valid` with `signingCredential.untrusted`, naming the
  constraint; `t61-inside` `Trusted`.
- Every WAV, MP3, FLAC and AVI file against both `c2patool` versions:
  unchanged; none `Valid` here where `c2patool` is not.
- The release set fuzzed again, now encoding every report: 16,041 runs
  over 295 files; 118 stayed `Valid`, each `Valid` in both `c2patool`
  versions; **5 faults**.

## A new finding: text that is not UTF-8 in an explanation

The five faults are one cause. A mutated certificate's KeyUsage comes back
from `openssl_x509_parse()` as raw bytes, and
`CertificateProfileCheck` puts them in the explanation of
`signingCredential.invalid`; `toJson()` then throws *Malformed UTF-8
characters*. The certificate travels in the file and the explanation is
written whether or not the signature holds, so no key is needed. Measured:
`v0.2.9`'s `bin/c2pa-verify` on one of the five ends with PHP's fatal error,
exit 255; `c2patool` 0.27.22 and 0.28.1 say `Error: unknown algorithm`.
Since this step the command answers exit 2 with one line (SPEC-019
amendment 2), but the library's `toJson()` still throws. Not built: it is
proposed to Maurice first.
