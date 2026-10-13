# May a BMFF hash exclude the media? (step 356)

Built by `bin/make-bmff-exclusion-coverage-variants.php <c2patool-0.28.1>
<c2patool-0.28.2>` on 2026-10-13. Each MP4 is `../fixture-signed.mp4` with
its `c2pa.hash.bmff.v3` given one more top-level exclusion, the digest
recomputed by the port of c2pa-rs's BMFF hash (which first reproduces the
signed digest), the claim re-signed under `probe-root.pem` (a throw-away
P-256 hierarchy; the keys were deleted after the run), and the COSE padded
so that no box moves. The `-edited` files change one byte, 16 bytes into
the excluded box's body, after signing.

| file | exclusions | 0.28.1 | 0.28.2 | this verifier (0.6.1 + steps 349–355) |
|---|---|---|---|---|
| `unchanged-resigned` | `/uuid /ftyp /mfra /free /skip` (c2pa-rs's default) | `Trusted` | `Trusted` | `Trusted` |
| `mdat-excluded` | the same and `/mdat` (all coded samples) | `Trusted` | `Trusted` | `Trusted` |
| `mdat-excluded-edited` | the same file, one mdat byte changed | `Trusted` | `Trusted` | `Trusted` |
| `moov-excluded` | the same and `/moov` (the track description) | `Trusted` | `Trusted` | `Trusted` |
| `moov-excluded-edited` | the same file, one moov byte changed | `Trusted` | `Trusted` | `Trusted` |

All under `probe-root.settings.json`. The answers are under
`../c2patool/bmff-exclusion-coverage/`. All three report the informational
`assertion.bmffHash.additionalExclusionsPresent`, also on the unchanged
file, because `/free` and `/skip` count as additional. The two `-edited`
files are the wrong `Trusted`. The control: the same mdat byte changed in
`unchanged-resigned.mp4` is `Invalid`, `assertion.bmffHash.mismatch`, here
and in 0.28.2 (measured in step 356, not kept as a file).
