# SPEC-031: the `trust.anchors` list, and a loose `allowed_list` refused

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-24                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

This project has a fixed design rule: **one trust settings file serves
`c2patool`, the sister library's signing service and this verifier**.
SPEC-014 fixed that file's shape as `verify.verify_trust`,
`trust.trust_anchors`, `trust.allowed_list` and `trust.trust_config`, each
holding file contents as a string.

`c2pa` 0.91.0 (2026-09-21), the engine inside `c2patool` 0.28.0
(2026-09-22), changes that shape. Step 107 measured the consequences:

- `trust.trust_anchors` is **deprecated** in favour of a list,
  `trust.anchors`, and its removal is announced for 0.92.0 (*"scheduled for
  mid-November 2026"*). In 0.28.0 it still works: every anchor-based verdict
  is unchanged.
- `trust.allowed_list` **no longer exists at the top level**. It moved inside
  each entry of `trust.anchors`, and a top-level one is **dropped without an
  error or a warning**. Given `allowed-only.settings.json`, 0.27.22 says
  `Trusted`, 0.28.0 says `Valid` with `signingCredential.untrusted`, and this
  verifier still says `Trusted`. The same file now means two things.

Without this spec:
- A settings file written for current `c2patool` (`trust.anchors`) is
  refused here as an unknown key (SPEC-014 AC7). The shared file stops
  being shared in one direction now.
- It stops being shared in the other direction when 0.92.0 removes
  `trust_anchors`.
- A loose `allowed_list` gives `Trusted` here and `Valid` in the current
  oracle. That makes this verifier the more lenient of the two, which is the
  wrong side for a verifier.

The C2PA specification does not define a settings file. It defines what
the lists are for, in C2PA 2.4 §14.4 *Trust Lists* (numbers checked against
the 2.4 text on 2026-09-24, step 110):

- **§14.4.1 *C2PA Signers*:** *"For each accepted EKU value, a list of
  'trust anchor configurations'"*. Trust is per anchor and per EKU, not
  one pool.
- **§14.4.2 *Time Stamping Authorities*:** the TSA anchors *"shall be
  separate from the lists for C2PA signers"*.
- **§14.4.3 *Private Credential Storage*** (the allowed list): it *"shall
  only apply to validating signed C2PA manifests, and shall not apply to
  validating time-stamps"*.

## Scope

**In scope**

- `trust.anchors`: a JSON list of objects. This verifier reads each entry
  exactly as `c2pa` 0.91.0 deserialises it:
  - `trust_anchors` (string of PEM contents, **required**);
  - `trust_kind` (**required**, one of `"manifest"`, `"tsa"`, `"cawg"`);
  - `trust_uri`, `trust_config`, `allowed_list` (strings, optional);
  - `trusted_ica_issuers` (a list of strings, optional).
- The deprecated `trust.trust_anchors` stays readable. It is treated as one
  more anchor set, as `c2pa` 0.91.0 does
  (`merge_legacy_trust_anchors()`), so every existing settings file keeps
  its meaning.
- **A top-level `trust.allowed_list` is refused** (maintainer's decision,
  2026-09-24). `TrustSettings::fromJson()` throws `TrustException` with a
  message that names the new place, `trust.anchors[].allowed_list`, and the
  CLI exits 2 (SPEC-019). `c2patool` 0.28.0 ignores the field silently;
  this verifier does not ignore input silently.
- Everything `c2patool` 0.28.0 refuses is refused here too: a missing
  `trust_kind`, an unknown `trust_kind`, `anchors` that is not a list, an
  entry without `trust_anchors`.
- **Stricter than the oracle:** an unknown key inside an anchor entry is
  refused. `c2patool` 0.28.0 ignores it (step 107, probe N9). This follows
  SPEC-014 AC7: the settings are whole or absent.
- The same bounds as SPEC-014: `DEFAULT_MAX_CERTIFICATES` counts across all
  entries together, and there is a maximum number of entries (open
  question 4).
- Amendments this spec forces:
  - **SPEC-014** AC3 and AC7: a loose `allowed_list` goes from trusted to
    refused.
  - **SPEC-025**: `TrustSettings` changes. It is a contract class.
  - The three fixtures that carry a loose `allowed_list` (`allowed-only`,
    `full-plus-allowed`, `allowed-plus-wrong-root`) are kept byte for byte
    as the refusal's evidence. Each gets a `trust.anchors` twin with the
    same certificates, so SPEC-014 AC3 is still measured.

**Out of scope** (each needs its own spec before it may be built)

- Re-pinning the oracle from 0.27.22 to 0.28.0 for the whole corpus. Step
  107's corpus run shows that 0.28.0 changes the result of 14 of 281 files,
  seven of them in their verdict, for reasons that have nothing to do with
  settings (see *References*). That is its own step, measured before it is
  specified.
- CAWG: `trust_kind: "cawg"` and `trusted_ica_issuers` are read and checked
  for shape so that a `c2patool` settings file is not refused. They are used
  for nothing, because this verifier refuses CAWG files by name.
- Reading the official C2PA trust list's ETSI TS 119 602 JSON directly
  (issue #9's territory).
- `trust_uri` in the report. Whether `c2patool` 0.28.0 reports it was not
  measured.

## Behavior

- **AC1 — the new shape is read, and gives the same verdict as the old**
  - Given `full.settings.json` rewritten as one `trust.anchors` entry
    (`trust_kind: "manifest"`, the same PEM, `trust_config` at the top level
    as before)
  - When `fixture-signed.jpg`, `.png`, `.webp` and `.mp4` are verified
  - Then each report is byte-identical to the one `full.settings.json`
    gives, and the state is `Trusted`. This is `c2patool` 0.28.0's verdict
    (probe N1).

- **AC2 — the old shape still works, and both together add up**
  - Given `full.settings.json` unchanged, and a file holding both
    `trust.trust_anchors` (the test roots) and a `trust.anchors` entry (the
    Truepic root)
  - When `fixture-signed.jpg` and a Truepic file are verified
  - Then the old file gives `Trusted` for `fixture-signed.jpg` exactly as
    today; the combined file gives `Trusted` for both, because the sets are
    added together as in `c2pa` 0.91.0 (probe N8). Only the signer's
    verdict is compared on the Truepic file: its data-hash verdict differs
    between oracle versions, and that is out of scope.

- **AC3 — the allowed list lives in the anchor now**
  - Given a `trust.anchors` entry with empty `trust_anchors` and
    `allowed_list` = `allowed_list.pem`
  - When `fixture-signed.jpg` is verified
  - Then the state is `Trusted` without a chain, as SPEC-014 AC3 measured
    for the old shape and as `c2patool` 0.28.0 gives for the new one
    (probe N5).

- **AC4 — a loose `allowed_list` is refused, and says where it went**
  *(error path; the maintainer's decision)*
  - Given `allowed-only.settings.json`, `full-plus-allowed.settings.json`,
    `allowed-plus-wrong-root.settings.json`, byte for byte as they are today
  - When `TrustSettings::fromJson()` reads them, and when `bin/c2pa-verify`
    is run with each
  - Then `fromJson()` throws `TrustException` and the message contains
    `trust.anchors[].allowed_list`. The CLI exits 2 with nothing on stdout.
    Nothing is half-built. Recorded next to it: `c2patool` 0.28.0 gives
    `Valid` for the first and third file and `Trusted` for the second, and
    says nothing about the field.

- **AC5 — malformed `trust.anchors` is refused whole** *(error path)*
  - Given each of the following:
    - `anchors` as an object;
    - an entry without `trust_kind`;
    - `trust_kind: "signer"`;
    - an entry without `trust_anchors`;
    - `trust_anchors` as a number;
    - an unknown key `foo` inside an entry;
    - one entry more than the maximum;
    - certificates beyond `DEFAULT_MAX_CERTIFICATES` spread over two entries.
  - When `fromJson()` reads it
  - Then `TrustException` names the entry by its index and the field. The
    first four are the cases where `c2patool` 0.28.0 also exits with an
    error (probes N2, N10, N11, N12). The unknown key is where this verifier
    is stricter (probe N9), and the test says so.

- **AC6 — every anchor counts only for its own kind** *(open question 1, answered 2026-09-24)*
  - Given, for the signer side, `trust.anchors` holding only the test
    roots, once as `"tsa"` and once as `"cawg"`. Given,
    for the time-stamping side, the DigiCert cross-certificate
    (`digicert-trusted-root-g4.pem`) as the only entry, once as `"tsa"`
    and once as `"manifest"`.
  - When `fixture-signed.jpg` is verified with the first two, and
    `c2pa-rs/C.jpg` with the last two
  - Then:
    - `fixture-signed.jpg` is `signingCredential.untrusted` and `Valid`
      both times. Only `"manifest"` entries (their anchors and their
      `allowed_list`) and the legacy `trust_anchors` can make a signer
      trusted.
    - A `"tsa"` entry that carries an `allowed_list` is refused by
      `fromJson()`, and the message cites §14.4.3: an allowed list never
      applies to time-stamps. `c2patool` 0.28.0's handling of it was not
      measured.
    - `C.jpg` reports `timeStamp.trusted` with the `"tsa"` entry, exactly
      as SPEC-017 AC6 does with the legacy settings. With the
      `"manifest"` entry it reports `timeStamp.untrusted`, and its
      explanation names the entry's kind. Only `"tsa"` entries and the
      legacy `trust_anchors` can make a time-stamping authority trusted.
    - A `"cawg"` entry makes nothing trusted.
    - The legacy `trust.trust_anchors` still counts for both sides:
      `full.settings.json` and `digicert-trusted-root-g4.settings.json`
      give today's reports byte for byte (AC2, AC7).
    - Each of these is **stricter than `c2patool` 0.28.0**, which gives
      `Trusted` for the signer in the first two cases (probes N3, N4) and
      `timeStamp.trusted` for a `"manifest"` entry (probe T2). The
      difference is named in `docs/comparison.md`.

- **AC8 — a `trust_config` counts for its own entry** *(open question 2, answered by measurement in step 110)*
  - Given `eku-probe.jpg`. It is signed by a throwaway leaf whose only EKU
    is `1.3.6.1.4.1.99999.1`, under a throwaway intermediate and root; it
    is built by a `bin/make-*` script in the tests-first step, and the
    keys are shredded before the script ends.
  - When it is verified under each of the settings E1–E9 of step 110
  - Then each verdict equals `c2patool` 0.28.0's under the same settings:
    - the EKUs accepted for a certificate that chains to an entry's anchor
      are the built-in list, plus the top-level `trust_config`, plus
      **that entry's own** `trust_config`;
    - one entry's `trust_config` never widens another's. E5 is `Invalid`
      with `signingCredential.invalid` (*"missing required EKU"*), where a
      union across entries would say `Trusted`;
    - E1, E2, E2b and E9 (the legacy shape) are already this verifier's
      verdicts today, measured.

- **AC7 — the drift alarm learns the new shape**
  - Given the corpus runs that already use `full.settings.json` and
    `full-plus-digicert-g4.settings.json`
  - When they are also run with their `trust.anchors` twins
  - Then every report is identical to the old shape's.

## References

- Specification: C2PA 2.4 §14.4.1 *C2PA Signers*, §14.4.2 *Time Stamping
  Authorities*, §14.4.3 *Private Credential Storage*. Read in the 2.4 HTML
  of `c2pa-org/specifications` at `4eb2c67` (2026-09-16), step 110.
- Oracle: `c2patool` 0.28.0 (`universal-apple-darwin` release asset, SBOM
  `c2pa` 0.91.0), `c2patool <file> --settings <path>`. The probes N1–N13 and
  T1–T4 were run in step 107's follow-up with constructed settings files
  that are not in the repository yet. They become fixtures in the
  tests-first step.
- Read: `c2pa` 0.91.0 `sdk/src/settings/mod.rs` (`Trust`, `TrustAnchor`,
  `TrustListKind` with `rename_all = "lowercase"`,
  `merge_legacy_trust_anchors()`) and
  `sdk/src/crypto/cose/certificate_trust_policy.rs`.
- Measured, and out of scope here: the corpus under 0.28.0 against 0.27.22,
  281 files, 14 changed. Six go from `Valid` to `Invalid`: the three
  Truepic files, `adobe-20260304-photoshop-remote-manifest.jpg` and
  `webp/length-differs.webp` with `assertion.dataHash.mismatch` (*"data hash
  exclusion does not match the manifest location in the asset"*), and
  `profile/eku-c2pa.png` with `signingCredential.invalid`. One goes from
  `Invalid` to `Valid` (`update-manifest/two-parents.png`, where
  `manifest.multipleParents` is gone). Two get no JSON (`absence/`). Four
  keep their state with new failure codes, and one gains
  `cawg.x509.credential.untrusted`.
- Reasoned: when 0.92.0 removes `trust_anchors`, `c2patool` will probably
  drop it as silently as it drops `allowed_list` now. Not measured.

## API sketch

Illustrative. `TrustSettings` stays `final readonly`. What a caller reads
today keeps its meaning.

```php
// namespace Provemark\C2paVerifier\Trust;

final readonly class TrustSettings
{
    /**
     * @param  list<Certificate>     $trustAnchors  the legacy string's anchors (unchanged meaning)
     * @param  list<Certificate>     $allowedList   the legacy loose list: always [] now (AC4 refuses it);
     *                                              kept so that the property does not disappear
     * @param  list<string>          $trustConfig   the top-level EKUs (unchanged meaning)
     * @param  list<TrustAnchorSet>  $anchorSets    new: one per trust.anchors entry, each with its kind,
     *                                              its anchors, its allowed_list and its own EKUs (AC6, AC8)
     */
    public function __construct(
        public array $trustAnchors,
        public array $allowedList,
        public array $trustConfig = [],
        public bool $verifyTrust = true,
        public array $anchorSets = [],
    ) {}
}

// new, final readonly: kind (an enum: Manifest, Tsa, Cawg), list<Certificate> $anchors,
// list<Certificate> $allowedList (Manifest only; AC6), list<string> $trustConfig, ?string $uri
```

## Open questions

1. **Does `trust_kind` separate signers from time-stamping authorities?**
   **Answered by Maurice van Loon, 2026-09-24: yes, every anchor counts
   only for its own kind** (AC6).
   - `"manifest"` entries anchor signers only.
   - `"tsa"` entries anchor time-stamping authorities only.
   - `"cawg"` entries anchor nothing.
   - The legacy `trust.trust_anchors` anchors both, so every existing
     settings file keeps its meaning.

   Why:
   - Measured: `c2patool` 0.28.0 ignores the kind. A `"tsa"` or `"cawg"`
     entry trusts a signer (N3, N4), and a `"manifest"` entry trusts a TSA
     (T2).
   - Read: `c2pa` 0.91.0 documents the `"cawg"` list as the Mozilla root
     store with the S/MIME trust bit. Copying `c2patool` would let an
     ordinary S/MIME certificate from any of those CAs sign C2PA content as
     `Trusted`, because E-mail Protection is an EKU the default list
     accepts. That last step is reasoned, not measured.
   - Measured: the C2PA publishes separate signer and TSA lists
     (`C2PA-TRUST-LIST`, 30 services; `C2PA-TSA-TRUST-LIST`, 22; step 107).
   - Read: C2PA 2.4 §14.4 describes separate lists.
   - Read: `c2pa` 0.91.0 has per-kind filters (`signing_trust_anchors()`,
     `tsa_trust_anchors()`, `cawg_trust_anchors()`), and its OCSP check
     uses the signing one, but its chain check walks every anchor. That
     reads as unfinished, not as a rule to copy.

   What it costs:
   - An operator who puts every root in a single `"manifest"` entry gets
     `timeStamp.untrusted` here where `c2patool` says `timeStamp.trusted`.
     The signer is then judged at *now*, and an old file can become
     `Invalid` (expired) where `c2patool` says `Trusted`. That is the
     fail-closed direction, and the explanation names the entry's kind.
   - Declined: letting `"manifest"` entries anchor TSAs as well. It would
     avoid that divergence at low risk, since a TSA also needs the
     `timeStamping` EKU. It was declined so that the rule stays one
     sentence and mirrors the official lists.
2. **Per-entry `trust_config`.** **Answered by measurement, step 110
   (AC8).** An entry's EKUs are the built-in list, plus the top level, plus
   its own, and they never spill over to another entry. This is `c2patool`
   0.28.0's behaviour, and it matches §14.4.1's model (anchor
   configurations per EKU). The earlier proposal, one union across all
   entries, was wrong: it would have called E5 `Trusted` where the oracle
   and §14.4.1 say `Invalid`.
3. **After 0.92.0.** *(not a blocker)* Keep reading `trust.trust_anchors`
   when `c2patool` no longer does? Proposal: yes. Refusing it would break
   every existing settings file, the sister repository's
   `certs/c2pa-trust.settings.json` included. The day `c2patool` drops it,
   `docs/comparison.md` names the difference.
4. **The maximum number of entries.** *(not a blocker)* Proposal: 32. The
   official list's JSON has 30 signer services, but a settings file bundles
   them into one PEM string, so a realistic file holds a handful of
   entries.
5. **The legacy string counts for both sides, which §14.4.2 does not
   want.** *(for the maintainer; not a blocker)* §14.4.2 says the TSA
   anchors *"shall be separate"*. A single legacy `trust.trust_anchors`
   string serves both today, here and in `c2patool`. Proposal: keep it,
   as AC2 and AC6 say. Otherwise every existing settings file loses
   `timeStamp.trusted`. Name it in `docs/comparison.md` as a departure
   kept for compatibility; the new shape is the conformant way.
6. **Today's code already lets the loose allowed list trust a TSA.**
   *(for the maintainer; out of scope here, its own step)*
   `TimestampCheck::tsaSettings()` passes `$operator->allowedList` to the
   TSA chain check (`src/Timestamp/TimestampCheck.php:223`), which
   §14.4.3 forbids. That is read from the code and not yet measured. AC4
   makes it unreachable for settings files, because a loose `allowed_list`
   is refused. A caller who builds `TrustSettings` in PHP could still
   reach it.
7. **The sister repository's settings file** has no `allowed_list` (read
   2026-09-24), so AC4 does not refuse it. Nothing to decide; recorded so
   that nobody has to look again.

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
