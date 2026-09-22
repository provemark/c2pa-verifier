# The absence audit (step 48)

Signed manifests in which one thing a rule depends on is *absent* — because
an unsigned edit is `Invalid` for its signature alone and proves nothing
about the absence. `bin/make-absence-variants.php <scratch>` builds them
from the PNG fixture's store (boxes removed, the claim's assertion lists
rewritten, the data hash re-bound where the store's length changed) and
re-signs the claim with a throw-away P-256 hierarchy (keys outside the
repository, deleted at the end of the run; the public root in
`throw-away-root.pem` and, as the only anchor, in
`throw-away-root.settings.json`). A new hierarchy per run; the shapes
do not change.

| variant | what is absent / moved | c2patool 0.27.22 (no settings / root as anchor) | this verifier before step 48 |
|---|---|---|---|
| `no-actions` | the `c2pa.actions.v2` box and its claim entry; `created_assertions` = [`hash.data`], `gathered` = [thumbnail] | `Invalid` — `assertion.action.malformed` "first action must be created or opened" on the manifest / the same | **`Valid` / `Trusted`** — nothing here reads the actions assertion |
| `no-thumbnail` | the thumbnail box and its entry (the control: nothing requires a thumbnail) | `Valid` / `Trusted` | `Valid` / `Trusted` — equal |
| `hash-data-gathered` | nothing absent: `hash.data` moved to `gathered_assertions`, actions to `created` | `Valid` / `Trusted` — placement is attribution, not validated (c2pa-rs) | `Valid` / `Trusted` — equal |
| `created-empty` | nothing absent: `created_assertions` = `[]`, every entry gathered — this verifier's claim reader refuses it, so the script cut the claim by offset to sign it | `Invalid` — `claimSignature.mismatch` (not investigated: both refuse) | `Invalid` — `claim.malformed` (a v2 claim creates at least one assertion) |

c2patool's JSON is under `../c2patool/absence/` (`<name>.json` without
settings, `<name>-trusted.json` with the root as anchor).
