# Step 183 — text assets: a placeholder

## Why

Maurice asked whether text can ever be verified the way this verifier
checks images, and whether anyone is working on it. The answer is yes,
and it is in the specification already. This note records what was found
on 2026-09-30, so a later spec does not start from nothing. Nothing was
built, and no decision was taken.

## What the specification says (read, not measured)

C2PA 2.3 (December 2025) added text. In C2PA 2.4 there are three methods:

- **§A.7, HTML.**
- **§A.8, unstructured text** (`text/plain`). The manifest store bytes
  become invisible Unicode variation selectors, one per byte (U+FE00–FE0F
  and U+E0100–E01EF). They sit in a `C2PATextManifestWrapper` (magic
  `C2PATXT\0`, version 1) at the end of the text, after a U+FEFF. The hard
  binding is a `c2pa.hash.data` over the text without the wrapper, after
  NFC normalisation.
- **§A.9, structured text** (Markdown, YAML, source code and similar). The
  store goes into a comment line between ASCII-armour delimiters, either
  as a `data:` URI or as a reference.

The claim signature, the chain, trust and the timestamp work as they do
for images. For this verifier, text would be a new container (like M1)
and a new hash binding (like M4), not new cryptography.

## Who is working on it (looked up 2026-09-30)

- **Encypher** wrote the text parts of the specification. It maintains
  `c2pa-text` in Python, TypeScript, Rust and Go. That library embeds and
  extracts the store; it does not sign or verify.
- **`c2pa-rs`** merged a native plain-text handler on 2026-09-10 (#2494).
  It sits behind the experimental Cargo feature `unstable_plain_text`
  (tracking issue #2505). Structured text sits behind
  `unstable_structured_text` (#2377). HTML is still open (#2729).
  Neither feature is in a default build.
- **Unicode.** Peter Constable and Joshua Hadley filed L2/26-042
  (2026-01-13) with the Unicode Technical Committee. They wrote that the
  scheme uses variation selectors in a way that does not conform to
  Unicode §23.4. They also noted the committee's earlier view that
  invisible metadata in plain text is higher-level markup. The context
  they give is Article 50 of the EU AI Act.

## What that means here (reasoned)

- A text binding only holds while the text is untouched. Any edit breaks
  the hash. Software that strips variation selectors removes the manifest,
  and that leaves plain text with no manifest, which cannot be told apart
  from text that never had one. §A.9 in a file kept as a whole (a
  Markdown file in a repository) is the sturdier case.
- There is no oracle yet. The `c2patool` pinned here (0.27.22) is not
  expected to read `.txt`, because of the feature flag (reasoned from the
  flag, not tried). Every milestone here ends in a comparison with
  `c2patool`, so text waits until `c2pa-rs` makes the handlers default,
  or until Maurice decides to build against a `c2patool` compiled with
  the flag.
- When it comes, it is "one spec per format", in the `later` row of
  `docs/milestones.md`: §A.8 first (one wrapper, one hash), §A.9 second.

## Sources

- C2PA 2.4, Appendix A.7–A.9:
  https://spec.c2pa.org/specifications/specifications/2.4/specs/C2PA_Specification.html
- Unicode L2/26-042, *Embedded Metadata in "Plain" Text*:
  https://www.unicode.org/L2/L2026/26042-embedded-metadata-in-plain-text.pdf
- https://github.com/encypherai/c2pa-text
- `contentauth/c2pa-rs` #2494, #2505, #2377, #2729, and
  `docs/experimental-features.md` there
