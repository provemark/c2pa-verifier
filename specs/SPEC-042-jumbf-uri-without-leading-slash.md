# SPEC-042: A JUMBF URI written `self#jumbf=c2pa/…`, without the leading slash

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-11-12                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A claim names its signature and its assertions by JUMBF URI. C2PA 2.4
§8.4.2.1 (read at `4eb2c67`, step 143): *"These self#jumbf URIs may be
relative to the entire C2PA Manifest Store, in which case they shall start
with a / (U+002F, Slash), or relative to the current C2PA Manifest."*
§10.2.2 wants the signature URI absolute, and §15.7 makes an unresolvable
one `claimSignature.missing`.

Microsoft Bing Image Creator writes every URI as
`self#jumbf=c2pa/urn:uuid:…/…`, without the slash. That is the form of the
spec's own example claim from 1.0 to 2.1 (step 143). `Manifest::resolve()`
reads such a path as relative to the manifest, finds no box labelled `c2pa`
there, and the file is `Invalid` with `claimSignature.missing`. `c2pa-rs`
(`sdk/src/jumbf/labels.rs`, `to_normalized_uri`, read at `ada3e4a`) puts a
`/` in front of any path that starts with `c2pa/`, so both `c2patool`
versions find the signature and the assertions.

ADR-0005 decided that this strictness protects nothing. The URI's label must
still be the claim's own manifest, and everything it leads to is checked
afterwards. So it is a gap, not a design. SPEC-041 amendment 3 deferred it
until box hashes, because the Bing files' hard binding is `c2pa.hash.boxes`.
That binding is refused by name (`PRED-CONT-006`), so reading the URI alone
moves no verdict for them. This spec is the first step towards box hashes,
as `docs/comparison.md` and ADR-0005 name it.

**Measured for this draft (2026-11-12, step 529).**

1. Over every file under `tests/Fixtures/`, the byte string
   `self#jumbf=c2pa/` occurs in two binaries:
   - `writers/microsoft-20260609-bing-fast-heartbeat.jpg`;
   - `c2pa-rs/prerelease.jpg`.

   `c2pa.hash.boxes` occurs in one real file (the same Bing file) and two
   variants of this project's own.
2. A scratch copy of `src/` (not in this repository) put a `/` in front of
   a path starting with `c2pa/` in `resolve()`, as `c2pa-rs` does. Nothing
   else was changed. The results:

   | file | today | scratch copy | `c2patool` |
   |---|---|---|---|
   | Bing, at now | `Invalid`: `claimSignature.missing` | `Invalid`: `claimSignature.validated`, `claimSignature.insideValidity`, two `assertion.hashedURI.match`; failures `signingCredential.expired`, `signingCredential.untrusted`, and `general.error` on `…/c2pa.assertions/c2pa.hash.boxes` (*"not supported yet"*) | 0.27.22 `Valid` (`assertion.boxesHash.match`, `signingCredential.untrusted`); 0.28.x `Invalid` (`signingCredential.invalid`, the EKU) |
   | Bing, `--at 2026-07-01T00:00:00Z` | `Invalid`: `claimSignature.missing` | `Invalid`: `signingCredential.untrusted`, `general.error` on the box hash | — |
   | `prerelease.jpg` | `Invalid`: `claimSignature.missing` | `Invalid`: `general.error` (*"the signature box is not valid CBOR: 5134 byte(s) remain"*) and eight `assertion.hashedURI.mismatch` | `Error: Prerelease claim found` |

   So no verdict moves. The Bing file now fails where `c2patool` 0.27.22
   would pass it, on the binding, which is the next step.

## Scope

**In scope**

1. One normalisation, `c2pa-rs`'s: a `self#jumbf=` URI whose path starts
   with `c2pa/` is read as if it started with `/c2pa/`. It applies wherever
   this verifier reads a claim's URI:
   - `Manifest::resolve()` (signature, assertion references, hashed URIs,
     ingredient references);
   - `Manifest::absoluteUri()` (redaction lookup, crJSON). Today it would
     turn `self#jumbf=c2pa/<label>/x` into `self#jumbf=/c2pa/<own
     label>/c2pa/<label>/x`;
   - SPEC-040's `assertion.outsideManifest` check in `checkReferences()`.
     A slash-less entry naming another manifest gets the code a slashed one
     gets.
2. Every check after resolution stays as it is:
   - the signature URI must still land on the manifest's own signature box;
   - an assertion reference must still land in its own assertion store;
   - a hashed URI's hash is still computed over the box it names.
3. `docs/comparison.md`: the Bing URI row leaves *"Where `c2patool` can do
   more"*. `docs/reading-c2pa-2.4.md` §8.4.2.1 and ADR-0005's consequence
   say what happened.

