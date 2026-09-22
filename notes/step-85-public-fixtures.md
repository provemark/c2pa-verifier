# Step 85 — Every public fixture, re-checked: one real hole found

*2026-09-22.* Maurice asked two things: is everything implemented, and can
it be tested against public fixtures. The second is a measurement, so it
was done first, and it answers the first.

## The official test files: nothing new, and nothing drifted

`c2pa-org/public-testfiles`, re-cloned (last commit 2025-12-05):

- The **`2.2` tree is still empty** — `.gitkeep` and READMEs only, exactly
  as step 43 measured. Everything in the repository is still the
  `legacy/1.4` tree.
- It holds **25 JPEG assets**; this repository holds 25, the same names,
  and every one is **byte-identical** to upstream. Nothing is missing and
  nothing has drifted since it was copied.

## `c2pa-rs`: nine files we never had, and they are all ISOBMFF

Step 39 mined c2pa-rs's fixtures, and that was before M8. Nine files it
holds are ones this project skipped because it could not read the
container:

| file | this verifier | `c2patool` 0.27.22 |
|---|---|---|
| **`video1.mp4`** | **`Invalid`** | **`Valid`** |
| `legacy.mp4` | `Invalid` — `signingCredential.expired` | `Invalid` |
| `dashinit.mp4` + `dash1.m4s` | `Invalid` — `claimSignature.missing` | `Invalid` |
| `nested_moov_1000.mp4` | `Invalid` | cannot parse it at all |
| `sample1.avif`, `sample1.heic`, `c.mov`, `BigBuckBunny_320x180.mp4`, `video1_no_manifest.mp4` | no manifest | no claim found |

Eight of the nine agree. `dashinit.mp4` is a deliberately broken fixture
upstream and both of us say so; `nested_moov_1000.mp4` is one `c2patool`
refuses to parse while this verifier reads the container and finds no
manifest.

## The hole: a real file carries `c2pa.hash.bmff.v2`

`video1.mp4` is `Valid` at `c2patool` with `assertion.bmffHash.match`, and
`Invalid` here with

> the hard binding **c2pa.hash.bmff.v2** is not supported yet

SPEC-027 put v2 out of scope in as many words: *"`c2pa.hash.bmff.v2`, if a
file with one ever turns up. The version is part of the label and a v2
assertion is not this one."* One has turned up, in the reference
implementation's own fixtures, and it is not exotic: it also carries the
only **trusted RFC 3161 timestamp** of any ISOBMFF file this project holds
(`timeStamp.validated`, `timeStamp.trusted`) and an ingredient.

The refusal is the right shape — named, never a silent `Valid` — but M8
supports the version `c2patool` writes *today* and not the one a file from
2022 carries. `video1.mp4` and its recorded verdict are now fixtures, so
the gap is evidence rather than a memory.

**What it would take is unmeasured.** Whether v2 differs from v3 in the
digest, in the exclusions, or only in the label is exactly the question
step 76 refused to guess at and step 77 settled by instrumenting c2pa-rs.
The same route applies, and it should be taken before anything is written.

## So: is everything implemented?

No, and the list is short and each item refuses by name rather than
guessing:

| | |
|---|---|
| `c2pa.hash.bmff.v2` | **a real public file uses it** — the finding above |
| `subset`, `length`, `version`, `flags` exclusion filters | `c2pa-rs` supports them; no reachable file uses them |
| Nested exclusion paths (`/moov/trak`) | likewise |
| Several renditions (more than one `merkle` map) | likewise |
| Redactions | refused since SPEC-011, the maintainer's decision, `docs/comparison.md` |
| CAWG identity assertions | refused |
| OCSP and revocation | never, by design: no network |
| Remote manifests | reported, never fetched, by design |
| GIF, TIFF, SVG, audio, PDF | not read |

Everything above is refused with its own message, so no file in any of
those categories can come back `Valid` with something unchecked. That was
the promise, and it still holds — but "M8 is closed" and "every ISOBMFF
file in the world verifies" are different sentences, and only the first
is true.
