# A claim without `alg` (SPEC-027 amendment 9)

Built by `bin/make-claim-alg-variants.php <scratch> <c2patool-0.28.3>
<c2patool-0.28.1>` on 2026-10-25 (steps 427 and 428; rebuilt in 428 with new throw-away keys). `c2patool` 0.28.3 signed
`fixture-unsigned.mp4` with a throw-away P-256 hierarchy; the keys lived in
a scratch directory and were deleted. `throw-away-root.pem` is the public
root, and `throw-away-root.settings.json` holds it as the legacy
`trust_anchors` string with `store.cfg`. The probes were made by
editing the claim without changing its length and signing it again with
the same throw-away leaf.

| file | what it is | 0.28.3 | 0.28.1 |
|---|---|---|---|
| `control.mp4` | `fixture-unsigned.mp4` signed (claim and BMFF hash on SHA-256) | `Trusted` | `Trusted` |
| `entries-alg.mp4` | the claim's `alg: sha256` moved into its two hashed-URI entries; 11 bytes taken off the `instanceID` to keep the length | error: unknown algorithm | error |
| `entries-alg-bmff-no-alg.mp4` | the same, the BMFF hash's `alg` key renamed `alX` | error: unknown algorithm | error |
| `entries-alg-claim-sha224.mp4` | `entries-alg.mp4` with `alg: sha224` added to the claim; 11 more bytes off the `instanceID` (23 characters now) | `Trusted` | `Trusted` |

The answers are under `../c2patool/claim-alg/<file>--<version>.json`.
Certificates are valid for ten years from the build.