**Out of scope** (each needs its own spec before it may be built)

- `c2pa.hash.boxes` as a hard binding (C2PA 2.4 §9.2, §18.6), including
  `c2pa` 0.91.11's rule on data after the last box (upstream #2949). That
  is the next spec. Until it exists, a Bing file stays `Invalid` here.
- `redacted_assertions` entries written without the slash. `redactionsOf()`
  keeps only entries starting with `self#jumbf=/c2pa/` (SPEC-035
  amendment 2). Whether `c2pa-rs` normalises those entries was not read,
  and no file carries one (open question 3).
- Any other leniency in URI form: no `self#jumbf=` prefix, `//`, or a path
  starting with anything other than `c2pa/`.
- Reporting a status url as written rather than in absolute form (open
  question 1).

## Behavior

- **AC1 — Bing's signature is found**
  - Given `tests/Fixtures/writers/microsoft-20260609-bing-fast-heartbeat.jpg`
  - When verified with no trust settings, at `2026-07-01T00:00:00Z`
  - Then the report carries:
    - `claimSignature.validated` and `claimSignature.insideValidity` on
      `self#jumbf=/c2pa/urn:uuid:2b35d21e-b8eb-4e52-ba67-29bc413dfea6/c2pa.signature`;
    - `assertion.hashedURI.match` for `c2pa.hash.boxes` and `c2pa.actions`;
    - no `claimSignature.missing`.

    It stays `Invalid` with `general.error` on
    `…/c2pa.assertions/c2pa.hash.boxes`, the refusal `PRED-CONT-006` names.
    (The time is fixed because the leaf expired on 2026-10-01, SPEC-041
    open question 3.)

- **AC2 — a slash-less file with a data hash is judged like its slashed original**
  - Given a variant of `fixture-signed.png`:
    - every URI in the claim (signature, assertion references, hashed URIs)
      rewritten from `self#jumbf=/c2pa/…` to `self#jumbf=c2pa/…`;
    - re-signed under a throwaway root by SPEC-036's route;
    - the COSE padded, so the store keeps its length and the data hash holds.
  - When verified with the throwaway root as anchor
  - Then `Trusted`, with the same status codes as the variant re-signed
    without the rewrite. Both `c2patool` versions are to be measured on the
    pair before the test is written.

- **AC3 — a slash-less entry naming another manifest is outside** *(error path)*
  - Given SPEC-040 AC1's variant with its actions entry written
    `self#jumbf=c2pa/urn:c2pa:00000000-0000-4000-8000-000000000000/c2pa.assertions/c2pa.actions.v2`
  - When verified with the throwaway root
  - Then `assertion.outsideManifest` on exactly that url, and `Invalid`, as
    SPEC-040 AC1 for the slashed form. (Reasoned from `c2pa-rs`: it
    normalises before it compares the label. To be measured with both
    `c2patool` versions.)

- **AC4 — a slash-less signature URI naming another manifest is missing** *(error path)*
  - Given AC2's variant with the signature URI's label replaced by
    `urn:c2pa:00000000-0000-4000-8000-000000000000`
  - When verified
  - Then `claimSignature.missing` and `Invalid`: after normalisation it
    names another manifest, and `resolve()` refuses that as today.

- **AC5 — `prerelease.jpg` stays refused** *(error path)*
  - Given `tests/Fixtures/c2pa-rs/prerelease.jpg`
  - When verified
  - Then `Invalid`, no longer with `claimSignature.missing`, but with
    `general.error` on its signature (*"not valid CBOR"*) and
    `assertion.hashedURI.mismatch`, as measured above. Never `Valid`.

- **AC6 — a relative URI keeps its meaning**
  - Given URIs relative to the manifest that do not start with `c2pa/`
    (e.g. `self#jumbf=c2pa.assertions/c2pa.actions.v2`,
    `self#jumbf=c2pa.signature`)
  - When `Manifest::resolve()` and `Manifest::absoluteUri()` read them
  - Then they resolve against the current manifest as today. And
    `absoluteUri('self#jumbf=c2pa/<label>/c2pa.assertions/x')` returns
    `self#jumbf=/c2pa/<label>/c2pa.assertions/x`, with the label written in
    the URI and not the current manifest's.

- **AC7 — nothing else in the corpus moves**
  - Given the corpus, under the three trust configurations, without and
    with a revocation source
  - When compared before and after (worktree with `vendor/` copied, not
    symlinked)
  - Then only the two files above change status codes, and neither changes
    its verdict.

## References

