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

## 129b — built

**The reader.** `ManifestStore::fromTree()` now reads every manifest
before it checks any references. A claim may redact an assertion of any
other manifest, and the union of every claim's list is what counts (open
question 1).
- Only **absolute** entries of `redacted_assertions` form that union. A
  relative entry names no manifest, and `c2pa-rs` does not resolve one.
- A reference among them that no longer resolves is skipped: a removed
  box is a valid redaction.
- A manifest that cannot be read is still reported where it stands in the
  store.

**The hashed-URI check** (`HashedUriCheck`) loses its refusal:
- a redacted entry whose box is gone is skipped;
- a redacted box still present with content that is not all zero bytes is
  `assertion.notRedacted`;
- the claim's own list is read entry by entry, as `c2pa-rs` reads it. The
  claim's label inside an entry gives `assertion.selfRedacted`, and
  `c2pa.actions` inside it gives `assertion.action.redacted`. Both carry
  the entry verbatim as their url;
- an entry naming a hard binding keeps a `general.error` refusal
  (amendment 2).

**The ingredient check** (`IngredientManifestCheck`): when the ingredient
manifest has redactions and a v2 claim, the box hash is not tried. The
hash the ingredient recorded over the signature box is compared instead.
The result is `ingredient.claimSignature.validated` (informational),
`.mismatch`, or `.missing` when the assertion records none.

Read while building, and recorded as amendment 3:
- `c2pa-rs` keys that route on the ingredient **claim's** version;
- a redacted manifest with a v1 claim gets neither check there, and here
  it stays with the box hash, which then fails;
- `assertion.notRedacted` in an ingredient manifest lands under that
  ingredient here, where `c2pa-rs` reports it at store level.

Six new `StatusCode` cases; the recorded surface goes 114 → 120.

`vendor/bin/pest --group=SPEC-035`: **9 passed.** The seven red tests of
129a are green. Old tests that encoded the refusal were rewritten, each
with an amendment:
- SPEC-011 AC8 (amendment 3): `claim-redacted.bin` gives three matches
  and `assertion.action.redacted`;
- SPEC-021 AC6 (amendment 5): `redacted.png` holds no `general.error`;
- SPEC-013 AC10 (amendment 14): `assertion.action.redacted` leaves
  `SPEC013_NOT_YET`, so the drift alarm now compares it;
- SPEC-025 (amendment 7): the six codes.

Two counts moved: `CertificateProfileCheckTest` (48 → 54 codes) and
`ApiSurfaceTest` (114 → 120). `spec021Mismatch()` moved to
`tests/Shared.php`, because a parallel run does not load one test file's
helpers into another.

**Before and after, the whole corpus** under the three standard settings,
with ingredient deltas in the comparison (1002 runs). Only the five
redaction files moved:

| file | before | after |
|---|---|---|
| `redactions/redacted-with-action.png`, `redacted-without-action.png` | `Invalid`, `assertion.missing` | `Valid` (`Trusted` with their root), `ingredient.claimSignature.validated` |
| `redactions/claim-signature-changed.png` | `Invalid`, `assertion.missing` | `Invalid`, `ingredient.claimSignature.mismatch` |
| `ingredient-manifest/redacted.png` | `Invalid`, `general.error` | `Invalid`, `assertion.selfRedacted`, `.action.redacted`, `.notRedacted` |
| `binding/claim-redacted.png` | `Invalid`, `general.error` among others | `Invalid`, `assertion.action.redacted` among others |

On the last two files every failure now equals `c2patool` 0.28.0's, code
and url. The one exception is 0.28.0's duplicated
`assertion.dataHash.mismatch`.

`composer check`: exit 0, 465 tests.

Conformance: `PRED-INGR-002`, `PRED-ASSE-003` and `PRED-ASSE-009` go from
*closed* to *yes*. The count is now yes 59 and closed 4; gaps are
unchanged at 14.

Seven amendments await confirmation: SPEC-035 #1–#3, SPEC-011 #3,
SPEC-021 #5, SPEC-013 #14 and SPEC-025 #7.
