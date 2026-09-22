# Step 46 — More writers: Wikimedia Commons as a source, a Pixel 10 camera and Lightroom Classic taken in, and a hole found on the way

*2026-09-22.* Step 44's lesson was that one writer that is not c2pa-rs
found more than sixteen c2pa-rs fixtures. This step went looking for
writers, not files: cameras and editors whose output is public under a
licence a repository may hold. Two came in; one search method is worth
keeping; and thinking through what a camera writes led to a hole in the
verifier that no corpus file could show.

## Where files from real writers can be found (measured)

Manufacturers' sample images are rarely licensed for redistribution.
**Wikimedia Commons keeps the original bytes of every upload and every
file has a licence** — so a Commons file from a C2PA camera, uploaded
without re-export, is a real-world fixture this repository may carry.
The MediaWiki API (`list=search`, `generator=categorymembers`,
`prop=imageinfo`, a proper `User-Agent`, and a pause between calls
after a first rate-limit refusal) gives sizes, URLs, licences and sha1s.

| Commons category | files | probed (six smallest uncropped originals) | Content Credentials |
|---|---|---|---|
| `Taken with Google Pixel 10 Pro` | 242 | 2 of 6 | **yes** — "Google C2PA SDK for Android", 10–11 MB |
| `Taken with Google Pixel 10` | 106 | 2 of 6 | **yes** — the same writer, 5.6–5.8 MB, US federal public domain |
| `Images with Content Credentials by Julesvernex2` | 500+ | 2 | **yes** — Lightroom Classic 15.3, Adobe's production certificate, CC BY-SA 4.0 |
| `Taken with Leica M11-P` | 33 | 0 of 6 | none — every file re-exported (State Department photos, crops) |
| `Taken with Leica SL3-S` | 49 | 0 of 3 | none |
| `Taken with Sony ILCE-1M2` | 151 | 0 of 6 | none |
| `Taken with Samsung Galaxy S25` / `S25 Ultra` | 68 / 96 | 0 of 12 | none |

Elsewhere: `c2pa-org/public-testfiles`'s 2.x tree is still empty
(step 43); the manufacturers' own galleries carry no licence for
redistribution and were not taken. Two files were taken (both
unchanged, sha1 recorded in the corpus README):

- **`google-20250919-pixel10-npld-picnic-table.jpg`** — a Pixel 10,
  uploaded by the US Bureau of Land Management, public domain. ES256,
  claim v2, `sigTst2`; the signer `Pixel Camera` lives three months
  (2025-09-05 to 2025-12-03) under `Google C2PA Mobile A 1P ICA G3 L3`;
  the TSA is Google's own, under `Google C2PA Pixel Time-Stamping ICA
  G3`; both intermediates hang from `Google C2PA Root CA G3`, which is in
  neither the file nor the token. The assertion store holds
  `c2pa.hash.data` **and** `c2pa.hash.data.part` ×2 **and**
  `c2pa.hash.multi-asset` — the Ultra HDR gain map is a second asset
  with its own hashes — and the data hash carries five exclusions.
- **`adobe-20260425-lightroom-classic-church.jpg`** — Lightroom Classic
  15.3 (c2pa-rs 0.46.0), PS256 with Adobe's production certificate
  (`Adobe C2PA`), claim v1, `sigTst` by DigiCert, one DNG ingredient
  without a manifest, an action chain of fifteen entries. CC BY-SA 4.0 by
  Jules Verne Times Two — attribution in the README.

## What c2patool and this verifier say (measured)

| file | c2patool | this verifier |
|---|---|---|
| Pixel 10, no settings | `Invalid`: `signingCredential.expired`, `.untrusted`; `timeStamp.validated`, `.untrusted` (Google's TSA is on no list of c2patool's either); `additionalExclusionsPresent` | the same codes, the same state, `time` byte-equal |
| Pixel 10, the two Google intermediates as anchors (`google-pixel-intermediates.settings.json`) | **`Trusted`**, no `expired` | **`Trusted`**, no `expired` — the signer judged at its Google stamp (SPEC-017 AC12, added) |
| Lightroom Classic, no settings | `Valid`, `untrusted`; `timeStamp.trusted` (DigiCert) | `Valid`, `untrusted`; `timeStamp.untrusted` (the named divergence) |

Both are in `tests/Fixtures/writers/` and in the fourth drift alarm
(`SPEC013_WRITERS_CORPUS`; the Pixel file under `_TSA_NOT_CONFIGURED`).
`composer check` green, 294 tests.

Neither c2patool nor this verifier reads `c2pa.hash.data.part` or
`c2pa.hash.multi-asset`: c2patool's JSON carries no code for them and
its `dataHash.match` is the primary asset's; ours the same. The gain map
of a Pixel photo is therefore unverified by both — noted for the
roadmap (C2PA 2.2 §18.x, with M8's BMFF and Merkle work the nearest
relative).

## The hole found on the way (not fixed here: step 47)

Reading the Pixel manifest raised the question what happens when a
manifest carries *no* `c2pa.hash.data` at all. Measured on
`binding/hard-binding-missing.png` through the front door: the report is
`Invalid` — but only for `claimSignature.mismatch`, because that variant
was relabelled without re-signing. `checks_performed` ends at
`hashedUris`: the data-hash check never ran, and no
`claim.hardBindings.missing` was said. SPEC-013 runs the data-hash check
"only if `hash.data`'s hashed URI matched"; when there is no such
assertion there is no such URI, and the check that would refuse the
manifest is skipped. **A properly signed manifest with no hard binding
would be `Valid` here.** No corpus file shows it (every writer binds),
`DataHashCheck` itself says `claim.hardBindings.missing` when called
(SPEC-012 AC10's unit test), and the Verifier never calls it in that
case. This is the "wrong `Valid`" class — the one risk the brief puts
first — found by reasoning, to be shown red by a re-signed variant
(the profile step's throw-away hierarchy can sign one) and closed by a
SPEC-013 amendment in step 47 before anything else.

## What the corpus policy learns

- Commons is the licence-clean source for camera output; the categories
  `Taken with <model>` and the search for "Content Credentials" find it,
  and the sha1 the API returns is the provenance of the fixture itself.
- Most camera uploads lose their manifest on the way (crops, exports):
  four models with C2PA firmware, zero intact files among the smallest
  originals — the Pixel's are intact because the phone's gallery upload
  keeps the bytes.
- Every real writer so far has brought something: OpenAI fractions,
  Amazon a negative nonce and ES384, `c2pa-ts` unsorted attributes and
  raw ECDSA, Pixel a second asset and short-lived certificates — and a
  question that found a hole.
