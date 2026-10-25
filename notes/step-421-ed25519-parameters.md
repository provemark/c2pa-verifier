# Step 421 — An Ed25519 signer certificate with parameters (SPEC-015 amendment 11)

*2026-10-25.* The second of three probes for the `c2pa` changes between
`c2patool` 0.28.2 and 0.28.3 that the corpus does not cover (step 419).
Maurice approved measuring ("akkoord, meet probe 2") and then the
amendment for the signer only ("akkoord, amendement voor alleen de
ondertekenaar").

## What `c2pa` 0.91.3 checks

Read in `c2pa-rs` PR 2785 (`certificate_profile.rs`), each refusing with
`signingCredential.invalid`, on the end-entity certificate:

| | rule | source | here before this step |
|---|---|---|---|
| A | the outer `signatureAlgorithm` equals `tbsCertificate.signature` | RFC 5280 §4.1.1.2 | checked since SPEC-015 amendment 8 |
| B | an `id-Ed25519` `signatureAlgorithm` has no parameters | RFC 8410 §3 | not checked |
| C | an `id-Ed25519` key has no parameters | RFC 8410 §3 | refused, because OpenSSL cannot read such a key |

C2PA 2.4 §14.5.1.1 lists `id-Ed25519` with RFC 8410 §3 among the
requirements *"all certificates shall fulfill"*.

## Measured

`bin/make-ed25519-params-variants.php` (new) makes a throw-away Ed25519
hierarchy, edits the DER where a probe says so, has the issuer sign the
tbsCertificate again (`openssl pkeyutl -rawin`), and re-signs the PNG
fixture's claim with EdDSA. Keys in scratch, deleted after the run. First
run in scratch, then as fixtures:

| probe | `openssl verify` | 0.28.2 | 0.28.3 | here, before | here, after |
|---|---|---|---|---|---|
| `control` | OK | `Trusted` | `Trusted` | `Trusted` | `Trusted` |
| `leaf-spki-params` | cannot decode the key | `Invalid` (`claimSignature.mismatch`) | `Invalid` (also "public key parameters must be absent") | `Invalid` ("public key cannot be read") | `Invalid` |
| `leaf-signature-params` | OK | `Trusted` | `Invalid` ("signature algorithm parameters must be absent") | **`Trusted`** | `Invalid` |
| `intermediate-signature-params` | OK | `Trusted` | `Trusted` | `Trusted` | `Trusted` |

Without settings, every `Trusted` is `Valid`. 0.28.3 checks only the
end-entity certificate (`check_end_entity_certificate_profile`).

After the fixtures were made, PHPStan (level max) refused the script's
untyped closures; they became typed functions (`edResign()` with
`'signature'` or `'key'`). The rewritten script was run again in scratch:
`leaf-signature-params` `Invalid` and `intermediate-signature-params`
`Trusted`, here and in 0.28.3, as before.

## The change

`CertificateExtensions::fromDer()` records `$ed25519Parameters`: the outer
signatureAlgorithm is `id-Ed25519` with more than the OID. `checkLeaf()`
reports it as `signingCredential.invalid` when the leaf is the manifest
signer. Certificates above the signer are left alone, as in `c2pa-rs`:
the letter says all certificates, but ADR-0005 allows stricter than
`c2patool` only where it prevents a wrong `Valid`, and a parameter in an
intermediate prevents none. A TSA's leaf is not checked for it either:
nothing measured what `c2pa-rs` does there.

## Tests and corpus

- `Ed25519ParametersTest` (AC15, two tests) red first: `leaf-signature-params`
  was `Trusted` ("Failed asserting that two strings are identical"); green
  after the change.
- The corpus (`corpus-norm241.php`, no settings and `trust/full.settings.json`)
  before and after: 1,886 runs before, 1,894 after; no existing file
  moved; the eight new runs are the four new fixtures.

## What changed

- `src/Trust/CertificateExtensions.php`, `src/Trust/CertificateProfileCheck.php`.
- `bin/make-ed25519-params-variants.php` (new), `tests/Fixtures/profile-ed25519/`
  and `tests/Fixtures/c2patool/profile-ed25519/` (0.28.2 and 0.28.3).
- SPEC-015 amendment 11 and AC15; a row in `docs/reading-c2pa-2.4.md`;
  `docs/comparison.md`.
- Not yet in `CHANGELOG.md`: that is written in a release commit.

## Next

Probe 3: ES256 under a P-384 key (the open question of step 416).
