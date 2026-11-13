# Step 535 — The corpus in crJSON against the conformance tool's setup

*2026-11-13.* The first step toward the Drupal module's application to the
C2PA Conformance Program. Before any code, this step measures how far
this verifier's crJSON is from what the programme's own tool produces
(Maurice: "begin met de meting tegen de conformance-tool"; recorded at
"documenteer de meting als stap in de verifier-notities"). No code.

## What the conformance tool is (read)

`contentauth/c2pa-conformance-tool` at `a5f9cd7` (2026-11-03,
Apache-2.0). It is a Svelte web app around `c2pa-rs` compiled to WASM.

- **The engine:** its `wasm/src/lib.rs` calls `Reader::from_context(…)`
  and returns `reader.crjson()`.
- **The pinned version:** the `c2pa-rs` submodule is at `b62499d`
  (2026-11-01, workspace version `0.92.0-dev`).
- **Its settings** (`src/lib/c2pa.ts`):
  - `verify_after_reading` and `verify_trust` on, `remote_manifest_fetch`
    off;
  - `ocsp_fetch` and `ocsp_fetch_should_override` on, through its own
    proxy;
  - `trust_config` set to six EKUs: emailProtection, documentSigning,
    timeStamping, OCSPSigning, Microsoft C2PA signing and C2PA claim
    signing;
  - `trust.anchors` holding the official C2PA list (`manifest`) and TSA
    list (`tsa`), fetched from `conformance-public` `main`.
- **Test mode** adds its embedded "C2PA Conformance Test Root" as both a
  `manifest` and a `tsa` anchor.
- **Fallbacks:** an ITL fallback and soft-binding registry, used only when
  the official lists leave a file untrusted. They are not reproduced here.

Its test root is byte for byte this repository's
`tests/Fixtures/conformance-test/test-root.pem` (compared as DER).

## What was run

- **`c2patool` v0.29.0** (2026-11-12, `c2pa` 0.92.0), the nearest
  release to the tool's engine. It was downloaded at Maurice's approval
  as `v0.29.0-universal-apple-darwin.zip`, SHA-256 `cf093fdf…8ea48`,
  equal to the digest GitHub publishes. It sits in
  `C2PA_Content_Credentials/tools/c2patool-0.29.0/`.
- **One trust section for both sides**, the tool's test mode:
  - the official `C2PA-TRUST-LIST.pem` (33 certificates) and
    `C2PA-TSA-TRUST-LIST.pem` (24) at `conformance-public` `87190b2`
    (2026-11-12);
  - the test root as a `manifest` and as a `tsa` anchor;
  - the tool's `trust_config`.

  `c2patool`'s settings add the tool's `verify` keys with `ocsp_fetch`
  off. That is the one difference from the tool, because this verifier
  never goes to the network. The verifier's settings carry
  `verify_trust` only.
- **The corpus:** every media file under `tests/Fixtures/` (930), through
  `c2patool --crjson` and `bin/c2pa-verify --crjson`, eight at a time,
  with no validation time (the clock, on both sides).
- **The verdict** is read from the active manifest's crJSON alone, as a
  harness reader would:
  - any `failure` makes it `Invalid`;
  - otherwise `signingCredential.trusted` makes it `Trusted`;
  - otherwise it is `Valid`;
  - no manifests means "none", and no output means "error".

  Failure codes were then compared per manifest label.

## Measured

| `c2patool` 0.29.0 | this verifier | files |
|---|---|---|
| `Invalid` | `Invalid` | 642 |
| `Trusted` | `Trusted` | 5 |
| `Invalid` | none (`manifests: []`) | 57 |
| error, no output | `Invalid` | 61 |
| error, no output | none | 165 |

**No file gets a different verdict where both sides write a manifest
(647 of 647).** There is no `Valid` on either side: under the official
lists almost every fixture is `signingCredential.untrusted`, which crJSON
puts under `failure`. The five `Trusted` files are the test-root fixtures
(SPEC-078).

### The 57: a refused store reads as no credentials

Where this verifier refuses the container, it writes `manifests: []` and
puts the failure on standard error. Its exit status is 1 on all 57, so
the report says `Invalid`. This is SPEC-075 AC8 as specified, and its
open question 2 settled it so in step 375. Examples:

- `avi/lbox-differs.avi`: "LBox 13464 differs from the chunk length";
- `avi/pad-nonzero.avi`: "pad byte at offset 25171 is FF";
- `avi/riff-size-plus-one.avi`: "RIFF size 25165 in the header".