- Specification: C2PA 2.4 §8.4.2.1 (URI forms), §10.2.2 (signature URI),
  §15.7 (`claimSignature.missing`), §15.10.3.1
  (`assertion.outsideManifest`). The spec's 1.0–2.1 example claims for the
  slash-less form (step 143).
- Oracle: `c2patool` 0.27.22 and 0.28.6, `c2patool <file>` with and without
  the throwaway root. Recorded today:
  - `tests/Fixtures/c2patool/writers/microsoft-20260609-bing-fast-heartbeat.json`;
  - `tests/Fixtures/c2patool/first-piece-z/microsoft-20260609-bing-fast-heartbeat--0.28.0.json`.

  AC2–AC4's variants are still to be made and measured.
- Reasoned:
  - `c2pa-rs` `to_normalized_uri` (`ada3e4a`, step 143);
  - for the signature, `claim.rs` checks only the label and the last
    segment.

  That AC3 matches `c2patool` is reasoned until measured.
- Decisions this rests on: ADR-0005 (and its addendum), SPEC-041 amendments
  1–3, SPEC-040.

## API sketch

No public API changes. One private helper inside `Manifest`, used by
`resolve()`, `absoluteUri()` and the SPEC-040 check:

```php
/** c2pa-rs's to_normalized_uri: a path starting with c2pa/ is read from the store's root (SPEC-042). */
private static function normalized(string $uri): string
{
    $path = substr($uri, strlen(self::URI_PREFIX));

    return str_starts_with($uri, self::URI_PREFIX) && str_starts_with($path, 'c2pa/')
        ? self::URI_PREFIX.'/'.$path
        : $uri;
}
```

Error messages keep quoting the URI as written.

## Open questions

1. **Which url goes in a status?** `HashedUriCheck` writes every
   `assertion.hashedURI.*` url in absolute form, built from the manifest
   label, also for a relative URI today. `c2patool` writes it as written,
   so without the slash for Bing (see the oracle JSON). Proposal: keep the
   absolute form. It is what this verifier already does for every relative
   URI, it moves no verdict, and it gets a row in `docs/comparison.md`.
   *(not a blocker)*
   *Status 2026-11-12 (step 530):* decided by Maurice: the proposal.
2. **Alone, or with box hashes?** On its own this spec moves no verdict in
   the corpus (measured above). Only AC2's synthetic variant shows a
   difference. ADR-0005 calls it *"the first step if box hashes are ever
   built"*. Proposal: approve it together with the decision to write the
   box-hash spec, and build it first. *(blocker: decides whether this is
   built now)*
   *Status 2026-11-12 (step 530):* decided by Maurice: the proposal. The
   box-hash spec is to be written; this one is built first.
3. **Slash-less `redacted_assertions` entries.** Out of scope as proposed:
   accepting them would let an entry excuse a missing assertion, which is
   leniency. `c2pa-rs`'s handling was not read and no file carries one.
   Proposal: read `c2pa-rs` before the box-hash spec, and amend this one
   only if it normalises there too and a file shows it. *(not a blocker)*
   *Status 2026-11-12 (step 530):* decided by Maurice: the proposal.

## Amendments

None yet.

## Traceability

Filled 2026-11-12 (step 531).

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Manifest/SlashlessUriTest.php :: AC1: Bing's signature is found / SPEC-042 | src/Manifest/Manifest.php :: normalized(), resolve() |
| AC2 | tests/Unit/Manifest/SlashlessUriTest.php :: AC2: a slash-less file with a data hash is judged like its slashed original / SPEC-042 | src/Manifest/Manifest.php :: normalized(), resolve(); bin/make-spec042-variants.php |
| AC3 | tests/Unit/Manifest/SlashlessUriTest.php :: AC3: a slash-less entry naming another manifest is outside / SPEC-042 | src/Manifest/Manifest.php :: checkReferences() (SPEC-040's check on the normalised url) |
| AC4 | tests/Unit/Manifest/SlashlessUriTest.php :: AC4: a slash-less signature URI naming another manifest is missing / SPEC-042 | src/Manifest/Manifest.php :: resolve(), checkReferences() |
| AC5 | tests/Unit/Manifest/SlashlessUriTest.php :: AC5: prerelease.jpg stays refused / SPEC-042 | src/Manifest/Manifest.php :: resolve() |
| AC6 | tests/Unit/Manifest/SlashlessUriTest.php :: AC6: a relative URI keeps its meaning / SPEC-042 | src/Manifest/Manifest.php :: normalized(), absoluteUri(), resolve() |
| AC7 | tests/Unit/Manifest/SlashlessUriTest.php :: AC7: nothing else in the corpus moves / SPEC-042 | — (a guard; the before/after corpus run is step 531's) |
