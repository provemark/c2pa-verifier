# Step 367 — What the C2PA conformance programme asks of a validator

*2026-10-23.* Maurice wants to prepare this verifier, slowly, for the C2PA
Conformance Program, and at least pass its test set. This step finds out
what that test set is and what the verifier still lacks. No code.

## The programme's documents

Read at `c2pa-org/conformance-public` `da71a72`, `docs/v0.2/` (dated
2026-07-31; supports products on C2PA 2.2 and 2.4):

- *C2PA Conformance Program*, "Validator Product Specification
  Requirements": the specification holds "over 150 normative requirements"
  for validators, and the programme "will eventually require claims
  validators to demonstrate complete conformance" with them. The areas are
  a fully formed manifest, signature, timestamp, assertion and hash
  validation, and result reporting.
- The same document, "Validator Product Sample Evidence": an applicant
  supplies sample validation results, with their `.json` or `.crjson`, for
  every media type it says it validates. The programme recommends giving
  it access so it can feed in its own samples.
- *Additional Conformance Requirements*, "Support of crJSON format for
  evaluation of product with validation functionality" (all spec
  versions). This is the test set. An applicant **shall** have a test
  harness that takes four inputs:
  1. an asset to validate,
  2. a (test) C2PA Trust List,
  3. a (test) C2PA TSA Trust List,
  4. a validation time (RFC 3339),

  and produces the validation results in **crJSON**. "Applicants will be
  provided with a set of such test inputs": the assets come from the
  programme during the evaluation, after the legal agreement and the
  intake form. They are not published.
- The other additional requirements in that document (`specVersion` in
  `claim_generator_info`, `allActionsIncluded`, `digitalSourceType` on
  most actions and not on `c2pa.opened`) are obligations on **generator**
  products. A validator does not enforce them.
- A validator "SHALL regularly refresh the C2PA Trust List". That is the
  operator's job here: the verifier reads the settings it is given and
  never goes to the network.
- Applying costs nothing, but it requires a legal agreement with the C2PA.
  The programme accepts pre-release products.

## What is public, and what it is worth

| source | state, measured 2026-10-23 | worth |
|---|---|---|
| `c2pa-org/public-testfiles` | last commit `22beccc`, 2025-12-05; the `2.2/` tree still holds READMEs and `.gitkeep` only (`gh api …/git/trees/main?recursive=1`); `legacy/1.4` holds the 25 JPEGs this repository already has, byte-identical (step 85) | nothing new |
| `contentauth/c2pa-conformance-tool` | `6d10ee0`, 2026-10-14; c2pa-rs in WASM; reports in crJSON; the official CA and TSA lists; a test mode with a "C2PA Conformance Test Root" and a public test signing bundle (its `TEST_CERTIFICATES.md`); licence not stated as SPDX | an oracle for crJSON, and the closest public copy of the programme's test trust setup |
| `encypherai/c2pa-conformance-suite` | already mapped, `docs/conformance.md` (step 90) | the checklist of obligations |

So there is no public test set to run. What can be prepared is the
**harness** (the four inputs, crJSON out) and the **obligations**.

## The four inputs, against this verifier

Measured by reading `bin/c2pa-verify --help` and `src/`:

| input | here |
|---|---|
| an asset | yes |
| a C2PA Trust List | yes: `--settings`, a `"manifest"` entry under `trust.anchors` (`docs/trust-settings.md`) |
| a C2PA TSA Trust List | yes: a `"tsa"` entry under `trust.anchors`, kept apart from the signer list (SPEC-031; `ChainCheck::tsaAnchorsOf()`). A harness only has to put the two files into one settings document |
| a validation time | **no**. `Verifier::verify()` takes a stream and settings only. Without a trusted timestamp, `ChainCheck`, `OcspCheck` and `CertificateProfileCheck` each fall back to `time()` |
| output in crJSON | **no**. `c2pa-verify` prints this verifier's own JSON report |

