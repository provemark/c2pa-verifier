# Step 143 — The Bing signature URI: read as the spec says, not adapted

*2026-09-25. A decision, from reading. No code or test behaviour
changed.*

## The question

After SPEC-041, the Bing Image Creator file's store is read, and the file
is `Invalid` with `claimSignature.missing`. Its claim writes every URI as
`self#jumbf=c2pa/urn:uuid:…/…`, without the leading slash. Both `c2patool`
versions read such a URI as absolute and find the signature. SPEC-042 was
to do the same. Before it was drafted, Maurice asked the question that
decides it: is Bing not simply wrong, and then why should this verifier
adapt?

## What the specification says (read)

From `c2pa-org/specifications` at `4eb2c67`, the rendered HTML of every
version converted to text:

- **C2PA 2.4 §8.4.2.1**: *"These self#jumbf URIs may be relative to the
  entire C2PA Manifest Store, in which case they shall start with a /
  (U+002F, Slash), or relative to the current C2PA Manifest."* 1.0 to 2.3
  say the same.
- **C2PA 2.4 §10.2.2**: *"The signature field shall be present and it
  shall contain an absolute URI reference to the claim signature in the
  same C2PA Manifest."* 1.0 to 2.1 only asked for *"a URI reference"*.
- **C2PA 2.4 §15.7**: *"If … the URI cannot be resolved … the claim shall
  be rejected with a failure code of claimSignature.missing."* 1.0 said
  the same with *must*.
- **The examples.** From 1.0 to 2.1, the spec's own example claim writes
  `"signature" : "self#jumbf=c2pa/urn:uuid:F9168C5E-…/c2pa.signature"`,
  and its assertion URIs the same way: exactly the Bing form. From 2.2 on,
  the examples start with `/`. 2.3 and 2.4 have no `self#jumbf=c2pa/` at
  all.

By the normative text, `c2pa/urn:uuid:…/c2pa.signature` is relative to the
manifest. The manifest holds no box labelled `c2pa`, so the URI does not
resolve, and `claimSignature.missing` is the prescribed outcome. That is
what this verifier reports.

## What `c2pa-rs` does (read, `ada3e4a`)

`sdk/src/jumbf/labels.rs`, `to_normalized_uri`, prefixes `/` to any path
that starts with `c2pa/`, so every such URI counts as absolute. For the
signature, `claim.rs` does not resolve the URI at all. It checks that the
label, if any, is the claim's own and that the last segment is
`c2pa.signature`, and it takes the signature box of the manifest itself.

## Why not adapt

- **Stricter is not a wrong `Valid`.** The only risk this project counts
  is a `Valid` that should not be. Here this verifier refuses what
  `c2patool` accepts, and it does so on the spec's normative text.
- **It would change no verdict today.** Bing's hard binding is
  `c2pa.hash.boxes` (both `c2patool` versions report
  `assertion.boxesHash.match`). This verifier refuses that binding by name
  (`PRED-CONT-006`, `docs/conformance.md`). With the URI read, the files
  would still be `Invalid`, now for the binding.
- **It sits beside other chosen strictness.** CAWG identity assertions,
  timestamp authorities without an anchor, and the redacted-assertion
  rules are already recorded in `docs/comparison.md` as differences by
  design.

The cost is real and named. A user who sees a Bing image pass at
`c2patool` sees `Invalid` here. The report's explanation says why.

## What changed

- `docs/comparison.md`: the row under *"Where `c2patool` can do more"*
  that pointed to SPEC-042 is gone. A row under *"Where this verifier
  differs by design"* now quotes §8.4.2.1, §10.2.2 and §15.7, names the
  spec's old examples and Bing, and says that the box hash would keep the
  files `Invalid` anyway.
- SPEC-041 amendment 2 (weight C, confirmed by Maurice van Loon the same
  day) withdraws amendment 1's pointer to SPEC-042. The comment in
  `FirstPieceSequenceTest.php` says the same.
- SPEC-042 is not written. If `c2pa.hash.boxes` is ever built, this
  question is the first to reopen, because only then does the answer move
  a verdict.

## In hindsight

SPEC-041 accepted Bing's first Z = 0, a probable deviation from the norm
too, on the grounds that `c2pa-rs` and the JPEG reference reader accept
it. By the reasoning above, that change was not needed either. It stays:
it is narrow, it is what the reference reader does, and it creates no
wrong `Valid`.
