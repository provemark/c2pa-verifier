# Step 129 — SPEC-035: redactions

*2026-09-24. SPEC-035 approved the same day. On open question 1 the
maintainer took the proposal: the union of every claim's
`redacted_assertions` in the store. Questions 2, 3 and 4 adopted their
proposals.*

## 129a — the probes, and the tests seen red

`bin/make-spec035-variants.php <c2patool-0.28.0> <c2patool-0.27.22>`
builds **three PNG probes** under a throwaway root, intermediate and leaf.

**Two come from `c2patool` 0.28.0's builder:**
- a parent signed with an extra `com.example.secret` assertion;
- two children made from it with `-p` and `redactions`, which redact that
  assertion. One of them carries a `c2pa.redacted` action and one does
  not.

**One is patched and re-signed**, as in SPEC-034:
- `claim-signature-changed`: one byte of the child's ingredient
  `claimSignature` hash is flipped, and the claim's hashed URI for the
  ingredient assertion is recomputed. The store keeps its length, the chunk
  CRC is recomputed, and the new signature is checked under its own leaf.

The script reads only the active manifest of each child, because this
verifier's own store parse still refuses the redacted parent. That refusal
is the fault this spec removes.

What both oracles said, with the root as anchor:

| probe | 0.27.22 | 0.28.0 | the ingredient delta |
|---|---|---|---|
| `redacted-with-action`, `redacted-without-action` | `Trusted` | `Trusted` | `ingredient.claimSignature.validated`, informational, in both |
| `claim-signature-changed` | `Invalid` | `Invalid` | `ingredient.claimSignature.mismatch`, in both |

This verifier says `Invalid` with `assertion.missing` on all three: the
reader refuses the parent's missing assertion before any redaction is read.

**Two existing files carry a redaction.** Both were re-measured under
0.28.0 and recorded next to the probes:
- SPEC-021's `ingredient-manifest/redacted.png` names **its own**
  `c2pa.actions.v2` by an absolute URI. 0.28.0 records
  `assertion.notRedacted`, `assertion.selfRedacted` and
  `assertion.action.redacted`, all on that URI.
- SPEC-010's `binding/claim-redacted.png` names the same assertion by a
  **relative** URI. Both versions record only `assertion.action.redacted`,
  on the URI as written.

`c2pa` `claim.rs` and `store.rs` (read) explain the difference:
- the codes carry the `redacted_assertions` entry verbatim;
- `selfRedacted` needs the claim's own label inside the entry;
- `notRedacted` needs the entry to resolve to a box whose content is not
  all zero bytes.

A relative entry does neither. The same code refuses a redacted
hard-binding assertion with `assertion.hardBinding.redacted`. That is
outside this spec (open question 4), so that case keeps the existing
refusal.

Recorded as SPEC-035 amendments:
1. The builder will not write AC3's or AC4's shape, and removes redacted
   boxes, so AC3–AC5 are evidenced by `redacted.png`.
2. The verbatim-URI reading. `claim-redacted.png` moves from
   `general.error` to `assertion.action.redacted`, still `Invalid`. A
   hard-binding redaction stays refused.

`tests/Unit/Manifest/RedactionTest.php`, run as
`vendor/bin/pest --group=SPEC-035`: **7 failed, 2 passed.** Every failure
is the rule not existing yet:
- `Invalid` where both oracles say `Trusted`;
- no `ingredient.claimSignature.*` delta;
- `general.error` where the three claim-level codes belong;
- none of the six `StatusCode` cases.

AC6 and AC7 are guards, green before and after. AC6 checks that a
mismatch in a store without redactions stays `ingredient.manifest.mismatch`.
AC7 checks that the ingredient files without a redaction keep
`c2patool`'s delta codes.

Each code the build does not have yet is looked up through a helper with
a `string` parameter. Otherwise PHPStan decides the comparison is always
false. `composer check` is otherwise clean: 458 passed.

Committed locally, not pushed.
