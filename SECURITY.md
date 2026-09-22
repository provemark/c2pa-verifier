# Security

A verifier's one job is to never say `Valid` about a file that is not.
This document says what counts as a vulnerability here, how to report
one, and what this project has already found in itself.

## What counts

**A vulnerability** is anything that makes this verifier report `Valid`
or `Trusted` for an asset that `c2patool` (the C2PA reference
implementation, pinned per milestone in `docs/milestones.md`) or the C2PA
specification would refuse — a tampered asset, a manifest that binds to
nothing, a signature that does not verify, a chain that reaches no
anchor — or anything that lets a crafted file make the verifier throw an
uncaught exception, exhaust memory or time, or read outside the file.

**Not a vulnerability**: a file this verifier refuses that `c2patool`
accepts (the verifier is allowed to be stricter, and every such case is
named in the drift alarms in `tests/Pest.php` and in
`docs/comparison.md`); a format or feature it does not support yet (it
says so with a `general.error`); a `Valid` verdict on a file whose
*signer* you do not trust — `Valid` without your anchors is what the
specification prescribes; `Trusted` needs your list.

## Reporting

Please do not open a public issue for a suspected wrong `Valid`. Send the
file (or a way to reproduce one) and what `c2patool` says about it to the
maintainer through the contact on the repository's profile; you will get
an acknowledgement within a week. A fix follows the project's own rule —
a spec amendment, a test that is red on the file, then the change — and
the report is credited in the note of that step unless you ask otherwise.

There is no bug bounty.

## Scope of what is verified

Verified: the manifest store's extraction (JPEG APP11, PNG `caBX`, WebP
`C2PA`), JUMBF and CBOR structure with bounds, claim v1/v2 syntax, the
COSE_Sign1 signature under the leaf certificate, the hashed URI of every
assertion the claim names, the `c2pa.hash.data` binding over the asset,
the leaf certificate's C2PA profile, the chain to a configured anchor,
the RFC 3161 timestamp (signature, imprint, TSA chain) and the validity
window it supplies, and the actions assertion's opening rule.

Not verified (each refused or named, never silently accepted): ingredient
manifests and everything in a store with more than one manifest (refused
until M7), CAWG identity assertions (refused), ISOBMFF/BMFF hashes (refused),
`c2pa.hash.data.part` / `c2pa.hash.multi-asset` (a second asset's hashes —
unread here as in `c2patool`), the content rules of assertions other than
the actions opening rule, and OCSP / certificate revocation of any kind
(no network). A remote manifest declared by URL is reported, never
fetched.

## Findings so far

The project keeps its own record. Two cases of a wrong `Valid` have been
found in it, both by the maintainers, both before any release:

- **2026-09-22, no hard binding** (`notes/step-47-no-hard-binding.md`).
  A correctly signed manifest with no `c2pa.hash.data` assertion — a
  manifest that binds to no pixels at all — was `Valid`, and `Trusted`
  with its signer's root as an anchor, because the data-hash check ran
  only when that assertion's hashed URI matched and never when there
  was none. `c2patool` refuses such a file outright. No writer omits the
  binding, so 68 real files and 70 870 fuzzed ones never showed it; it
  was found by asking the code what it does when the assertion is
  absent. Closed the same day (SPEC-013 amendment 10) with a re-signed
  variant that is red without the fix.
- **2026-09-22, no actions assertion** (`notes/step-48-absence-audit.md`,
  `notes/step-49-actions-check.md`). A 2.x manifest without an actions
  assertion, or one that does not open with `c2pa.created` /
  `c2pa.opened`, was `Valid`; `c2patool` refuses it. Found by the same
  method applied to every gate; closed with SPEC-018.

The method — for every rule of the form "check X when Y is present",
build a *signed* manifest in which Y is absent and measure — is now
tooling (`bin/make-absence-variants.php`) and part of every new spec.

## What this project will not do

It will not fetch anything over the network during verification, will
not bundle a production trust list, will not sign, and will not hold a
private key — test keys included. Any change to these four is a
documented architecture decision, not a patch.