## crJSON, measured once

The format page (C2PA 2.4, "Content Credentials JSON") describes a JSON-LD
view of the manifest store with its validation results, and embeds a JSON
Schema (`$id` `https://c2pa.org/crjson/crJSON.schema.json`). It is a
derived view: not an input, and not independently verifiable.

`c2patool` 0.28.2 (and 0.28.1) has `--crjson`. On
`tests/Fixtures/acknowledged-kind/recorded-failure.jpg`:

```
c2patool recorded-failure.jpg --crjson
```

- top level: `@context`, `manifests`, `jsonGenerator`
  (`{"name": "c2pa-rs", "version": "0.91.2"}`);
- each manifest: `label`, `isUpdateManifest`, `isCompressedManifest`,
  `assertions`, `claim.v2`, `signature`, `validationResults`,
  `ingredientDeltas`;
- `validationResults`: `success`, `informational`, `failure` (entries with
  `code`, `url`, `explanation`), `specVersion` (`"2.3.0"`) and
  `validationTime` (the current time).

Two things to check in a later step, not concluded here. First, whether
the two `is…Manifest` keys are allowed by the schema; the format page was
read through a summary only. Second, why `specVersion` says 2.3.0 under a
2.4 format page. There is also no `validation_state` key in this output:
the verdict seems to be carried by the status codes alone. That rests on
one file.

## The plan that follows

Each step gets its own explanation and approval, as always:

1. this note;
2. a validation time as input (CLI and API), with negative tests: an
   expired certificate whose outcome changes with the time given;
3. crJSON read and measured against `c2patool --crjson` and the
   conformance tool, before anything is written;
4. crJSON output, in two or three steps, next to the existing report so
   the contract does not break;
5. `bin/conformance-harness`: the four inputs in, crJSON out;
6. the test mode measured with the Conformance Test Root, per media type
   that would be declared;
7. the open gaps of `docs/conformance.md`, a wrong `Trusted` first;
8. only then, and only by Maurice's decision, applying.

The earlier proposal had a separate spec for the TSA list. This step's
reading shows it is not needed.

## Measured and reasoned

- Measured: the documents and repositories above at the commits named
  (`gh api`); `c2patool` 0.28.2 `--help` and `--crjson` on one fixture;
  `bin/c2pa-verify --help`.
- Reasoned: that the programme's test inputs are the test set, and that
  they are not public (from the wording "will be provided"); which of the
  verifier's parts serve which input (from reading `src/`).

## Addendum, the same day: who is on the list

Measured: `conforming-products/conforming-products-list.json` at
`c2pa-org/conformance-public` `da71a72` holds 273 records, all
`conformant`: 243 generator products and 30 validator products, 10 of
them under programme version 0.2. A search of the records for
`c2patool`, `c2pa-rs`, `contentauth`, `c2pa-web`, "Content Authenticity
Initiative" and "SDK" finds nothing. The CAI's page on the programme does
not name its SDK either.

Reasoned: the programme lists products, not libraries. `c2patool` and
`c2pa-rs` are building blocks; products built on them are listed, but a
record does not say which library a product uses. That makes `c2patool`
a reference here, not a certified answer: the norm is the
specification, and the divergences this verifier keeps on purpose
(`docs/comparison.md`) are the ones to explain when applying. The
programme's expected results are not public; its own conformance tool
runs on `c2pa-rs`.

This verifier, a library, would not be listed either. A product using it
would be. CMS and asset-management software is on the list as validator
products (Fotoware, `image/jpeg` and `image/png`, programme 0.1;
SitecoreAI DAM, C2PA 2.4, programme 0.2), and a record states
`supportsCompressedManifests`, which may be `false`: this verifier
refuses `brob` and `c2cm` boxes (`JumbfParser`), so it would be `false`.
Which product to put forward, and whether to apply at all, is Maurice's
decision and is left until the harness exists.
