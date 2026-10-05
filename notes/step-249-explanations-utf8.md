# Step 249 — Every explanation is UTF-8

*2026-10-05. The finding of step 248, approved by Maurice ("akkoord, voer
stap 249 uit").*

**What.** A `ValidationStatus` now replaces every byte sequence of its
explanation that is not UTF-8 with `?` when it is made (`mb_scrub`). The
report's own JSON encoding stays strict (`JSON_THROW_ON_ERROR`), so that the
fuzzer, which encodes every report since step 248, finds any other source.

**Why there.** The bytes came from `CertificateProfileCheck` quoting a
KeyUsage extension that `openssl_x509_parse()` returns raw when it is
damaged, but any check may quote what a certificate or a file carries. One
place covers them all. The recorded API surface is unchanged
(`bin/api-check.php`): `$explanation` stays a public readonly string.

**The fixture.** `tests/Fixtures/hostile-3/keyusage-not-utf8.jpg`, one of
the five faults of step 248: `c2pa-rs/no_alg.jpg` with 8 store bytes
changed (seed 20261005, round 29, `store8`). The KeyUsage came back as
`03 02 46 c0`. `c2patool` 0.27.22 and 0.28.1: `Error: unknown algorithm`.

## Measured

- SPEC-043 AC13 red first (`mb_check_encoding` false; 1 failed, 799
  passed), then green.
- `composer check`: exit 0, 800 tests. `bin/api-check.php`: the recorded
  surface matches.
- The corpus against step 248: two files moved, `hard-bindings-two` and
  `hard-bindings-instance-two`, in the wording of their
  `assertion.multipleHardBindings` explanation only (each binding now
  named with its offset, changed in step 248 after its corpus run); codes
  and states unchanged.
- The release set fuzzed, every report encoded: 16,041 runs over 295 files,
  **0 faults**; 118 stayed `Valid`, each `Valid` in both `c2patool`
  versions.
- Every WAV, MP3, FLAC and AVI file against both versions: unchanged, none
  `Valid` here where `c2patool` is not.

**Verdict**: the check of step 243 holds again after steps 247–249. The
push and the tag wait for Maurice's word.
