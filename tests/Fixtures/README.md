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

- **WAV** — `fixture-unsigned.wav` (the sister repository's file,
  unchanged) and `fixture-signed.wav`, signed with c2patool 0.27.22 and
  the c2pa-rs ES256 test certificates (step 204), with malformed variants
  under `wav/`.
- **AVI** — `fixture-unsigned.avi` (the sister repository's file,
  unchanged) and `fixture-signed.avi`, signed with c2patool 0.27.22 and
  the same certificates (step 209); variants under `avi/`, among them a
  signed file with a second RIFF chunk (`avi/signed-avix.avi`).
- **WAV from other writers** under `wav-writers/`: four files from
  `contentauth/c2pa-rs` and `contentauth/c2pa-python` at pinned commits,
  with their licences (step 210).
- **MP3** — `fixture-unsigned.mp3` (the sister repository's file,
  unchanged) and `fixture-signed.mp3`, signed with c2patool 0.27.22 and the
  same certificates (step 225); variants under `mp3/`.

What never lives here, in any branch, under any name: a private key. This
project verifies only; it has no use for one. `*.key` is gitignored
unconditionally as a second line of defence, not as the first.
