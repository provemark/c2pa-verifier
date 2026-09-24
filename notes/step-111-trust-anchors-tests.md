# Step 111 — SPEC-031: the tests seen red (111a), then built (111b)

*2026-09-24. SPEC-031 approved by the maintainer the same day. Tests and
fixtures only; `src/` is unchanged.*

## The fixtures

`bin/make-anchors-variants.php <c2patool-0.28.0>` writes three things. It
refuses to run against any other `c2patool` version.

- **Settings** under `tests/Fixtures/trust/anchors/`: 20 files. They hold
  only public certificates and EKU lists already in the repository, plus
  the probe's root.
  - `full` (AC1);
  - the twins `full-twin` and `full-plus-digicert-g4-twin` (AC7). A twin
    carries the same PEM once as `"manifest"` and once as `"tsa"`, because
    that is what the legacy string means;
  - `legacy-plus-truepic-entry` (AC2) and `allowed-in-entry` (AC3);
  - the kind cases `test-roots-as-tsa`, `test-roots-as-cawg`,
    `digicert-as-tsa`, `digicert-as-manifest` and `tsa-with-allowed-list`
    (AC6);
  - step 110's E1–E9 (AC8).
- **The probe**, `eku-probe.jpg`: `fixture-unsigned.jpg` signed by a leaf
  whose only EKU is `1.3.6.1.4.1.99999.1`, under an intermediate and a
  root; `eku-probe-root.pem` is the root. The three keys are made in a
  temporary directory, overwritten and deleted by a shutdown handler, and
  the script exits non-zero if one survives. The file changes on every
  run, and the recorded answers are made from the same run.
- **What `c2patool` 0.28.0 said**, under `tests/Fixtures/c2patool/anchors/`:
  25 reports.

A check that no private key reached the new directories:
`grep -rl "BEGIN.*PRIVATE" tests/Fixtures/trust/anchors
tests/Fixtures/c2patool/anchors` returns nothing.

## Red, and why each is red

`vendor/bin/pest --group=SPEC-031`: **8 failed**. Each was read for its
reason:

| test | why red |
|---|---|
| AC1, AC2, AC3, AC6, AC7, AC8 | `TrustException: unknown key trust.anchors`. SPEC-014 AC7 refuses the new shape today, as it should until this spec is built |
| AC4 | a loose `allowed_list` is still accepted, so there is no message naming `trust.anchors[].allowed_list` |
| AC5 | the messages are about the unknown key, not the entry and field |

**One false green was caught before commit.** AC5's first case, `anchors`
as an object, looked for `trust.anchors` in the message. Today's *"unknown
key trust.anchors"* contains that text, so the case passed for the wrong
reason. The needle is now `not a list`. The byte-count cases were
tightened the same way (`more than 32`, `more than 256`). This is the
`not->toContain` trap in another form: a needle that is present for a
reason other than the one being tested.

Some parts of the suite will pass without any change, and each says so in
a comment:
- AC8's legacy cases (E1, E2, E2b, E9) are already this verifier's
  verdicts (step 110);
- AC6's last clause (the legacy DigiCert settings) is SPEC-017 AC6's
  behaviour today.

Each sits in a test that is red for another reason, so nothing is green
from birth without its test being red.

`composer check`: 8 failed, 422 passed. PHPStan, Deptrac and Pint are
clean. PHPStan's 18 findings on the first draft were fixed with the
repository's own idiom for untrusted JSON (`assert()` on shape), not
suppressed.

## Not pushed

Committed locally. A red `main` on a public repository serves no reader,
so this step goes out together with 111b.

## 111b — built

Four source files changed and one was added. Everything is green:
`composer check` (430 passed, PHPStan, Deptrac, Pint, the API check) and
`composer test:parallel` (430).

- **`TrustAnchorSet`** (new, contract): the kind, the anchors, the
  entry's allowed list, its EKUs and its URI. A `"tsa"` or `"cawg"` entry
  with an allowed list is refused in the constructor (§14.4.3).
- **`TrustSettings::fromArray()`**: `trust.anchors` is read entry by
  entry. The index and the field are named in every refusal, the
  certificate total is bounded across all entries, and a top-level
  `allowed_list` is refused first, before anything else is read.
- **`ChainCheck`**: the anchors a chain may reach are the legacy list plus
  every `"manifest"` entry, and the TSA's are the legacy list plus every
  `"tsa"` entry (`anchorsOf()`, `tsaAnchorsOf()`). An untrusted outcome
  names an entry of another kind **only if the chain would have reached
  it** (`kindNote()`).
- **`CertificateProfileCheck`**: the accepted EKUs are the built-in list,
  plus the top level, plus the `trust_config` of every `"manifest"` entry
  the chain reaches (`acceptedEkus()`).
- **`TimestampCheck::tsaSettings()`**: the TSA's anchors come from
  `tsaAnchorsOf()`, and the kind note is added to an untrusted TSA.

### What the red-to-green run found

- **AC7 caught an explanation that said too much.** The first `kindNote()`
  named every entry of another kind. In the twins, where the same PEM
  sits under `"manifest"` and `"tsa"`, that added a sentence about an
  entry that changed nothing, and the reports differed. It now names
  only an entry the chain would have reached (SPEC-031 amendment 1).
- **The API check rang twice before anything was written down**: once for
  a new class in neither list, and once for twelve unrecorded symbols.
  Four helper methods first sat on `TrustSettings`, where every public
  method is contract. They moved to the `@internal` `ChainCheck`, and
  the contract grew by 12 symbols instead of 16 (SPEC-025 amendment 4).
- **SPEC-014 AC3 went red**, as it had to: its two settings files are
  refused now. The test moves their certificates into a `"manifest"`
  entry and keeps the point, that the allowed list is tried before the
  walk (SPEC-014 amendment 3, weight A).

### What changes for a user

A settings file with a top-level `allowed_list` now gets exit 2 and a
sentence saying where the list belongs. That is a break, so the next tag
should be `0.2.0` (see `CHANGELOG.md`). Every other settings file, the
sister repository's included, gives the same report as before: AC7 checks
that over the three corpora, report for report.
