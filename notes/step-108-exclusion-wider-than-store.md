# Step 108 — an exclusion wider than the store: a wrong `Trusted`

*2026-09-24. Measurement only: no specification, test or code changed.*

## Why this was measured

Step 107 found five corpus files that `c2patool` 0.28.0 turns from `Valid`
into `Invalid` with `assertion.dataHash.mismatch`, *"data hash exclusion
does not match the manifest location in the asset"*. If 0.28.0 is right
about them, this verifier says `Valid` where it should not. That is the
one risk this project ranks above all others. So the five were examined
before anything else in 0.28.0.

## What changed in `c2pa-rs`

`c2pa` 0.91.0, `sdk/src/claim.rs`, `data_hash_exclusions_match_manifest()`
(read):

- if no exclusion has a length above zero, the file passes (the remote or
  sidecar placeholder);
- if the store was found in the asset, **some exclusion must equal the
  store's range exactly** (`exclusions.contains(range)`);
- if the store was not found in the asset, the file passes only when the
  manifest was read from it (`is_embedded`, upstream #2643).

The comment above the function cites C2PA §15.12: *"the exclusion range
containing the manifest store must hold only the manifest store and
padding"*. The function is not in `c2pa` 0.90.22.

## The five files

| file | this verifier (no settings) | reason | wrong `Valid` here? |
|---|---|---|---|
| `webp/length-differs.webp` | `Invalid`, `general.error` | refused at extraction since SPEC-003 (stricter than 0.27.22) | no; 0.28.0 now agrees on the state |
| `writers/adobe-20260304-photoshop-remote-manifest.jpg` | `Invalid` | no embedded store; the remote manifest is reported and never fetched | no |
| `public-testfiles/truepic-20230212-camera.jpg` | `Invalid` without settings (`signingCredential.expired`/`.untrusted`), **`Trusted` with `truepic-root.settings.json`** | the data hash matches | **yes** |
| `…-landscape.jpg`, `…-library.jpg` | the same | the same | **yes** |

## What the Truepic exclusion leaves unhashed

`truepic-20230212-camera.jpg`, measured with a segment walk and this
verifier's own extractor:

```
      0 SOI
      2 FFE1 APP1 "Exif"   length 13613
  13617 FFEB APP11 JUMBF   (store piece, three segments)
 206316 FFDB DQT … image data follows
```

- store: `[13617, 192699]`, ending at 206316;
- `c2pa.hash.data` exclusion: `[0, 206316]`.

The exclusion covers the store, and also **the SOI marker and the whole
13613-byte EXIF segment**. The other two files have the same layout:
14400 and 25017 bytes before the store, 0 after.

## The tamper test

A copy of `truepic-20230212-camera.jpg` with the EXIF capture date changed
from `2023` to `2019` in three places (offsets 202, 616, 636; six bytes
differ, checked with `cmp -l`), run with
`--settings tests/Fixtures/trust/truepic-root.settings.json`:

| | original | EXIF date changed |
|---|---|---|
| this verifier (`bin/c2pa-verify`) | `Trusted`, `assertion.dataHash.match` | **`Trusted`, `assertion.dataHash.match`** |
| `c2patool` 0.27.22 | `Trusted` | `Trusted` |
| `c2patool` 0.28.0 | `Invalid`, `assertion.dataHash.mismatch` | `Invalid`, `assertion.dataHash.mismatch` |

**A file whose metadata was changed after signing is called `Trusted`.**
The signed `stds.exif` assertion inside the manifest still says 2023. The
file's own EXIF says 2019, and nothing in the verdict tells the reader
that the two differ.

## What the specification says

C2PA 2.4, the data hash validation rules. The text below is read through
the rule catalogue of `encypherai/c2pa-knowledge-graph`, version 2.4, and
the section number is still to be taken from the specification itself:

- **VAL-ASSE-0043** (*shall*): *"A validator shall ensure that the data
  contained within the exclusion range containing the C2PA Manifest Store
  consists of only the C2PA Manifest Store and any appropriate padding
  (e.g., zero'd data) in clearly marked pad fields or free/skip boxes."*
- **VAL-ASSE-0044** (*shall*): *"If a validator encounters any data other
  than what is permitted, then the manifest shall be rejected with a
  failure code of `assertion.dataHash.mismatch`."*
- **VAL-ASSE-0045**: an exclusion *other than* the store's, for example
  for metadata, is allowed and gives the informational
  `assertion.dataHash.additionalExclusionsPresent`.

So the specification separates two cases:
- a *separate* exclusion for the EXIF would have been legitimate, and
  reported;
- an exclusion that holds the store **and** the EXIF breaks a *shall*.

The Truepic files are the second case.

## Where this verifier went wrong, and why

SPEC-012 amendment 5 (step 38, 2026-09-21) changed the rule from *equal*
to *cover*. It rested on two grounds:
- `c2patool` 0.27.22 accepted the files;
- the exclusion is signed intent: *"a writer that excludes more than the
  store hides bytes from its own binding, which the signer chose and
  vouched for"*.

The first ground was the oracle agreeing, not the specification. The
second is exactly what VAL-ASSE-0043 forbids, and VAL-ASSE-0077 names it
as an attack surface: padding and exclusions *"may enable an attacker to
replace parts of that padding with arbitrary data … without invalidating
the hash"*. The amendment did not check the reasoning against the
specification's text. It checked it against the oracle.

Step 90 then marked `PRED-IMG-004` (*exclusion range content
restrictions*) as **yes** in `docs/conformance.md`: *"only the store and
padding inside the exclusion"*. That is **not true** of the code, which
checks cover, not content. The public conformance table has been wrong
since 2026-09-22.

## How wide the change would be

Over the whole corpus, every JPEG/PNG/WebP with a store and a
`c2pa.hash.data`: 165 files.

- **128**: the covering exclusion equals the store's span exactly.
- **23**: no exclusion covers the store. They are already
  `assertion.dataHash.mismatch` under the cover rule, or fail earlier.
- **14**: the covering exclusion is wider than the store.
  - 11 are this project's own negative variants, already `Invalid` for
    another reason.
  - The other **3** are the Truepic files.

An *equal* rule therefore changes the verdict of exactly the three files
that break VAL-ASSE-0043. It may change *which* failure the other 11
report first. That has to be looked at criterion by criterion when the
rule is specified.

Reasoned, not measured: for JPEG, PNG and WebP, padding sits *inside* the
store (JUMBF `free` boxes, the claim's `pad` fields), not between the
store and the edge of its exclusion. For these three formats, "only the
store and padding" and "equal to the store's span" should therefore come
to the same thing. That is the rule `c2pa-rs` 0.91.0 applies. ISOBMFF
(`c2pa.hash.bmff.*`) is a different check and was not part of this
measurement.

## What this is not

- Not a change to any code, specification or test. The fix needs an
  amendment to SPEC-012 that reverses amendment 5, approved first, then
  tests seen red.
- Not yet reflected in `docs/conformance.md`, `SECURITY.md` or the
  changelog. What the public texts should say, and whether `v0.1.0` needs
  an advisory, is the maintainer's decision.
