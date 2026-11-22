# Step 549 — The corpus against `c2patool` 0.29.0, and the C2PA 2.5 table

*2026-11-22.* `c2patool` 0.29.0 (`c2pa` 0.92.0) was released on
2026-11-12. On 2026-11-10 Maurice decided the next round waits for this
stable version (step 521 measured 0.28.6). Step 535 used 0.29.0 only for
crJSON. This step measures the ordinary report, and checks each row of
*"C2PA 2.5: what waits for `c2pa-rs`"* in `docs/milestones.md` against
`c2pa` 0.92.0. Maurice: "ja, werk die stap uit", then "akkoord, samen".

No code and no tests change.

## What was run

- Both binaries were already local:
  - `c2patool` 0.28.6, from step 521;
  - `c2patool` 0.29.0, from step 535, whose zip SHA-256 equals GitHub's digest.
- Both versions ran on every media file under `tests/Fixtures/`: 930 files
  (png, jpg, mp4, mp3, webp, wav, gif, avi, m4s, flac, mov, heic, avif).
  Each file ran without settings and with
  `tests/Fixtures/trust/full.settings.json`: 3,720 runs, eight at a time.
- Per run, the comparison recorded:
  - the exit code;
  - `validation_state`;
  - every status code under `validation_results` and `validation_status`,
    with its url, sorted.
- Each file whose state moved also went through `bin/c2pa-verify` with the
  same settings.
- The probes whose meaning depends on their own throw-away root were run
  again under that root, in all three implementations:
  - `chain-constraints/` under its `root.settings.json`;
  - `revocation/` under its `throw-away-root.settings.json`.
- `c2pa-rs` was read at tags `c2pa-v0.91.11` and `c2pa-v0.92.0`: the
  commit log for `sdk/src` (50 commits), and the source of both tags,
  searched and diffed.
- The C2PA 2.5 text was read at
  <https://spec.c2pa.org/specifications/specifications/2.5/specs/C2PA_Specification.html>
  (§13.2, §13.5, Appendix H.2, and the time-stamp validation steps).

**A pitfall, measured.** The first run passed `trust/full.settings.json`,
a path relative to the wrong folder. Both `c2patool` versions exit 0
with an empty standard error when the settings file does not exist.
`--settings /nonexistent/x.json` gives exit 0 as well. That run measured
no trust at all, and no output showed it. The trust half was run again
with the right path, and its totals match step 521 (74 `Trusted`).
`bin/c2pa-verify` exits 2 on an unreadable settings file.

## Measured: the corpus

| | 0.28.6 | 0.29.0 |
|---|---|---|
| without settings | 378 `Valid`, 328 `Invalid`, 224 no report | 396 `Valid`, 312 `Invalid`, 222 no report |
| with `full.settings.json` | 74 `Trusted`, 307 `Valid`, 325 `Invalid`, 224 no report | 74 `Trusted`, 322 `Valid`, 312 `Invalid`, 222 no report |

The report differs on 31 files without settings and 25 with them. The
state moved on 20 files.

**No file is `Valid` or `Trusted` here where 0.29.0 says `Invalid` or gives
no report.** Every move except one makes `c2patool` more lenient. The
moves fall into four groups, each traced to one upstream change.

### 1. The C2PA EKU (`c2pa` #2854, #2755)

The files are `profile/eku-c2pa.png`, `conformance-test/signed.{jpg,png,webp}`
and `writers/microsoft-20260609-bing-fast-heartbeat.jpg`. According to
`tests/Fixtures/profile/README.md`, the leaf of `eku-c2pa` carries only the
C2PA EKU. The other leaves get the same message from 0.28.6; their EKUs
were not read in this step.

- **0.28.6:** `signingCredential.invalid`, *"certificate missing required
  EKU"*, and the file is `Invalid`.
- **0.29.0:** the C2PA EKU is always accepted. Upstream's commit cites the
  Trust Model for this; that text was not read here. The file the file is `Valid` (signer untrusted).
- **This verifier:** `Valid` on the first four, already before 0.29.0, so
  `c2patool` now agrees with it.
- **Bing:** `Invalid` here with `signingCredential.expired`. The signer
  expired on 2026-10-01.
  - 0.29.0 judges the signer at the time-stamp, because its DigiCert TSA
    is *"legacy timestamp cert trusted"* without any settings.
  - This verifier has no TSA anchor without settings, so it judges at the
    current time.
  - This is the known difference behind SPEC-086 AC6. It is stricter here,
    and it is not new.

