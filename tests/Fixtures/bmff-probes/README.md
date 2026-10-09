# ISOBMFF probes from the reading of C2PA 2.4 (SPEC-027 amendment 8, SPEC-028 amendment 2)

Built by `bin/make-bmff-probe-variants.php <scratch> <c2patool-0.28.1>
<c2patool-0.27.22>` on 2026-10-09 (step 317). `c2patool` 0.28.1 signed
with a throw-away P-256 hierarchy; the keys lived in a scratch directory
and were deleted. `throw-away-root.pem` is the public root, and
`throw-away-root.settings.json` holds it as the legacy `trust_anchors`
string with `store.cfg`. A probe that `c2patool` will not write was made by
changing a few bytes of one assertion without changing their length,
re-hashing the claim's hashed URI for it and signing the claim again with
the same throw-away leaf.

| file | what it is | 0.28.1 | 0.27.22 |
|---|---|---|---|
| `sha384-control.mp4` | `fixture-unsigned.mp4` signed with `hash_alg: sha384` | `Trusted` | `Trusted` |
| `sha384-bmff-no-alg.mp4` | the same, the BMFF hash's `alg` key renamed `alX` | `Trusted` | `Trusted` |
| `init-alone.mp4` | the init segment of a stream from `ffmpeg -f dash`, signed with `c2patool fragment`, without its fragments | `Invalid` | `Invalid` |
| `init-no-count.mp4` | the same, the merkle map's `count` key renamed | error: cannot decode | error |
| `init-count-zero.mp4` | the same, the merkle map's `count` set to 0 | `Invalid` | `Invalid` |

The answers are under `../c2patool/bmff-probes/<file>--<version>.json`.
Certificates are valid for ten years from the build.
