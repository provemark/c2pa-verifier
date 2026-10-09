# Step 315 — Probes for the ISOBMFF and structure candidates (L1, L9, L10, L12, L13)

*2026-10-09. Measurement only; no change to `src/`.*

The last measurement step of the reading round. As in step 313,
`c2patool` 0.28.1 signed with a throw-away hierarchy in a scratch
directory. Three probes needed more than an edit of the same length, so
two small tools also fix every enclosing box length, the data hash's
exclusion length and its hashed URI, and the PNG chunk's length and CRC
before signing again. Each tool was first run on a change that keeps the
file valid:

- a shorter but valid `claim_generator_info`: `Trusted` in both versions
  and here;
- an MP4 and a fragmented init segment signed again unchanged: `Trusted`,
  and `Invalid` in all three for the init segment alone, as before.

## Result

| probe | `c2patool` 0.28.1 | 0.27.22 | this verifier |
|---|---|---|---|
| MP4 signed with `hash_alg: sha384`, the BMFF hash's `alg` key renamed | `Trusted` | `Trusted` | **`Invalid`** |
| PNG whose manifest box is typed `c2md` | `Trusted` | `Trusted` | **`Invalid`** |
| PNG with a parent, a copy of the parent manifest appended: `[X, Y, X']` | `Invalid`, X' active | `Invalid` | **`Trusted`**, Y active |
| a fragmented stream's init segment alone, the merkle map's `count` renamed | error | error | **`Trusted`**: "all 0 fragment(s) reach the merkle root" |
| PNG whose version 2 claim has `claim_generator_info: {}` | error | error | **`Trusted`** |

- **L1.** Without an `alg`, the BMFF hash falls back to SHA-256 here and
  to the claim's algorithm in `c2pa-rs`. Measured in the strict direction:
  a SHA-384 claim is `Invalid` here and `Trusted` there. The lenient
  direction (a SHA-256 hash under a SHA-384 claim, `Trusted` here) needs a
  hash of another length and is read, not built. One fix ends both:
  fall back to the claim's algorithm, as `DataHashCheck` does.
- **L9.** Stricter here, but against §11.2.2, which says consumers shall
  accept `c2md`. The lenient case reasoned in step 312 (a store
  `[c2ma, c2md]`) was not built.
- **L10, L12, L13: wrong `Trusted` against both `c2patool` versions.**

## The round in all

Of the thirteen candidates from step 312:

- **10 confirmed as more lenient than `c2patool`:** L2, L3, L4, L6, L7,
  L8, L10, L11, L12, L13, plus the action field types (P08-3).
- **2 measured as stricter:** L1 and L9, both against the specification
  or `c2pa-rs`'s rule.
- **1 not confirmed:** L5.

Next: the fixes, grouped by the spec that owns them, each with its probes
as fixtures, tests first. Then 0.5.4.
