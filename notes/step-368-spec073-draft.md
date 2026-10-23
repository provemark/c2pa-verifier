# Step 368 — SPEC-073 drafted: a validation time given by the caller

*2026-10-23.* The second step of the plan in step 367. The C2PA
Conformance Program's test harness takes a validation time (RFC 3339);
this verifier always uses the clock. `specs/SPEC-073-validation-time.md`
is the draft, for approval. No code.

## What the draft says

- An optional `?DateTimeInterface $validationTime` on `Verifier::verify()`
  and `FragmentedVerifier::verify()`, and `--at <RFC 3339>` on the
  command line.
- It replaces "the current time" of C2PA 2.4 §15.8.2, §15.9, §15.9.1,
  §15.7 and §14.4.1, and nothing else. A trusted timestamp's attested
  time keeps precedence (§15.8.2).
- Fractions of a second are rounded down; `notAfter` is inclusive.
- No report field for the time yet: crJSON's `validationTime` belongs to
  the crJSON spec.
- Without a time nothing changes (AC6).

## Fixtures: existing ones suffice

Measured with `bin/c2pa-verify` on 2026-10-23:

| file | what makes it fit |
|---|---|
| `timestamp/tsa-matrix/expired-signer-no-timestamp.png` | signer valid `2026-10-08T05:30:24Z` to `2026-10-09T05:32:54Z`, no timestamp: `Invalid` now, a minute's window to judge it in |
| `timestamp/tsa-matrix/expired-signer-trusted-tsa.png` | the same shape with a trusted timestamp: `Trusted` through the attested time, so a given time must change nothing |
| `c2pa-rs/ocsp.jpg` | a real file: a stapled OCSP response current `2025-08-11T21:51:18Z` to `2025-08-18T21:51:18Z`, an active signer and an ingredient signer both valid inside that week, and an untrusted TSA, so both are judged at the current time |

The step-367 proposal needed a new fixture for the OCSP window.
`ocsp.jpg` makes that unnecessary.

## No oracle

`c2patool` 0.28.2 has no time option (`--help`), and the settings of
`c2pa-rs` 0.91.1 have no validation time (searched in `src/settings/`).
The acceptance criteria rest on the specification's text; AC6 ties the
default to today's answers.

## Measured and reasoned

- Measured: the specification passages, by searching the published C2PA
  2.4 HTML for "current time" and "time of validation"; the three
  fixtures' windows and statuses; the `time()` calls in `src/`
  (`ChainCheck.php:118`, `OcspCheck.php:112`,
  `CertificateProfileCheck.php:115`); that `public-surface.txt` lists
  names, not parameters.
- Reasoned: that rounding down is safe, since certificate validity is
  given in whole seconds; that an optional parameter makes this a minor
  release.
