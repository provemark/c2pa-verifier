# Test fixtures

What may live here:

- **Public test certificates** — the c2pa-rs ES256 test chain
  (`es256_certs.pem`, `trust_anchors.pem`, `allowed_list.pem`, `store.cfg`),
  byte-identical to `contentauth/c2patool` `sample/`.
- **Signed test assets** — files signed with those test certificates, with a
  note (in `notes/`) saying which tool and version signed them and how.
- **Deliberately malformed files** — truncated segments, wrong lengths, altered
  bytes — each one named for the failure it exercises.
- **Files from `c2pa-org/public-testfiles`** under `public-testfiles/`, copied
  unchanged at a pinned commit, CC BY-SA 4.0 (the licence attaches to those
  files, not to this package's code); see the README there for attribution
  and what each file shows.

- **ISOBMFF** — four flavours, each unsigned and signed with c2patool
  0.27.22 and the c2pa-rs ES256 test certificates, each with its
  `c2patool/*.json` beside it: **mp4** and **mov** (the sister
  repository's files, unchanged), **avif** (likewise), and **heic** (made
  from this repository's own `fixture-unsigned.png` with macOS `sips`, so
  nothing third-party enters). MP4 and AVIF arrived in step 73; MOV and
  HEIC in step 80b, after they had been claimed in the README without a
  fixture — which is why SPEC-026 AC9 now says a flavour named anywhere
  must be a fixture here.

What never lives here, in any branch, under any name: a private key. This
project verifies only; it has no use for one. `*.key` is gitignored
unconditionally as a second line of defence, not as the first.
