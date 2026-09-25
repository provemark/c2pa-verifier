# ISOBMFF files with bytes after their last box (step 152, SPEC-027 amendment 4)

Fewer than eight bytes after the last top-level box make no box header.
`c2pa-rs` hashes them after every included range, with no offset marker of
their own, even when the last box is excluded. `bin/make-bmff-tail-variants.php
<c2patool> <manifest.json> <scratch>` builds these files. It signs with
`c2patool` 0.28.0 and the c2pa-rs ES256 test pair, which stays outside this
repository.

| file | what it is | c2patool 0.27.22 and 0.28.0 (`--settings ../trust/full.settings.json`) |
|---|---|---|
| `appended.mp4` | `../fixture-signed.mp4` with 7 bytes appended after signing | `Invalid`: `assertion.bmffHash.mismatch` |
| `signed-tail.mp4` | `../fixture-unsigned.mp4` with 7 bytes after `mdat`, then signed | `Trusted` |
| `signed-free-tail.mp4` | the same with an 8-byte `free` box (excluded) before the tail | `Trusted` |
| `signed-free-tail-changed.mp4` | `signed-free-tail.mp4` with the tail's last byte changed | `Invalid`: `assertion.bmffHash.mismatch` |

`../bmff-fragmented/broken/seg_3-tail.m4s` is `seg_3` with 7 bytes appended.
Both versions refuse the stream with it in place of `seg_3`; see
`../c2patool/bmff-fragmented/seg_3-tail*.txt`. The JSON for the four files
is in `../c2patool/bmff-tail/`, as `<name>.json` (0.27.22) and
`<name>--0.28.0.json`.
