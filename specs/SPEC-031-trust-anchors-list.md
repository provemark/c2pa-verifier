# SPEC-031: the `trust.anchors` list, and a loose `allowed_list` refused

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
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
the lists are for: C2PA 2.4 §14.4, *Trust lists*. §14.4.1, *C2PA Signers*,
is the number `c2pa-rs` quotes from 2.3 and still has to be checked
against 2.4. It asks a validator to keep the C2PA trust list and a list of
additional anchors for signers, separately from the list for time-stamping
authorities.

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

- **AC6 — what `trust_kind` means for a signer** *(depends on open question 1)*
  - Given `trust.anchors` holding only the test roots, as `"tsa"`, and
    separately as `"cawg"`
  - When `fixture-signed.jpg` is verified
  - Then, as proposed, `signingCredential.untrusted` and `Valid`: only
    `"manifest"` entries and the legacy `trust_anchors` can make a signer
    trusted. This is **stricter than `c2patool` 0.28.0**, which gives
    `Trusted` for both (probes N3, N4). The difference is named in
    `docs/comparison.md`.

- **AC7 — the drift alarm learns the new shape**
  - Given the corpus runs that already use `full.settings.json` and
    `full-plus-digicert-g4.settings.json`
  - When they are also run with their `trust.anchors` twins
  - Then every report is identical to the old shape's.

## References

- Specification: C2PA 2.4 §14.4 *Trust lists*. The section number of
  §14.4.1 *C2PA Signers* comes from the 2.3 text `c2pa-rs` quotes and is to
  be checked against 2.4 before approval.
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
     * @param  list<Certificate>  $trustAnchors  every certificate that may anchor a signer:
     *                                           the legacy string plus every "manifest" entry
     * @param  list<Certificate>  $allowedList   every entry's allowed_list, together
     * @param  list<string>       $trustConfig   top-level plus every entry's, together (open question 2)
     * @param  list<Certificate>  $tsaAnchors    new: every "tsa" entry (open question 1)
     */
    public function __construct(
        public array $trustAnchors,
        public array $allowedList,
        public array $trustConfig = [],
        public bool $verifyTrust = true,
        public array $tsaAnchors = [],
    ) {}
}
```

## Open questions

1. **Does `trust_kind` separate signers from time-stamping authorities?**
   *(blocker)*
   - Measured: `c2patool` 0.28.0 ignores it. A `"tsa"` or `"cawg"` entry
     trusts a signer (N3, N4), and a `"manifest"` entry trusts a TSA (T2).
   - Proposal: separate them as §14.4 describes. `"manifest"` entries and
     the legacy string anchor signers. `"tsa"` entries, the legacy string
     and `"manifest"` entries anchor TSAs; the last is today's behaviour,
     kept so that no existing file loses `timeStamp.trusted`. `"cawg"`
     entries anchor nothing.
   - The alternative is to copy `c2patool`: every entry anchors everything.
     That is simpler, but it puts a trust in the operator's hands that the
     operator did not configure.
2. **Per-entry `trust_config`.** *(not a blocker)* In `c2pa` 0.91.0 it is
   documented to *"overlay the default top level trust_config"*. On the
   test leaf no probe could separate the two, because that leaf's EKU is
   already in the built-in list (N6, N7, N13 all `Trusted`). Proposal:
   union, like the top level today (ADR-0003 item 4), and a fixture with a
   leaf whose EKU is not built in before approval.
3. **After 0.92.0.** *(not a blocker)* Keep reading `trust.trust_anchors`
   when `c2patool` no longer does? Proposal: yes. Refusing it would break
   every existing settings file, the sister repository's
   `certs/c2pa-trust.settings.json` included. The day `c2patool` drops it,
   `docs/comparison.md` names the difference.
4. **The maximum number of entries.** *(not a blocker)* Proposal: 32. The
   official list's JSON has 30 signer services, but a settings file bundles
   them into one PEM string, so a realistic file holds a handful of
   entries.
5. **The sister repository's settings file** has no `allowed_list` (read
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