### 2. A TSA certificate off the profile (`c2pa` #3031)

The files are the eleven probes of `timestamp/tsa-matrix/` and
`timestamp/tsa-profile/` whose TSA leaf breaks the profile: a CA leaf,
no or a wrong EKU, no key usage, SHA-1.

- **0.28.6:** `signingCredential.invalid` (*"expected end-entity
  certificate"* and the like), and the file is `Invalid`.
- **0.29.0:** the same fault is `timeStamp.untrusted` (informational), the
  time-stamp is ignored, and the file is `Valid`.
- **This verifier:** `Invalid` with `signingCredential.invalid`
  (SPEC-017 AC14, amendment 8, which followed `c2patool` 0.28.1).
- **What C2PA 2.5 says:** the time-stamp validation steps say a TSA
  certificate that is invalid or untrusted gives `timeStamp.untrusted`
  (optionally `timeStamp.credentialInvalid`). The validator then proceeds
  as if no time-stamp were present.
- **So this verifier is now stricter than both `c2patool` and the
  specification text.** That is a wrong `Invalid`, never a wrong `Valid`.

### 3. Where the chain may be (`c2pa` #2895)

Upstream's commit message names *"C2PA 2.5 x5chain label precedence"*.
`cert_chain_from_sign1` now accepts label 33 and `"x5chain"` in both
headers. Under each probe's own root:

| file (SPEC-047) | 0.28.6 | 0.29.0 | here |
|---|---|---|---|
| `x5chain-unprotected`: label 33, unprotected | no report | `Trusted` | `Invalid` (AC1) |
| `x5chain-both`: protected 33 and unprotected `"x5chain"` | no report | `Trusted`, the protected chain | `Invalid` (AC2) |
| `x5chain-swapped`: label 33, unprotected, swapped after signing for a certificate of the same key naming `O=Adobe Inc` | no report | **`Trusted`, issuer "Adobe Inc"** | `Invalid` |
| `x5chain-text-unprotected` | `Trusted` | `Trusted` | `Invalid` (AC3) |
| `x5chain-text-swapped` | `Trusted`, "Adobe Inc" | `Trusted`, "Adobe Inc" | `Invalid` (AC3) |
| `manifest-probes/x5chain-unprotected-too`: the same chain under 33 in both headers | `Trusted` | no report | `Invalid` (SPEC-047 am. 2) |

Without settings, the first three moved from no report to `Valid` and the
last from `Valid` to no report: the one move that makes `c2patool`
stricter, and towards what this verifier says.

C2PA 2.5 §13.2 says the credential shall be in the protected header. It
also says exactly one credential may appear in the union of both headers,
except the same chain under both labels. §13.5 says a validator shall
accept either label, and use label 33 when both are present. The text
does not say a validator shall refuse an unprotected chain.

The swap that 0.27.22 already allowed under `"x5chain"` (SPEC-047 AC3,
named in `docs/comparison.md`) now also works under label 33. This
verifier refuses both: it is stricter, and the protected-header rule
stands behind it.

### 4. A revocation staple signed by the issuer (`c2pa` #3019)

- **Without settings:** `revocation/cs-own.png` loses
  `signingCredential.ocsp.skipped`.
- **Under its own root:** `cs-own.png` moves from `Trusted` (0.28.6) to
  `Invalid` (0.29.0). That is what this verifier says (SPEC-066).
- The other revocation probes stay `Trusted` in 0.29.0. Here `cs-revoked`
  and `cs-two` stay `Invalid`, and `ca-revoked` stays `Valid`: stricter,
  as before.

### Code moves on files whose state stays

- `signingCredential.invalid` (*"certificate missing required EKU"*) is
  also gone on eight files that stay `Invalid` for another reason, so
  group 1's change reaches further than its five moves:
  - five tampered variants under `box-hash/` (Bing's signer);
  - `conformance-test/edited.{jpg,png,webp}`, without settings.
- `claimSignature.validated` appears in 48 runs on 27 files:
  - every file of groups 1 to 3 that lost its refusal or now gives a
    report;
  - the eight files above.

  0.28.6 omits the code when the credential is refused (#2984). With the
  refusal gone, the code is reported. This follows from those groups and
  is not a separate change.
- `assertion.action.malformed` is added on
  `actions-rules/created-then-opened.jpg` and
  `actions-rules/edited-then-created.jpg`. Both stay `Invalid`. This
  verifier already reports that code on both.

## Measured and read: the C2PA 2.5 table against `c2pa` 0.92.0

Searched in the source of both tags, outside tests:

| 2.5 row | in `c2pa` 0.92.0 | outcome |
|---|---|---|
| §12.3.1 crossed curve refused | as 0.91.x | unchanged; already refused here |
| §12.3.1 ESP256/384/512, ML-DSA-44/65/87 | no mention (`Esp256`, `MlDsa`, `ML-DSA`: 0 hits) | not applied; keeps waiting |
| §14.10.4.2.4 a redacted ingredient as the target of `opened` and others | `claim_verify.rs` identical to 0.91.11; `actions.rs` differs in comments only | not applied; keeps waiting |
| §14.10.4.2.4 `when` with `whenStart`/`whenEnd` | no mention (0 hits) | not applied; keeps waiting |
| §14.10.4.2.4 `c2pa.deleted` and `componentOf` | no validation found; the one 0.91.11 hit was a region-of-interest enum | not applied; keeps waiting |
| §14.10.4.2.4 `assertion.action.ingredientLocationMismatch` | no such code | not applied; keeps waiting |
| §14.10.4.2.4 `relatedAssertions` to a redacted assertion | only `assertion.action.redacted`, as before | not applied; keeps waiting |
| §14.11.3.2 an ingredient's own `relatedAssertions` | `ingredient.rs` differs in comments only | not applied; keeps waiting |
| §13.4.2 TSA anchor dates | no validity check on the anchor found; the only validity check in `time_stamp/verify.rs` is the TSA signer's | not applied, as far as the search shows |
| **new: §13.2 / §13.5 the chain's label and header** | applied (#2895, group 3 above) | a row added; this verifier stays stricter |

A row marked *not applied* rests on searching and diffing the source, not
on a probe. None of these rows has a corpus file that would move.

## What it means (reasoned)

- **No wrong `Valid` and no wrong `Trusted` appeared.** Wherever 0.29.0
  disagrees with this verifier, this verifier is the stricter one.
- **Group 2 is the candidate that counts.** This verifier calls eleven
  probes `Invalid` where `c2patool` 0.29.0 and the C2PA 2.5 text both say
  the time-stamp should be ignored, and the file judged without it.
  - Following them would be a SPEC-017 amendment (AC14).
  - It would make the verifier more lenient, so the amendment needs its
    own probes first: a TSA off the profile with an expired signer must
    still be `Invalid`, because the time-stamp no longer rescues it.
- **Group 3 stays a deliberate difference.** SPEC-047 refused an
  unprotected chain in a claim v2 on purpose (option A, 2026-09-27).
  0.29.0 widens the swap `c2patool` allows.
  - Whether to tell upstream is Maurice's decision. Nothing about it is
    posted.
  - `docs/comparison.md` names the `"x5chain"` swap but not yet the label
    33 form; that row waits for the follow-up.
- **Groups 1 and 4 bring `c2patool` to what this verifier already says.**
  SPEC-015's EKU difference and SPEC-066's `cs-own` are now agreements.
- **The status-code moves change no verdict.** Whether to follow them in
  the code lists, and moving the drift alarms from 0.27.22 to 0.29.0, are
  the follow-ups decided on 2026-11-10. They are not done here.
- **Not covered by the corpus.** These upstream changes in 0.92.0 move no
  fixture, and that shows nothing about them:
  - RSA-PSS and SKI time-stamp signers (#3015);
  - an expired CA as `claimSignature.outsideValidity`;
  - `genTime` as the attested time;
  - an OCSP `thisUpdate` in the future;
  - the CAWG interim certificate policy.

## What changed

- This note, `NOTES.md`, and `docs/milestones.md`: the 2.5 table gains the
  §13.2/§13.5 row and the step.
- No code, no tests, no fixtures, no answer files.

## Next (proposals, each for Maurice's word)

1. SPEC-017 amendment: a TSA certificate off the profile is
   `timeStamp.untrusted`, the time-stamp ignored, as 2.5 and 0.29.0. It
   comes with probes, including an expired signer.
2. `docs/comparison.md`: the five SPEC-047 shapes under 0.29.0, and the
   EKU and `cs-own` rows now agreeing.
3. The drift alarms from 0.27.22 to 0.29.0, and the status-code moves one
   by one, as planned on 2026-11-10.