`c2patool` reads those stores anyway and reports per manifest, for
instance `assertion.dataHash.mismatch` and `signingCredential.untrusted`.
The files, by folder: `mp3` 7, `wav` 7, `jumbf` 6, `webp` 6, `avi` 5,
`claim` 4, `numeric-label` 4, `manifest-probes` 3, and the rest 2 or
fewer.

A harness that reads crJSON only, which is what the programme's harness
is, would read these 57 as "no Content Credentials", not as "does not
verify". This is the one finding of this step that bears on an
application.

### The 61: `c2patool` gives nothing, this verifier says `Invalid`

`c2patool` exits with an error and writes no crJSON. The errors, by
count:

| error | files |
|---|---|
| "unknown algorithm" | 7 |
| "claim missing hard binding" | 6 |
| "could not decode assertion c2pa.hash.data" | 5 |
| "could not generate a trusted time stamp" | 5 |
| "could not decode assertion c2pa.hash.boxes" | 4 |
| "could not decode assertion c2pa.ingredient.v3" | 4 |

This verifier answers each with a manifest and a failure code.

### Codes on the same verdict

In 162 of the 647 files the failure codes differ in a manifest both
sides read. In every case both sides read the same set of manifest
labels.

The pattern: `c2pa-rs` stops at `signingCredential.untrusted`, while
this verifier reports what else is wrong. In each table, "files" counts
manifests, one file each.

| only this verifier | files | example |
|---|---|---|
| `signingCredential.expired` | 30 | `box-hash/bing-after-eoi.jpg` |
| `signingCredential.invalid` | 23 | `chain-constraints/pss-sha1-leaf.png` |
| `assertion.bmffHash.mismatch` | 13 | `bmff-exclusion-coverage/mdat-excluded-edited.mp4` |
| `assertion.dataHash.mismatch` | 10 | `binding/exclusion-extra.png` |
| `general.error` | 6 | `c2pa-rs/C_with_CAWG_data.jpg` |
| `signingCredential.ocsp.revoked` | 6 | `ingredient-staple/child-staple-revoked.png` (an ingredient) |
| `assertion.alternativeContentRepresentation.malformed` | 5 | `manifest-probes/acr-both.png` |
| 16 other codes | 1–3 each | |

| only `c2patool` | files | example |
|---|---|---|
| `assertion.dataHash.mismatch` | 10 | `binding/alg-sha1.png` (twice; here only `assertion.hashedURI.mismatch`) |
| `signingCredential.untrusted` | 9 | `chain-constraints/x5chain-both.png` (here: `signingCredential.invalid` three times) |
| `claimSignature.mismatch` | 6 | `cose/alg-eddsa-with-ec-key.png` |
| `ingredient.manifest.missing` | 3 | `c2pa-rs/adobe-20220124-E-clm-CAICAI.jpg` |
| `assertion.required.missing` | 3 | `cawg/cawg-ingredient-instance.png` |
| 8 other codes | 1–2 each | |

How often each code is named in `docs/comparison.md`, counted with
`grep -c`:

| code | mentions |
|---|---|
| `signingCredential.invalid` | 17 |
| `general.error` | 16 |
| `assertion.bmffHash.mismatch` | 7 |
| `assertion.dataHash.mismatch` | 6 |
| `signingCredential.expired` | 3 |
| `claimSignature.mismatch` | 2 |
| `signingCredential.ocsp.revoked` | 1 |
| `assertion.required.missing` | 1 |
| `assertion.alternativeContentRepresentation` | 0 |
| `ingredient.manifest.missing` | 0 |

A mention is not proof that this file's difference is the one described.
That was not checked row by row.

## Reasoned, not measured

- **The 57 are the gap that matters for the programme.** Its expected
  results are not public, but they come from a tool built on `c2pa-rs`,
  which reports these files per manifest. SPEC-075 open question 2 chose
  `manifests: []` against `c2patool`'s silence. That was right for a
  missing store, but it covers a refused one too. Whether a refused
  store should instead write the manifests it could read, with the
  refusal as a failure, is a question for an amendment to SPEC-075, not
  for this note.
- **The extra codes are unlikely to fail a comparison of verdicts** but
  could fail a comparison of codes. Which of the two the programme does
  is not public.
- **The 61 are in this verifier's favour** for a harness: an answer
  rather than none.

## Not done

- No ITL or soft-binding fallback, and no live OCSP: the tool does all
  three.
- No validation time on either side. The programme's harness gives one.
- crJSON schema validity of this verifier's output was not re-measured
  here (SPEC-076 covers it).
- The scratch material (settings, per-file outputs, the comparison script)
  is in the session scratchpad and not kept in the repository.
