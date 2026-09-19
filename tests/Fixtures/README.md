# Test fixtures

What may live here:

- **Public test certificates** — the c2pa-rs ES256 test chain
  (`es256_certs.pem`, `trust_anchors.pem`, `allowed_list.pem`, `store.cfg`),
  byte-identical to `contentauth/c2patool` `sample/`.
- **Signed test assets** — files signed with those test certificates, with a
  note (in `notes/`) saying which tool and version signed them and how.
- **Deliberately malformed files** — truncated segments, wrong lengths, altered
  bytes — each one named for the failure it exercises.

What never lives here, in any branch, under any name: a private key. This
project verifies only; it has no use for one. `*.key` is gitignored
unconditionally as a second line of defence, not as the first.
