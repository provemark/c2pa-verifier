# Step 61 — A second, independent oracle: 257 files through the Go verifier

*2026-09-22.* Everything this project measures itself against comes from
one implementation. `c2patool` is `c2pa-rs`; asking it twice is not
independent confirmation, and a fault the two of us share would be
invisible to every test in this repository. `richardwooding/c2pa`
(v0.22.0, pure Go, no cgo) is a verifier written independently against
the same specification — COSE, chain, RFC 3161, hard bindings, hashed
URIs, ingredients with cycle detection. This step ran it over the same
files and compared, file by file.

## How

`tools/go-oracle/` — a dozen lines around `c2pa.Validate` that print one
file's verdict as JSON. It runs in a container (`golang:1.26`); the
machine needed no Go toolchain and the repository gains no dependency.
The Go library defaults to the official C2PA conformance trust list, so
every run was given the same anchors this verifier was given, per family
(`trust/full.settings.json`, the matrix's roots, each variant family's
throw-away root) — otherwise every difference would have been "untrusted"
and nothing else.

**257 files**: 92 with a manifest from the four corpora and the matrix,
and 165 variants — the deliberately broken ones.

## What it found

### 1. A false `Invalid` in the Go verifier (`update_manifest.jpg`)

| | verdict | binding |
|---|---|---|
| this verifier | `Trusted` | `assertion.dataHash.match` |
| c2patool 0.27.22 | `Trusted` | match |
| the Go verifier | not valid | **`assertion.dataHash.mismatch`** |

Decided by measurement, not by majority. The parent manifest's
`c2pa.hash.data` excludes `{start 9964, length 18874}`; the store now
occupies `{9964, 43607}`, because an update manifest was appended after
the binding was written. Hashing the file both ways against the digest
the assertion records:

```
minus the RECORDED exclusion : 4e55f258…  does not match
minus the CURRENT store range: b7ffd257…  MATCHES the assertion
the assertion records        : b7ffd257…
```

C2PA 2.4 §15.12.1.1 says exactly that: with an update manifest present,
the exclusion that starts where the store starts "shall be treated as the
current length of the entire C2PA Manifest Store". The writer hashed it
that way, `c2pa-rs` reads it that way, and SPEC-022 implements it. The Go
verifier appears not to, and reports a mismatch on a file that is whole.
Reporting it to that project is the maintainer's call.

### 2. Thirteen files this verifier refuses and the Go verifier accepts

`jpeg/lbox-differs`, `jpeg/two-instance-numbers`, `png/crc-wrong`,
`png/length-differs`, `webp/length-differs`, `jumbf/brob`,
`jumbf/label-no-nul`, `jumbf/root-label`, `jumbf/root-uuid-c2ma`,
`jumbf/toggles-bit5`, `jumbf/uuid-c2cm`, `claim/claim-label-v3`,
`claim/no-manifest` — all container-, JUMBF- or claim-level
malformations, all already named as "stricter than c2patool" in the
specs that refuse them (SPEC-001, SPEC-002, SPEC-003, SPEC-005,
SPEC-007). A second implementation accepting them says the strictness is
real and deliberate, not an accident of one oracle.

### 3. Two files this verifier accepts and the Go verifier refuses

| file | here and at c2patool | the Go verifier |
|---|---|---|
| `profile/no-digital-signature.png` (KeyUsage `nonRepudiation` only) | `Trusted` | `signingCredential.invalid` |
| `profile/eku-c2pa.png` (EKU = the C2PA signing OID `1.3.6.1.4.1.62558.2.1`) | `Trusted` | `signingCredential.invalid` |

Both are rules SPEC-015 mirrors from `c2pa-rs` on Maurice van Loon's
decision of step 33 ("optie a"), and c2patool answers `Trusted` for both.
The C2PA profile (§14.5) can be read more strictly, and the Go verifier
does. Named in `docs/comparison.md` as a place where two implementations
disagree and this one follows the one it is measured against.

### 4. And the headline

**On 257 files, no other implementation found a fault this verifier
missed.** Every difference is one of the three above: a bug in the other
verifier, strictness this project has already named, or a rule the
maintainer chose to read as `c2pa-rs` does.

One more difference, not a fault either way: `c2pa-rs/prerelease.jpg`,
whose claim names its signature box with a pre-release URI spelling
(`self#jumbf=c2pa/…`, no leading slash). This verifier refuses it while
reading the manifest, the Go verifier reads it and reports
`claimSignature.mismatch`; `c2patool` refuses it too (it is one of the
files that produce an error and no JSON). All three call it not valid.

## What this does not prove

Two implementations agreeing is better than one, and still not a proof.
Both read the same English specification; a misreading it invites would
be shared. What it does rule out is the class of fault that lives in one
codebase: an ordering mistake, a forgotten case, a wrong constant.

## Measured / reasoned

- Measured: 257 files through `c2pa.Validate` with matched anchors; the
  two-way hash of `update_manifest.jpg` that decides §15.12.1.1; the
  thirteen and the two, each re-checked with the right trust settings
  after a first run showed ten differences that were nothing but
  `signingCredential.untrusted` from the wrong anchors file.
- Reasoned: that the Go verifier's `dataHash.mismatch` is a bug rather
  than a different reading — the digest itself decides, and it agrees
  with the adjusted exclusion.
