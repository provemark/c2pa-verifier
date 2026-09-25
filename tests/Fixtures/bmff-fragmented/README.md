# A fragmented BMFF stream (step 82)

Made here, because nothing this project can reach holds one:

```sh
ffmpeg -stream_loop 4 -i fixture-unsigned.mp4 -c copy -f dash \
       -seg_duration 0.3 -init_seg_name 'init.mp4' \
       -media_seg_name 'seg_$Number$.m4s' out.mpd
c2patool -m manifest.json -o out/ init.mp4 fragment --fragments_glob "seg_*.m4s"
```

`ffmpeg` 8.0 and `c2patool` 0.27.22, signed with the c2pa-rs ES256 test
certificates. The source is this repository's own `fixture-unsigned.mp4`,
so nothing third-party enters.

| file | what it carries |
|---|---|
| `init.mp4` | `ftyp`, the C2PA `uuid` box with `purpose: manifest`, `moov`. Its `c2pa.hash.bmff.v3` assertion has **no `hash`** — a `merkle` list instead |
| `seg_1…5.m4s` | `styp`, `sidx`, a C2PA `uuid` box with **`purpose: merkle`**, `moof`, `mdat` |

This verifier refuses the whole stream today, by name: SPEC-026 AC5 refuses
a `merkle` purpose and SPEC-027 AC5 refuses a `merkle` field. The fixture
is here because step 82 measured what verifying it would take, and a
measurement without the file it was made on is a claim.

`broken/` holds the variants `bin/make-fragmented-variants.php` writes, each
with one thing wrong. Two came with step 151 (SPEC-028 amendment 1):
`seg_5-location-5.m4s` and `seg_1-location-minus-1.m4s`. Each is a fragment
whose merkle box names a `location` outside the five-leaf tree, with nothing
else changed. `c2patool` 0.27.22 and 0.28.0 refuse both sets of AC8 with
`assertion.bmffHash.mismatch`; their answers are in
`../c2patool/bmff-fragmented/location-*.txt`.

`broken/seg_3-tail.m4s` (step 152, SPEC-027 amendment 4) is `seg_3` with 7
bytes appended. It is written by `bin/make-bmff-tail-variants.php`; see
`../bmff-tail/README.md`.
