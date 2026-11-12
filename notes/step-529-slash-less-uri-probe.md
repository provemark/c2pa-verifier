# Step 529 — Box hash and `brob` in the corpus, and the slash-less URI probe

*2026-11-12.* Two questions from Maurice, in order:
- "meet hoe vaak box hash en brob in het corpus voorkomen";
- "schrijf een draft spec voor SPEC-042".

This step records what the draft rests on. No code in `src/` changed, and
no test or fixture either.

## 1. How often the corpus uses `c2pa.hash.boxes` and `brob`

A byte search over all 3,087 files under `tests/Fixtures/` (the 953-file
corpus included), for the label `c2pa.hash.boxes` and the box type `brob`.

| what | files | of which from a real writer |
|---|---|---|
| `c2pa.hash.boxes` | `writers/microsoft-20260609-bing-fast-heartbeat.jpg`, `hard-binding-redacted/hash-boxes-relative.png`, `binding/hard-binding-boxes.{png,bin}` | **one**: Bing Image Creator. The other two are this project's own variants (SPEC-035, SPEC-012 AC13) |
| `brob` | `jumbf/brob.{png,bin}` | **none**: the claim's `cbor` TBox relabelled; `c2patool` says *"claim cbor box not valid"* |

The hits in `.json` and `.md` files (oracle output, READMEs, the crJSON
schema) are left out.

- **The box hash.** `c2patool`'s oracle for the Bing file reports
  `assertion.boxesHash.match`, so the box hash is that file's hard binding.
  Step 141 measured nine Bing files on Commons, all written the same way.
  One mass-market writer uses it, and no other writer in the corpus does.
- **`brob`.** Outside the corpus, `tools/c2pa-rs-src/sdk/tests/fixtures/sample1.jxl`
  contains the bytes `brob`. JPEG XL also uses `brob` for compressed
  metadata, and whether a manifest is involved there was not checked. This
  verifier does not read JPEG XL.

## 2. Slash-less URIs in the corpus

A byte search for `self#jumbf=c2pa/` over the same files finds two binaries:
- `writers/microsoft-20260609-bing-fast-heartbeat.jpg`;
- `c2pa-rs/prerelease.jpg` (label `adobetest:urn:uuid:…`; both `c2patool`
  versions refuse it with *"Prerelease claim found"*).

No other file can be moved by reading that form as absolute.

## 3. The probe

The probe ran on a scratch copy outside this repository: `src/`, `bin/`,
`vendor/` copied with `rsync` (not symlinked, so `vendor/autoload.php`
loads the copy's `src/`, checked through reflection). One line was added at
the top of `Manifest::resolve()`, as `c2pa-rs`'s `to_normalized_uri` does
(`ada3e4a`, step 143):

```php
if (str_starts_with($path, 'c2pa/')) { $path = '/'.$path; }
```

`bin/c2pa-verify` without trust settings:

| file | today | probe |
|---|---|---|
| Bing, at now | `Invalid`: `claimSignature.missing` | `Invalid`. Successes: `claimSignature.validated`, `claimSignature.insideValidity`, `timeStamp.validated`, `assertion.hashedURI.match` for `c2pa.hash.boxes` and `c2pa.actions`. Failures: `signingCredential.expired` (leaf expired 2026-10-01), `signingCredential.untrusted`, `general.error` on `…/c2pa.assertions/c2pa.hash.boxes` (*"the hard binding c2pa.hash.boxes is not supported yet"*) |
| Bing, `--at 2026-07-01T00:00:00Z` | `Invalid`: `claimSignature.missing` | `Invalid`: `signingCredential.untrusted`, `general.error` on the box hash |
| `prerelease.jpg` | `Invalid`: `claimSignature.missing` | `Invalid`: `general.error` (*"the signature box is not valid CBOR: 5134 byte(s) remain after the value"*) and eight `assertion.hashedURI.mismatch` |

For comparison, `c2patool` on the Bing file (recorded oracles):
- 0.27.22: `Valid`, with `assertion.boxesHash.match` and
  `signingCredential.untrusted`;
- 0.28.x: `Invalid` on `signingCredential.invalid` (the EKU, step 112).

## What it means

- **No verdict moves.** The Bing file gets past the signature and stops at
  the box-hash refusal. That is the only remaining difference from
  `c2patool` 0.27.22, apart from the EKU and trust questions it shares with
  0.28.x. `prerelease.jpg` stays refused, for other reasons than
  `c2patool`'s.
- **The status url differs.** Under the probe, `assertion.hashedURI.*`
  carries the absolute form (`self#jumbf=/c2pa/…`), because
  `HashedUriCheck` builds it from the manifest label. `c2patool` writes the
  url as written, without the slash. This is SPEC-042 open question 1.
- **Read in the code, not probed:**
  - `Manifest::absoluteUri()` would turn a slash-less URI into
    `self#jumbf=/c2pa/<own label>/c2pa/<label>/…`;
  - SPEC-040's `assertion.outsideManifest` check matches only
    `self#jumbf=/c2pa/`, so a slash-less entry naming another manifest
    would get `assertion.missing` from `resolve()`;
  - `redactionsOf()` keeps only slashed entries.

  The probe changed `resolve()` alone, so none of these was exercised. No
  corpus file has the first two cases.

## What changed

- `specs/SPEC-042-jumbf-uri-without-leading-slash.md`, a draft resting on
  this step. Its measurements are the ones above, and its "NOTES step to
  follow" now reads step 529.
- No code, no tests, no fixtures. The scratch copy is not kept.
