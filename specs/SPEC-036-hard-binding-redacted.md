# SPEC-036: a redacted hard binding — `assertion.hardBinding.redacted`

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-24                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-035 made this verifier read redactions as `c2pa-rs` reads them, with
one exception. An entry of a claim's `redacted_assertions` that names a
hard-binding assertion is refused with `general.error` (SPEC-035
amendment 2). `c2pa-rs` reports it with a code of its own,
`assertion.hardBinding.redacted`, and the verdict is `Invalid` either way.

The refusal is correct but says less than it could. A caller reading the
report sees *"this verifier refused"*, where the file carries a fault that
the specification has a name for. It is also the last place where this
verifier's redaction vocabulary differs from `c2patool`'s. The drift
alarms cannot compare what one side calls `general.error`.

What the specification says, read in the 2.4 HTML at `4eb2c67`:
- **§6.8** (*Redaction of Assertions*), a rule for claim generators:
  *"When creating an update manifest, the claim generator shall not
  redact the hard binding to content assertion that applies to the
  current asset."*
- **The status-code table (§15)** defines `assertion.hardBinding.redacted`,
  *"A hard binding assertion was redacted"*. It lists
  `assertion.dataHash.redacted` as its **deprecated** predecessor, with
  the same meaning.
- As far as read, **no validation step in §15 names the code**. The table
  defines it, and §6.8 forbids the act, but only for generators.

What `c2pa-rs` does (`claim.rs`, `verify_internal`, read at `6c92bc3`): for
every entry of the claim's `redacted_assertions`, it checks whether the
entry contains one of `c2pa.hash.data`, `c2pa.hash.boxes`,
`c2pa.hash.bmff` or `c2pa.hash.collection.data` (its `HASH_LABELS`). If
one matches, it logs `assertion.hardBinding.redacted` on the entry as
written. The check is by substring and on any claim, not only update
manifests. It is stricter than §6.8, and it is what `c2patool` reports.

No file in the corpus carries such an entry. The change moves no verdict:
the same entries are refused today, and they stay `Invalid`.

## Scope

**In scope**

1. `StatusCode` gains `AssertionHardBindingRedacted =
   'assertion.hardBinding.redacted'`, a failure.
2. `HashedUriCheck::redactions()` reports it instead of `general.error`
   for an entry that contains one of the four hard-binding labels. The
   url is the entry verbatim, as for the other three redaction codes. The
   rule applies wherever the entry points, as in `c2pa-rs`.
3. The other redaction rules are unchanged, and apply alongside. An
   absolute entry naming the claim's own `c2pa.hash.data` is also
   `assertion.selfRedacted`, and `assertion.notRedacted` if the box is
   still there with content, as `c2pa-rs` logs them.
4. The refusal for a `redacted_assertions` that is not a list of strings
   stays `general.error`.
5. Fixtures: variants of the PNG fixture whose claim gains a
   `redacted_assertions` naming a hard binding. They are built by a new
   `bin/make-spec036-variants.php` on the route of
   `bin/make-ingredient-manifest-variants.php`'s `redacted`: the claim map
   grows one pair, the data hash is rebound, and the claim is re-signed
   under a throwaway root. The keys stay outside the repository and are
   shredded. Both `c2patool` versions judge every variant.

**Out of scope** (each needs its own spec before it may be built)

- The `c2pa.redacted` action and `assertion.action.redactionMismatch`
  (SPEC-035 open question 3): a measurement first.
- Narrowing the rule to §6.8's wording (update manifests, the binding of
  the current asset). See open question 2.
- The deprecated `assertion.dataHash.redacted`: never emitted here.

## Behavior

- **AC1 — a relative entry naming the hard binding** *(error path)*
  - Given the PNG fixture whose claim carries `redacted_assertions:
    ["self#jumbf=c2pa.assertions/c2pa.hash.data"]`, re-signed, with its
    data hash intact
  - When verified with the throwaway root
  - Then `assertion.hardBinding.redacted` on that entry as written, and
    `Invalid`. There is no `general.error`, and no `assertion.selfRedacted`
    or `assertion.notRedacted`: a relative entry names no manifest
    (SPEC-035 amendment 2). The redaction faults are `c2patool` 0.28.0's,
    code and url.

- **AC2 — an absolute entry naming the claim's own hard binding** *(error path)*
  - Given the same fixture with the entry
    `self#jumbf=/c2pa/<its label>/c2pa.assertions/c2pa.hash.data`
  - When verified
  - Then `assertion.hardBinding.redacted`, `assertion.selfRedacted` and
    `assertion.notRedacted`, each on that entry, and `Invalid`, as
    `c2patool` 0.28.0 records them.

