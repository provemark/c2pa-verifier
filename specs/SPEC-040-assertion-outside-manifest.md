# SPEC-040: `assertion.outsideManifest` — a claim that lists another manifest's assertion

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

A claim lists its assertions by URI in `created_assertions` and
`gathered_assertions`. C2PA 2.4 §15.10.3.1 (read at `4eb2c67`): *"If the
URI does not refer to a location within the same C2PA Manifest (a
self#jumbf location), the claim shall be rejected with a failure code of
`assertion.outsideManifest`. If the URI cannot be resolved and the data
retrieved, the claim shall be rejected with a failure code of
`assertion.missing`."*

This verifier refuses such a claim, but under the second code. The
manifest reader cannot resolve a URI into another manifest, so it stops
with `assertion.missing` on the store (*"refers to another manifest …;
cross-manifest references are not supported yet"*). `docs/conformance.md`
marks `PRED-ASSE-004` *partial* for exactly this.

**Measured for this draft (step 136).** The route is SPEC-036's, on the
signed PNG fixture:
1. one `created_assertions` URL is rewritten;
2. the claim is re-signed under a throwaway root;
3. the COSE is padded, so the store keeps its length and the data hash
   does not change.

| variant: the actions entry's url | 0.27.22 | 0.28.0 | here |
|---|---|---|---|
| `self#jumbf=/c2pa/<its own label>/c2pa.assertions/c2pa.actions.v2` | `Trusted` | `Trusted` | `Trusted` |
| `self#jumbf=/c2pa/urn:c2pa:00000000-…/c2pa.assertions/c2pa.actions.v2` | `Invalid`: `assertion.outsideManifest` on that url, then `claim.missing` *"Failed to load manifest"* | the same | `Invalid`: `assertion.missing` on `self#jumbf=/c2pa` |
| `self#jumbf=/c2pa/` (no manifest label) | no report (*"assertion missing: url = c2pa.actions.v2"*) | the same | `Invalid`: `assertion.missing` |

`c2pa` `claim.rs` `verify_internal` (read at `6c92bc3`) explains it. For
every absolute entry of the claim's assertion list it reads the manifest
label from the URI:
- when the label is not the claim's own, it logs
  `assertion.outsideManifest` on the URL as written;
- when there is no label at all, it logs `assertion.hashedURI.mismatch`,
  although `c2patool` stops before that and prints no report.

The trailing `claim.missing` is how `c2pa-rs` aborts, as SPEC-034
amendment 2 found for icons; it is not a rule.

No verdict changes. What changes is that the code and its url become the
ones the specification names and `c2patool` reports.

## Scope

**In scope**

1. `StatusCode` gains `AssertionOutsideManifest = 'assertion.outsideManifest'`,
   a failure.
2. `Manifest::checkReferences()`: an entry of `created_assertions` or
   `gathered_assertions` whose URI is absolute and names a manifest label
   other than the claim's own is refused with `assertion.outsideManifest`.
   The url is the entry as written. The label is compared whether or not a
   manifest with that label exists in the store.
3. Everything else stays as it is:
   - a URI with no manifest label keeps `assertion.missing`, since
     `c2patool` gives no report to copy;
   - a relative URI or one with the own label resolves as today;
   - references outside the claim's assertion list keep their codes:
     icons (SPEC-034), `relatedAssertions` and ingredient references
     (SPEC-033), and the redaction rules (SPEC-035).

**Out of scope** (each needs its own spec before it may be built)

- Copying `c2pa-rs`'s abort line (`claim.missing`, *"Failed to load
  manifest"*). It is not a rule, as SPEC-034 amendment 2 decided.
- Reading on after the refusal. `c2pa-rs` aborts the claim too, so the
  report stays one refusal.

## Behavior

- **AC1 — an entry naming another manifest** *(error path)*
  - Given the variant whose actions entry is
    `self#jumbf=/c2pa/urn:c2pa:00000000-0000-4000-8000-000000000000/c2pa.assertions/c2pa.actions.v2`
  - When verified with the throwaway root
  - Then `assertion.outsideManifest` on exactly that url, and `Invalid`,
    as both `c2patool` versions report it (their abort line aside).

- **AC2 — an absolute entry naming the claim's own manifest passes**
  - Given the variant whose actions entry names its own label absolutely
  - When verified
  - Then `Trusted`, as both oracles.

- **AC3 — an entry with no manifest label keeps its code** *(error path)*
  - Given the variant whose actions entry is `self#jumbf=/c2pa/`
  - When verified
  - Then `assertion.missing` and `Invalid`, as today (`c2patool` prints no
    report).

- **AC4 — a label that exists in the store is still outside** *(error path)*
  - Given, through `Manifest`'s reader on a two-manifest store
    (`c2pa-rs/CACA.jpg`'s, with one entry of the active claim rewritten in
    memory to name the ingredient manifest's label)
  - When the store is read
  - Then `assertion.outsideManifest` on that entry. The label differs
    from the claim's own, as in `c2pa-rs`.

- **AC5 — nothing else moves**
  - Given the whole corpus under the three standard settings, before and
    after, with ingredient deltas and informational codes compared
  - Then no verdict and no code changes outside the new fixtures, and the
    drift alarms pass.

- **AC6 — the vocabulary grows by one code, verbatim**
  - Then `StatusCode::AssertionOutsideManifest` exists with the value
    `assertion.outsideManifest`, is a failure, and is in the recorded
    surface (125 → 126).

## References

- Specification: C2PA 2.4 §15.10.3.1 and the §15 status-code table
  (*"An assertion listed in the claim is not in the same C2PA Manifest as
  the claim"*). Read in the 2.4 HTML of `c2pa-org/specifications` at
  `4eb2c67`.
- Oracles: `c2patool` 0.28.0 and 0.27.22 on step 136's three variants
  (scratchpad), to be recorded with the fixtures in the tests-first step.
- Reasoned: `c2pa` `claim.rs` `verify_internal`, the assertion loop, read
  at `6c92bc3`.

## API sketch

Illustrative.

```php
// Report\StatusCode (contract): one case more
case AssertionOutsideManifest = 'assertion.outsideManifest';

// Manifest\Manifest::checkReferences() (@internal): before resolving an entry
if (preg_match('#\Aself\#jumbf=/c2pa/([^/]+)/#', $reference->url, $m) === 1 && $m[1] !== $this->label) {
    throw new ManifestException('assertion reference to external assertion store: '.$reference->url,
        StatusCode::AssertionOutsideManifest, null, $reference->url);
}
```

## Open questions

*Answered on approval, 2026-09-24:* both settled by adopting their
proposals.

1. **Only the claim's assertion list.** `c2pa-rs`'s rule runs over the
   claim's assertion list alone. Proposal: the same. The other
   references keep the codes their own specs measured. *(not a blocker)*
2. **An entry that is both redacted and outside.** SPEC-035's
   redaction set holds only entries that name the claim's own manifest, so
   an entry naming another manifest is never excused as redacted. It gets
   `outsideManifest`, as in `c2pa-rs`, whose loop does not look at
   redactions first. Proposal: as it falls out. *(not a blocker)*

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
