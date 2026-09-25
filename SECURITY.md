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
`C2PA`, and the ISOBMFF `uuid` box of MP4, MOV, AVIF and HEIC), JUMBF and
CBOR structure with bounds, claim v1/v2 syntax, the COSE_Sign1 signature
under the leaf certificate, the hashed URI of every assertion the claim
names, the hard binding over the asset — `c2pa.hash.data` and, for
ISOBMFF, `c2pa.hash.bmff.v2` and `.v3` including the Merkle tree of a
fragmented stream — the leaf certificate's C2PA profile, the chain to a
configured anchor, the RFC 3161 timestamp (signature, imprint, TSA chain)
and the validity window it supplies, the OCSP responses a signer staples
into its own signature, the actions assertion's opening rule, and the
manifests an ingredient assertion names — each validated in its own
right, update manifests included, with a fault reported against the
ingredient that brought it in.

Not verified (each refused or named, never silently accepted): a
manifest no ingredient assertion reaches (ignored, as C2PA 2.4
§15.11.3.3 directs, and rendered so the caller sees it is there), an
ingredient naming a manifest the store does not hold
(`ingredient.unknownProvenance`), CAWG identity assertions (refused),
`c2pa.hash.boxes` and `c2pa.hash.collection.data` (refused by name),
`c2pa.hash.data.part` / `c2pa.hash.multi-asset` (a second asset's hashes —
unread here as in `c2patool`), the content rules of assertions other than
the actions opening rule, and **any revocation that needs the network** —
an online OCSP query, an Authority Information Access fetch, a CRL. A
stapled response that cannot be read, cannot be verified, or is about
another certificate is reported as `signingCredential.ocsp.skipped`
rather than believed, and a file carrying none says so too. A remote
manifest declared by URL is reported, never fetched.

The 111 obligations of C2PA 2.4 that apply to the formats this verifier
reads are listed one by one in `docs/conformance.md`, with the 11 it does
not meet named. None of those 11 can make it report `Valid` about a file
whose bytes changed; that claim is set out there, per gap. It was made
once before and was wrong: see `PRED-IMG-004` below.

## Findings so far

The project keeps its own record. Eight cases of a wrong `Valid` or
`Trusted` have been found in it, all by the maintainers: two before any
release, one after `0.1.0`, and five, with eight ways to crash the
verifier, in the security review of 2026-09-25, fixed in `0.2.2`:

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
- **2026-09-24, an exclusion wider than the store — present in `0.1.0`, fixed after it**
  (`notes/step-108-exclusion-wider-than-store.md`). The three official
  `truepic-20230212-*` test files exclude the file head, including the
  whole EXIF segment, in the same range as the manifest store. C2PA 2.4
  says that range may hold only the store and padding. This verifier
  checked only that the range *covers* the store (SPEC-012 amendment 5),
  so a copy with its EXIF capture date changed is still `Trusted` with
  the signer's root as an anchor. `c2patool` 0.27.22 agrees with this
  verifier; `c2patool` 0.28.0 rejects the file, and its new rule is how
  this was found. It is present in `0.1.0`. Fixed the same day by SPEC-012
  amendment 7 (step 109): an exclusion that holds any part of the store
  must hold nothing else. Over 864 runs, that changed the verdict of
  these three corpus files and no others.

- **2026-09-25, a security review of the whole code base — present in
  `0.1.0` to `0.2.1`, fixed in `0.2.2`.** None of these needs a signing
  key. A wrong verdict:
  - an issuer in the chain walk was never checked to be a certificate
    authority, so a signer under an anchor could issue a leaf on any name
    and be `Trusted` (step 148, SPEC-014 amendment 4);
  - an ingredient assertion of a manifest the graph never reaches could
    cancel a real fault of one it does, so a changed asset could stay
    `Valid` or `Trusted` (step 149, SPEC-021 amendment 6);
  - a standard manifest without a hard binding borrowed its parent's,
    with the exclusion widened, when any update manifest was in the
    store (step 150, SPEC-022 amendment 6);
  - a fragment's Merkle location was not range-checked, so a withheld
    fragment could be replaced by a copy of another (step 151, SPEC-028
    amendment 1);
  - up to seven bytes after the last ISOBMFF box were not hashed (step
    152, SPEC-027 amendment 4).

  A crash, a hang or a corrupted report: an empty DER element in a
  timestamp token or an OCSP response (step 153); a very long INTEGER
  (step 154); and a CBOR memory bomb, unbounded ISOBMFF reads, a media
  type that is not UTF-8, OpenSSL warnings on standard output, input that
  cannot seek, and PHP stream wrappers in the command (step 155,
  SPEC-043). Each is described in its note under `notes/`, with the test
  that was red before the fix.

The method — for every rule of the form "check X when Y is present",
build a *signed* manifest in which Y is absent and measure — is now
tooling (`bin/make-absence-variants.php`) and part of every new spec.

## What this project will not do

It will not fetch anything over the network during verification, will
not bundle a production trust list, will not sign, and will not hold a
private key — test keys included. Any change to these four is a
documented architecture decision, not a patch.