- **AC3 — the other three hard-binding labels** *(error path)*
  - Given variants with one relative entry each, naming
    `c2pa.hash.boxes`, `c2pa.hash.bmff.v2` and
    `c2pa.hash.collection.data` (boxes this file does not have)
  - When verified
  - Then each is `assertion.hardBinding.redacted` on its entry, and
    `Invalid`, as `c2patool` 0.28.0.

- **AC4 — an entry that names no hard binding gets no such code**
  - Given SPEC-035's fixtures (`redactions/*.png`,
    `ingredient-manifest/redacted.png`, `binding/claim-redacted.png`)
  - When verified
  - Then none carries `assertion.hardBinding.redacted`, and each keeps the
    result SPEC-035 gave it.

- **AC5 — nothing else moves**
  - Given the whole corpus under the three standard settings, before and
    after, with ingredient deltas compared
  - Then no verdict and no failure code changes outside the new fixtures,
    and the drift alarms pass.

- **AC6 — the vocabulary grows by one code, verbatim**
  - Then `StatusCode::AssertionHardBindingRedacted` exists with the value
    `assertion.hardBinding.redacted`, is a failure, and is in the recorded
    surface (120 → 121).

## References

- Specification: C2PA 2.4 §6.8 (*Redaction of Assertions*) and the §15
  status-code table (`assertion.hardBinding.redacted`, and
  `assertion.dataHash.redacted` as deprecated). Read in the 2.4 HTML of
  `c2pa-org/specifications` at `4eb2c67`.
- Oracles: `c2patool` 0.28.0 and 0.27.22 on the new variants, to be
  measured in the tests-first step. Each criterion names 0.28.0. What
  0.27.22 reports is recorded and not required (open question 1).
- Reasoned: `c2pa` `claim.rs` `verify_internal` (the redaction loop and
  `HASH_LABELS`) and `assertions/labels.rs` (`HASH_LABELS`,
  `NON_REDACTABLE_LABELS`), read at `6c92bc3`. Because the hash labels are
  on `NON_REDACTABLE_LABELS`, `c2patool`'s builder most likely refuses to
  write such a redaction; hence the patched variants. Not measured yet.

## API sketch

Illustrative.

```php
// Report\StatusCode (contract): one case more
case AssertionHardBindingRedacted = 'assertion.hardBinding.redacted';

// Hash\HashedUriCheck::redactions(): per entry, next to selfRedacted and action.redacted
if (str_contains($entry, $label)) {   // one of the four HARD_BINDINGS
    $statuses[] = new ValidationStatus(StatusCode::AssertionHardBindingRedacted, $entry, 'redaction of disallowed hash assertion');
}
```

## Open questions

*Answered on approval, 2026-09-24:* question 2 by the maintainer (follow
`c2pa-rs`). Questions 1 and 3 were settled by adopting their proposals.

1. **What 0.27.22 reports.** It may still use the deprecated
   `assertion.dataHash.redacted`. Proposal: follow 0.28.0 and 2.4
   (`assertion.hardBinding.redacted`), and record 0.27.22's answer in
   the note as a named difference between the versions. The verdict is
   `Invalid` in both. *(not a blocker)*
2. **Whose rule: `c2pa-rs`'s or §6.8's.** §6.8 forbids generators to
   redact the hard binding *of the current asset* in an *update manifest*.
   `c2pa-rs` flags any entry containing a hash label, in any claim.
   Following §6.8 exactly would stop refusing some entries that are
   refused today, for example a redacted hard binding of an ingredient's
   manifest. Proposal: follow `c2pa-rs`. It is the oracle, it is the
   stricter of the two, and no verdict moves. *(not a blocker, but a
   choice worth making knowingly)*
3. **Matching by substring.** `c2pa-rs` matches `c2pa.hash.data` anywhere
   in the entry, so a label such as `com.example.c2pa.hash.data.notes`
   would match too. Proposal: copy the substring rule. It can only refuse
   more, never pass more, and the codes then compare. *(not a blocker)*

## Amendments

1. **2026-09-24, step 130a, measured before the tests.** Open question 1
   has its answer. On all five variants, `c2patool` 0.27.22 reports the
   deprecated `assertion.dataHash.redacted`, where 0.28.0 reports
   `assertion.hardBinding.redacted`, on the same url. On
   `hash-data-absolute`, 0.27.22 also lacks `assertion.notRedacted`, as on
   SPEC-035's `redacted.png`. Both versions call every variant `Invalid`.
   The criteria name 0.28.0, as proposed. Nothing in them changes.

   Weight C: a measurement recorded, no criterion changed.

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
