# Step 48 — The absence audit: every gate asked "and when it is not there?", four signed variants, one more wrong `Valid`

*2026-09-22.* Step 47's lesson turned into a method: for every rule of
the form "the verifier does X when Y is present", ask what happens when
Y is absent — and where the answer is "nothing", build a *signed*
manifest without Y and measure. An unsigned edit is `Invalid` for its
signature alone and proves nothing about the absence; the throw-away
signing of steps 34/47 is what makes the question answerable.

## 1. The inventory (reasoned from the code, then checked)

Every place the verifier or a check returns early, skips, or treats a
field as optional, and what an absence does there:

| where | what may be absent | what happens | refused? |
|---|---|---|---|
| `Verifier` | the manifest store | `has_manifest` false, `Invalid`; a declared remote manifest named (step 44) | yes |
| `Verifier` | the `c2pa.hash.data` assertion | **was**: the data-hash check skipped, `Valid` — **now** `claim.hardBindings.missing` (step 47) | yes |
| `Verifier` | the timestamp header | nothing said, validity at now (a timestamp is optional, §14.6) | n/a |
| `Verifier` | trust settings | `signingCredential.untrusted` (SPEC-014 amendment 1) | yes |
| `Claim::fromMap` | a required claim field (v2: `instanceID`, `claim_generator_info`, `signature`, `created_assertions`; v1: `claim_generator`, `signature`, `assertions`, `dc:format`, `instanceID`) | `claim.malformed` before any check | yes |
| `Claim::fromMap` | an empty `created_assertions` list | `claim.malformed` | yes (`created-empty` below) |
| `Claim::fromMap` | `gathered_assertions`, `dc:title`, `alg` | optional; `alg` absent falls to each hashed URI's own, and with neither `algorithm.unsupported` | n/a / yes |
| `Manifest::fromBox` | the claim box, the signature box, a signature URI that does not name the box | `ManifestException` → refused | yes |
| `Manifest::resolve` | an assertion the claim names that is not in the store | `assertion.missing` | yes |
| `HashedUriCheck` | a box in the store the claim does not name | `assertion.undeclared` | yes |
| `CoseSign1` | `alg`, `x5chain` (`cose/alg-missing`, `cose/x5chain-missing`, `cose/chain-empty`) | `CoseException` → refused | yes |
| `DataHashCheck` | `hash`, an exclusion covering the store, `alg` (both places) | `.malformed` / `.malformed` / `algorithm.unsupported` | yes |
| `CertificateProfileCheck` | EKU, AKI, basicConstraints on the leaf | `signingCredential.invalid` (EKU, AKI), not-a-CA (bC) | yes / n/a |
| `TimestampHeader`, `TimeStampToken` | `tstTokens`, certificates, `signedAttrs`, `messageDigest`, `contentType` | `timeStamp.malformed` (informational — the time is lost, as designed) | n/a |
| **assertion content** | **the actions assertion** | **nothing** — no check reads an assertion's content | **no** |
| assertion content | the thumbnail | nothing — and nothing requires one | n/a |
| assertion placement | `hash.data` under `gathered_assertions` instead of `created_assertions` | nothing — attribution, not validated (the brief's §7; c2pa-rs the same) | n/a |

One row without a refusal that should have one: the actions assertion.
Every other absence is caught before or by a check, or is allowed by
the specification.

## 2. The variants (`bin/make-absence-variants.php`, measured)

Four signed manifests from the PNG fixture, keys thrown away, the root
kept as an anchor for a second run — `tests/Fixtures/absence/`, c2patool's
JSON alongside:

| variant | c2patool, no settings → root as anchor | this verifier | agree? |
|---|---|---|---|
| `no-actions` — the `c2pa.actions.v2` box and its entry removed | `Invalid` — `assertion.action.malformed` "first action must be created or opened" on the manifest url | **`Valid` → `Trusted`** | **no** |
| `no-thumbnail` — the thumbnail removed (control) | `Valid` → `Trusted` | `Valid` → `Trusted` | yes |
| `hash-data-gathered` — `hash.data` moved to `gathered_assertions` | `Valid` → `Trusted` | `Valid` → `Trusted` | yes |
| `created-empty` — `created_assertions` `[]` (this verifier's claim reader refuses it, so the script cut the claim by offset to sign it) | `Invalid` — `claimSignature.mismatch` | `Invalid` — `claim.malformed` | both refuse |

The first two needed the data hash re-bound (a removed box shortens
the store, so the exclusion's length, the digest and the claim's hashed
URI for `hash.data` are recomputed by the script) — otherwise they were
`Invalid` for `assertion.dataHash.mismatch`, which is the wrong reason
and was the first thing the script showed.

## 3. The finding: the actions assertion

c2pa-rs (`claim.rs` 0.90.22, `verify_actions`) checks, for a v2 claim
that is not an update manifest: that an actions assertion exists and
the first action of the first one (created list, then gathered) is
`c2pa.created` or `c2pa.opened`; that every actions assertion has a
non-empty `actions` array; and a family of content rules after that
(no second `created`/`opened`, `action` present, `c2pa.opened`/`placed`
with an ingredient parameter, icons, templates) — all
`assertion.action.malformed`. For a v1 claim only "at most one actions
assertion" unless `strict_v1_validation`. C2PA 2.4 §18.x makes the
first two normative for 2.x manifests.

This verifier reads no assertion's content at all (SPEC-007 decoded it;
nothing has judged it) — the `SPEC013_NOT_YET` list has named
`assertion.action.redacted` and `assertion.required.missing` since M3 as
"the assertion-content rules of later specs". A v2 manifest without any
actions assertion is therefore `Valid` here and `Invalid` at c2patool:
**more lenient than the oracle**, which the drift-alarm rule forbids,
and a second file this project's own fixtures could not show. Less
grave than step 47's (the binding says whose pixels these are; the
actions say what was done to them), but a wrong `Valid` all the same.

**Proposal: SPEC-018, the actions assertion** — the two normative rules
and no more: for a v2 claim, exactly one actions assertion among
`created_assertions` first, else `gathered_assertions`, whose `actions`
list is non-empty and whose first `action` is `c2pa.created` or
`c2pa.opened` → else `assertion.action.malformed` on the manifest url,
as c2patool; for a v1 claim, at most one actions assertion (the corpus
has v1 files from Lightroom, Nikon, Truepic, Adobe 2022 to measure).
The content family (2.b–2.f) stays out until M7 needs ingredients — and
is named as such in the spec.

## 4. What the audit did not find, and what it cannot

- No other absence passes silently; the parse-level refusals (SPEC-005
  to SPEC-009) are where most of them land, and they refuse before a
  signature is even looked at.
- The audit is over *this* verifier's gates. Rules the specification
  has and this verifier does not implement at all (ingredient rules,
  update manifests, CAWG, BMFF) are not "absent Y" gates — they are
  named refusals (`_MULTI`, `_CAWG`) or later milestones.
- `created-empty` shows a limit of the method: when this verifier
  refuses a shape at parse, the signed variant can only be measured on
  the oracle side; the script does that by cutting the claim by offset.

`composer check` green, 295 tests (nothing under `src/` changed in this
step). Next: SPEC-018 as a draft, its test red on `no-actions`.
