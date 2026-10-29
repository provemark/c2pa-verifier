# Changelog

This project follows the milestones in `docs/milestones.md`; each entry
below names the milestone, the specs that closed it and the day it was
measured against `c2patool` 0.27.22. Dates are the day the work was
committed.

## 0.10.0 — 2026-10-28

A minor release. The evidence behind a verdict, which 0.9.0 made
readable, can now be written down as one versioned document to keep next
to the file — base64 DER for every certificate, time-stamp token and OCSP
response, so that an archive can be checked later without this verifier.
A caller's revocation source is asked each question once per
verification. It also carries the fixes of a security review of the whole
code made before the release (below, under Fixed): files that were wrongly
`Trusted` become `Invalid`. No corpus file outside the new probes changes
verdict.

**A `0.10`, not a `0.9.1`:** the public contract grows from 209 to 211
symbols (below). Nothing was removed or renamed; a caller on `^0.9` keeps
0.9.0 until it asks for `^0.10`. The C2PA 2.5 additions are not in it:
they wait until `c2patool` applies them (`docs/milestones.md`).

### Added

- **The evidence as a document to keep (SPEC-089).**
  `VerificationReport::evidenceToArray()` and `evidenceToJson()` write
  `trustLists()` and `evidence()` down in one versioned format
  ([`docs/evidence-format.md`](docs/evidence-format.md), version 1): the
  verdict it is the evidence of, the trust lists, and per manifest the
  time and anchors, with the certificates, time-stamp tokens and OCSP
  responses as base64 DER, so that an archive can be checked later
  without this verifier. Deterministic; `toJson()` and `toCrJson()` are
  unchanged. Two contract symbols (211).

### Changed

- **The revocation source is asked once per certificate and issuer
  (SPEC-086 amendment 6, SPEC-087 amendment 2).** Within one `verify()`,
  manifests that share a chain, and the Time-Stamping second walk, no
  longer ask the caller's source the same question again: a later need
  gets the first answer, an exception included. Each manifest still judges
  the answer at its own time. Over the corpus, 448 calls become 378, and
  no report changes.
- Two findings of the review of 0.9's code tidied, with no change a caller
  can see: one answer to "is the signer trusted?" inside the verifier
  (the anchor the chain walk hands out), and a time-stamp token whose read
  was refused is not read a second time.

### Fixed

- **A CAWG identity assertion is refused wherever this verifier validates
  (SPEC-013 amendment 24, SPEC-021 amendment 8).** The refusal knew
  `cawg.identity` only by its exact label and only in the active
  manifest: an instance (`cawg.identity__1`) or one in an ingredient
  manifest, with a credential this verifier never examined, left a file
  `Trusted` that `c2patool` calls `Invalid`. It now reads the base label
  and covers every manifest the ingredient graph reaches.
- **Time Stamping written twice is still the only EKU (SPEC-031
  amendment 5).** A certificate whose EKU extension names Time Stamping
  twice escaped the rule that keeps a time-stamping certificate from
  signing manifests under the legacy `trust_anchors`, and such a file was
  `Trusted`. The purposes are now read as a set.
- **An OCSP `good` without `nextUpdate` is current for 24 hours after
  its `producedAt` (SPEC-030 amendment 7).** It counted for ever, as
  C2PA 2.4 §15.9.1 does not allow. With a caller's revocation source that
  mattered: such a stored response, from before a revocation, kept the
  source from being asked, and a revoked signer stayed `Trusted`.
- **A delegated OCSP responder must be valid when it signed (SPEC-030
  amendment 8).** Its certificate's validity was not checked, so a
  retired responder key could answer `good` for ever and, with a
  caller's revocation source, keep a revoked signer `Trusted`.
- **The handler of an excluded track is read behind a 64-bit box header
  too (SPEC-072 amendment 1).** It was always read at offset 16, where a
  header with `size` 1 puts the version and flags, so a timed-metadata
  track could be turned into an unsigned video track that stayed
  `Trusted`, and a genuine one with that header was refused.
- **The BMFF box walk resumes where a container ends (SPEC-029
  amendment 4).** One to seven bytes after a container's last child were
  not passed over, so every later box header was read out of step and
  the tree could list boxes the file does not hold there.
- **The command line shows bytes that are not UTF-8 as U+FFFD (SPEC-019
  amendment 4).** Its error lines on stderr wrote them as `?`, which can
  also be a real character of what is echoed; amendment 4 had promised
  U+FFFD. The report's explanations keep writing `?`, as SPEC-043 asks.

## 0.9.0 — 2026-10-28

A minor release. A report can now show what its verdict rests on: which
trust lists it was judged against, the time and the anchor each manifest
was judged at, the certificates, the time-stamps found and the OCSP
responses offered. A caller may also hand the verifier a revocation
source of its own, so that a file that carries no revocation data can be
checked online; this package itself still opens no connection, and
without a source nothing changes. Three faults now carry the status code
C2PA 2.4 names. No verdict changes: over the corpus, against `0.8.3`,
the reports of twelve files differ — nine in a status code, three in an
explanation only (below).

**A `0.9`, not an `0.8.4`:** the public contract grows from 142 to 209
symbols in eighteen classes (below), the `Verifier` takes an optional last
constructor argument, and `StatusCode` gains two cases, which a `match`
over every case has to name. Nothing was removed or renamed; a caller on
`^0.8` keeps 0.8.3 until it asks for `^0.9`.

### Added

- **Which trust lists (SPEC-081).** `TrustListIdentity` — the list's kind
  (signers or time-stamp authorities), its form (PEM or the programme's
  JSON, `PEM`/`LOTE`), the SHA-256 of the list as given, and for a JSON
  list its sequence number and issue date — on each set
  `TrustSettings::fromTrustLists()` builds (`TrustAnchorSet::$identity`),
  and `VerificationReport::trustLists()`.
- **The evidence behind each verdict (SPEC-082 to SPEC-085).**
  `VerificationReport::evidence()` gives one `Verifier\ManifestEvidence`
  per judged manifest:
  - the time it was judged at and where that time came from (`TIMESTAMP`,
    `GIVEN`, `CLOCK`), and the anchor its signer's chain and its
    time-stamp authority's chain reached (`Trust\AnchorEvidence`: the
    anchor's SHA-256, the list, the depth, the route through an anchor or
    the allowed list, the dates the list trusts it between) — SPEC-082;
  - the certificates of `x5chain` as the file carries them, leaf first —
    SPEC-083;
  - every time-stamp token offered, from the header and from time-stamp
    assertions (`Timestamp\TimestampEvidence`: its bytes, time,
    certificates, outcome — used, rejected, not tried — and its own
    `timeStamp.*` codes) — SPEC-084;
  - every OCSP response the signer's revocation check walked, stapled or
    from certificate-status assertions (`Trust\OcspEvidence`: its bytes,
    outcome — chosen, not chosen, unusable, not tried — the chosen one's
    code, a usable one's status and times) — SPEC-085.

  The evidence is read from the checks where they judge; it never decides
  anything, and `toJson()` and `toCrJson()` are unchanged by it.
- **A revocation source of your own (SPEC-086, SPEC-087, ADR-0007).**
  `Trust\RevocationSource` has one method, `ocspResponse($subject,
  $issuer, $urls)`: the DER of a certificate, its issuer's DER and the
  OCSP urls of its authority information access; it returns an OCSP
  response, or null. Pass it as `new Verifier(revocationSource: …)`. The
  verifier asks it about each CA between the anchor and the signer (a
  revoked CA makes the signer `signingCredential.untrusted`), then about a
  trusted signer whose file proves nothing about revocation, and judges
  every answer exactly as a stapled response. No answer, a source that
  throws, or an answer that cannot be used is the new informational
  `signingCredential.ocsp.inaccessible` for the signer, and nothing for a
  CA. A fetched response is listed in the evidence as
  `OcspEvidence::FETCHED`. `Trust\NoRevocationSource`, the default, is
  never asked; to give no source, give none (the parameter is not
  nullable, SPEC-086 amendment 5). The urls come only from certificates on a path to an anchor
  you configured.
- `StatusCode::SigningCredentialOcspInaccessible`
  (`signingCredential.ocsp.inaccessible`, informational) and
  `StatusCode::AssertionCborInvalid` (`assertion.cbor.invalid`, failure).

### Changed

- **Three status codes follow C2PA 2.4 (SPEC-088).** An assertion that is
  not well-formed CBOR is `assertion.cbor.invalid` (§15.10.3.1), where it
  was `general.error`; the store is refused as before. A limit of this
  decoder or an item outside the subset it reads (depth, the item budget,
  `undefined`, an integer above 2^63 − 1) stays `general.error`: such CBOR
  may be valid (amendment 1), and the explanation now says "CBOR beyond
  what this verifier decodes" instead of "invalid CBOR". An array or map
  declaring more items than its remaining bytes can hold is truncation,
  so `assertion.cbor.invalid`, even when the count is also above the
  limit (amendment 2). A time-stamp token
  whose `messageImprint` names a hash outside §13.1 (SHA-1, for instance),
  or that does not carry its time-stamp authority's certificate, is
  `timeStamp.untrusted` (§15.8.2), where it was `timeStamp.malformed`;
  the token is ignored as before. A caller that filters on the old codes
  should look for the new ones. `c2patool` 0.28.3 reports the imprint and
  the token without certificates the same way, refuses the file with the
  broken assertion outright, and drops a token that carries only its
  authority's intermediate without any code (`docs/comparison.md`).

### Fixed

Found by a review of the new code before the tag (step 482).

- **The revocation source is told the issuer the chain walk verified
  (SPEC-086 amendment 2).** It was told that `x5chain`'s second
  certificate issued the signer, whatever that certificate was, and was
  not asked when `x5chain` held the signer alone. A signer issued directly
  by an anchor could thus escape the source and stay `Trusted` though
  revoked. For a signer on the allowed list, or itself an anchor, the
  second certificate is used only when it demonstrably issued the signer.
- **A signer with only the Time Stamping EKU: its path's CAs are checked
  on the second walk too (SPEC-087 amendment 1, SPEC-066 amendment 3).**
  The walk without the legacy anchors read neither a stapled response
  about a CA nor the source, so a revoked intermediate left the file
  `Trusted`. The stapled half dates from 0.6.0.
- **A stored `unknown` no longer keeps the source from being asked
  (SPEC-086 amendment 3).** The unsigned `rVals` header could carry an
  `unknown` about the signer and so block the lookup. The source is now
  asked after it; its `revoked` or current `good` counts, anything else
  leaves `signingCredential.ocsp.unknown`.
- **A trusted signer's stored OCSP responses are read against the issuer
  the chain walk verified (SPEC-030 amendment 5).** They were read against
  `x5chain`'s second certificate, which the author of a file chooses: a
  certificate with the anchor's name and another key could staple a
  `good` about a revoked signer and so keep the revocation source from
  being asked. Found by a second review, of the fixes themselves. As a
  side effect, a signer issued directly by an anchor has its staple read
  even when `x5chain` holds the signer alone; a verified `revoked` there
  is now `Invalid`, where `c2patool` 0.28.3 says `Trusted`.
- **A stored `good` proves nothing from an issuer only its own signature
  proves (SPEC-030 amendment 6).** For a signer on the allowed list, or
  itself an anchor, the issuer is the second certificate of `x5chain`
  when its key verifies the signer's signature. For an RSA signature
  another key that does so can be constructed, so a stapled `good` from
  it is now `notChosen` and the status `skipped`, and a revocation source
  is asked; a stapled `revoked` still counts. The verdict is unchanged.
  Found by a third review, of the fix above.
- **A stored response that was never read is `notTried` (SPEC-085
  amendment 2).** When the signer's issuer is not in `x5chain`, no stored
  response can be matched to the signer; the evidence called each one
  `unusable`, as if it had been read and refused. No verdict changes.
- **Responder urls with control characters are not passed to the source
  (SPEC-086 amendment 4).** A url from the certificate's AIA is passed
  only when every byte is visible ASCII; one with CR, LF, NUL, a space or
  a tab could otherwise reach a caller that builds its own HTTP request.

### Documentation

- `docs/adr/ADR-0007-revocation-from-an-injected-source.md`: why online
  revocation comes in only through a source the caller injects, and what
  of `c2patool`'s fetch path was not copied.
- `docs/conformance.md`: `PRED-STRU-010`, `-013`, `-016`, `PRED-CRYP-020`,
  `-022`, `-023` *yes* (opt-in where they need the network), and
  `PRED-ASSE-007` *yes*: 78 yes, 9 partial, 18 by design.
- `docs/reading-c2pa-2.4.md`: 334 rules covered, 50 partial; group F
  (another code, the same verdict) worked through, five rows kept with
  their reasons. `signingCredential.expired` recorded as a code of the
  C2PA 1.0–2.1 tables that `c2patool` still reports.

## 0.8.3 — 2026-10-26

A patch release. Before reading C2PA 2.5 further, every rule of 2.4 was
checked against this verifier once more (`docs/reading-c2pa-2.4.md`, 563
rules). Four questions asked whether it could be more lenient than
`c2patool`: two found it was, and both now say `Invalid`, as `c2patool`
does. Nothing was forged in either case. An MP4 with a Merkle tree over
its `mdat`, which `c2patool` writes on request, is now read. The public
contract is unchanged (142 symbols), so a caller on `^0.8` receives this
release without changing its constraint.

### Added

- **A Merkle tree over the `mdat` of a non-fragmented MP4 (SPEC-080).**
  `c2patool` writes one when `core.merkle_tree_chunk_size_in_kb` is set:
  the file hash beside a merkle map, and one proof box per block after the
  `mdat`. Such files were `Invalid` here (two C2PA boxes, or "both a hash
  and a merkle list") and are now verified: the file hash, then every
  block against its proof and the stored root or leaves. A changed byte
  names its block. Where `c2patool` 0.28.3 and 0.28.1 call a file
  `Trusted` with bytes under neither the hash nor a leaf (the whole `mdat`
  excluded), or with a stored row the tree does not have, this verifier
  says `Invalid` (`docs/comparison.md`). Variable block sizes and several
  `mdat` boxes, which `c2pa-rs` writes by no setting, are refused by name.

### Changed

- **A BMFF hash with no algorithm named anywhere is
  `algorithm.unsupported` (SPEC-027 amendment 9).** It was `Trusted` by a
  SHA-256 fallback when neither the assertion nor the claim named one;
  `c2patool` 0.28.1 and 0.28.3 refuse such a claim. C2PA 2.4 §15.4.1
  names the code. The data hash already did this.
- **Every reference of a `c2pa.opened` action must be the one parent
  ingredient (SPEC-033 amendment 3).** The parent followed by a reference
  to nothing, or to a `componentOf` ingredient, was `Trusted` here and is
  `Invalid` in `c2patool`, which lets the last reference decide. Now
  `assertion.action.ingredientMismatch` in either order, as C2PA 2.4
  §15.10.3.2.3 says ("exactly one"); in the other order that is stricter
  than `c2patool`.
- **An ingredient's own stapled `revoked` counts (SPEC-066 amendment 2).**
  A revoked signer already made the active manifest `Invalid` (SPEC-030);
  the same response stapled in an ingredient's signature now makes the
  ingredient fail too. `c2patool` reads neither, so this is stricter than
  it. `good` responses on ingredients stay unreported.
- A refusal of a manifest with two assertion stores now says "assertion
  stores", not "assertion storees".

### Documentation

- `docs/reading-c2pa-2.4.md` brought up to date (steps 425, 426, 450):
  337 rules covered, 78 by design, 98 not for a validator, 45 partial and
  4 candidates, the open ones sorted by what they can do to a verdict.
  None of them can give a `Valid` that `c2patool` would not give.
- Each covered rule of §6 to §11, §13 to §15, §18 and Appendices A and
  C broken once on purpose (steps 431–441, 393 mutations): a break that
  no test caught was given a test, or recorded in its step's note as
  equivalent or left by design.

Measured: the corpus against `0.8.2` (`vendor/` copied into the
worktree, the same fixtures in both trees), 33 of 874 files moved, every
one a fixture new since `0.8.2`: the 28 of `mdat-merkle/`, and the five
probes of the three changes above.

## 0.8.2 — 2026-10-25

A patch release. `c2patool` 0.28.3 (`c2pa` 0.91.6) and C2PA 2.5 both
appeared on 2026-10-24/25. Measured against them, this verifier said
`Valid` or `Trusted` in two cases where every `c2patool` measured says
`Invalid`; both now say `Invalid`. Nothing was forged in either case: the
signature is the holder's. The public contract is unchanged (142
symbols).

### Changed

- **An ECDSA algorithm under a key on another curve is refused (SPEC-009
  amendment 5).** ES256 under a P-384 key, ES384 under P-256 and ES512
  under P-384 were `Valid`, and `Trusted` under a trusted root.
  `c2patool` 0.27.22 to 0.28.3 and `c2pa-web` 0.15.3 say `Invalid`; C2PA
  2.4 §13.2.1 said to accept such a pairing, C2PA 2.5 §12.3.1 lets a
  validator refuse it. Now `signingCredential.invalid`, without
  `claimSignature.mismatch` (which `c2patool` adds), as the signature
  itself is sound.
- **A signer certificate signed with `id-Ed25519` and algorithm
  parameters is refused (SPEC-015 amendment 11).** RFC 8410 §3 says the
  parameters must be absent. `c2patool` 0.28.3 refuses such a signer
  certificate; 0.28.2 did not. Now `signingCredential.invalid`.
  Certificates above the signer are left as `c2pa-rs` leaves them.
- **The update-manifest rules skip a manifest nothing reaches (SPEC-022
  amendment 8).** An update manifest or a standard manifest that no
  ingredient names made the file `Invalid` here (`manifest.update.invalid`,
  `manifest.multipleParents`) and `Trusted` in `c2patool`; C2PA 2.4
  §15.11.3.3 says to ignore such manifests, and every other check here
  did. This one goes the other way: a verdict that was stricter than
  `c2patool` without protecting anything.
- **`bin/make-ed25519-params-variants.php`, `bin/make-ecdsa-pairing-variants.php`**
  are new, and `bin/graph-fuzz.php` builds update manifests. Their
  throw-away keys are made outside the repository and deleted after each
  run.

### Documentation

- C2PA 2.5 read against this verifier (steps 416–418): no other gap; what
  2.5 adds waits for `c2pa-rs` (`docs/milestones.md`).
- The corpus against `c2patool` 0.28.3 (step 419): 769 of 770 files give
  the same state and codes; the one that moved is refused here, as it was.
- A time-stamp with a SHA-1 imprint (step 420): `Invalid` here already,
  as in 0.28.3; 0.28.1 and 0.28.2 said `Trusted`.

Measured: the corpus against `0.8.1` (`vendor/` copied into the worktree,
the same fixtures in both trees), 12 of 1,906 runs moved, every one in a
fixture new since `0.8.1`.

## 0.8.1 — 2026-10-24

A patch release. A fuzzer for the manifest graph (`bin/graph-fuzz.php`),
which builds manifest stores in which every manifest is signed again,
found two ways to a wrong `Trusted` in `0.8.0`, one of them present since
`0.1.0`. Every user should upgrade. The public contract is unchanged (142
symbols).

### Security

- **Only the first reference to an ingredient manifest had its hash
  checked (SPEC-021 amendment 7). Present in `0.1.0` to `0.8.0`.** A
  manifest named by two ingredient assertions was validated once, through
  the first, and the second assertion's hash of it was never compared. A
  second reference with a wrong hash left the file `Trusted`; `c2patool`
  0.28.2 says `Invalid`, `ingredient.manifest.mismatch`. Confirmed on
  `0.7.1` and `0.8.0`. The hash of every assertion that names a manifest
  is now checked, under that assertion (C2PA 2.4 §15.11.3.3.2); the
  manifest itself is still validated once.
- **A redaction could reach the active manifest through a cycle
  (SPEC-035 amendment 7). Present in `0.8.0`.** `0.8.0` let a redaction
  apply only below the manifest that declares it, but "below" followed a
  cycle, so an ingredient whose references lead back to the active
  manifest could still declare one of its assertions redacted. With the
  cycle acknowledged (see Changed), the file was `Trusted` with an
  assertion of the active manifest removed. Confirmed on `0.8.0`. It needs
  the ingredient's author to know the active manifest's label in advance.
  No redaction of the active manifest applies now.

### Changed

- **A cycle can no longer be acknowledged (SPEC-070 amendment 4).** A
  reference that closes a cycle is `assertion.ingredient.malformed`, and a
  record in a manifest above it could acknowledge it, so a cyclic store
  could be `Valid` or `Trusted`. `c2patool` refuses every such store. A
  cycle is not an error in one ingredient's claim (C2PA 2.4 §18.16.12.4),
  so no record speaks for it now, and a cyclic store is `Invalid`. Other
  failures in such a store are acknowledged as before. No corpus file
  holds a cycle.
- **`bin/graph-fuzz.php`** is new: seeded, replayable plans of one to six
  manifests with faults, ingredients, redactions and recorded statuses,
  judged against a prediction made from the plan alone, plus fixed
  calibration plans. It found both issues above and, run against
  `0.7.1`, the issues fixed in `0.8.0`. Its throw-away keys are made
  outside the repository and deleted after each run.

Measured: the corpus against `0.8.0` (`vendor/` copied into the
worktree), 0 of 1,854 runs moved, 18 new runs for the new fixtures;
`bin/graph-fuzz.php` on seeds 407–410, 2,000 plans each, 0 findings.

## 0.8.0 — 2026-10-23

A minor release. The verifier can now be driven the way the C2PA
conformance programme drives a validator: a validation time given by the
caller, the programme's two trust lists (PEM or its JSON form, with
dates), and an answer in crJSON. A review of the whole code base before
the release found five ways to a wrong `Valid` or `Trusted`; four were in
released versions. Every user should upgrade.

**A `0.8`, not a `0.7.2`:** the public contract grows from 136 to 142
symbols (below), and `verify()` takes an optional third argument. Nothing
was removed or renamed; a caller on `^0.7` keeps 0.7.1 until it asks for
`^0.8`.

### Security

- **An ingredient could acknowledge its own failures (SPEC-070 amendment
  3). Present in `0.1.0` to `0.7.1`.** A recorded ingredient status
  excuses a failure only when a manifest above the failing one recorded
  it (C2PA 2.4 §18.16.12.4). The records of every manifest the graph
  reaches were pooled, so a replaced ingredient manifest could list its
  own `claimSignature.mismatch` and its parent's `ingredient.manifest.mismatch`
  in an ingredient assertion of its own, and the file came out `Valid`,
  or `Trusted`. Confirmed with the real classes; no such file was found.
  A record now speaks only for the manifests below the one holding it.
- **A redaction could reach the active manifest (SPEC-035 amendment 6).
  Present in `0.2.1` to `0.7.1`.** A redaction declared by any reached
  manifest applied to whichever manifest its URI named, so an ingredient
  could declare one of the active manifest's assertions redacted and its
  missing box was excused. A redaction now applies only below the
  manifest that declares it (C2PA 2.4 §6.8). Reasoned from the code: since
  the fix above, it needs the ingredient's author to know the active
  manifest's label in advance. `assertion.notRedacted` still checks every
  declared redaction.
- **BMFF offset tables could be excluded from the hash (SPEC-071
  amendment 1). Present in `0.1.0` to `0.7.1`.** An exclusion of the
  offset fields of `stco`, `co64`, `tfhd`, `trun`, or of `meta/iloc`, was
  allowed because an older `c2pa-rs` wrote it. With the chunk offsets
  unsigned, the media could be changed without breaking the binding.
  C2PA 2.4 §18.6.2 hashes every
  offset at its post-embed value, and today's `c2pa-rs` no longer excludes
  them. Such an exclusion is now `assertion.bmffHash.mismatch`. A file an
  older `c2pa-rs` (2024, `c2pa.hash.bmff.v2`) signed this way is `Invalid`
  here.
- **An entry's EKUs were chosen at the clock (SPEC-079 amendment 2).
  Present in `0.2.0` to `0.7.1`.** Whether a chain reaches a
  `trust.anchors` entry, for that entry's `trust_config` EKUs and for the
  note naming an entry of the other kind, was asked at the current time,
  while trust is judged at a time-stamp's time. A leaf whose EKU only
  another entry allows could pass. Reasoned, not reproduced. Both now ask
  at the judged time.
- **The JSON trust lists (new in this release) could trust a removed
  anchor (SPEC-079 amendment 2).** Found before release: a certificate in
  two services, an `untrusted` and a `trusted` status at one instant, a
  fraction of a second, and a `ServiceHistory` that is not a list each
  gave `Trusted`. All now fail closed.

### Added

- **A validation time (SPEC-073).** `verify($stream, $settings,
  $validationTime)` and `bin/c2pa-verify --at <RFC 3339>`: the time to
  judge certificates, anchors and OCSP at where no trusted time-stamp
  gives one, in the active manifest and every ingredient. The report keeps
  it (`VerificationReport::$validationTime`). Without it, the clock is
  read once per verification.
- **crJSON (SPEC-074, SPEC-075, SPEC-076).** `VerificationReport::toCrJson()`
  and `--crjson`: every manifest with its claim, assertions, signature and
  its own validation results, as C2PA 2.4's crJSON describes them;
  schema-valid but for v1 claims and unreadable signatures.
  `Verifier::VERSION` names the generator.
- **The programme's trust lists (SPEC-077, SPEC-079).**
  `TrustSettings::fromTrustLists()`, `--trust-list` and `--tsa-trust-list`
  take the signer and TSA lists, as PEM or in the JSON form (ETSI TS 119
  602) with their dates: an `untrusted` status limits an anchor from its
  time on (`TrustAnchorSet::$windows`, `$reasons`). See
  `docs/conformance-harness.md`.
- **Fixtures signed with the conformance tool's public test certificate
  (SPEC-078)**, and `docs/conformance.md` brought up to date.

### Changed

- Of two assertion boxes with one label, every check reads the first, the
  one the claim's hash covers; the second is still `assertion.undeclared`
  (SPEC-007 amendment 9).
- Error lines on stderr escape control characters and bidirectional
  overrides (SPEC-019 amendment 4); `--at` refuses a time outside the
  years 0001–9999 in UTC (SPEC-073 amendment 2).
- `opis/json-schema` joins `require-dev`, for the crJSON tests only
  (ADR-0006); nothing new is required at run time.

Over the fixtures and corpora, against 0.7.1: 20 of 1,842 runs move, ten
files. By verdict only `c2pa-rs/video1.mp4` moves (its offset tables
refused); the other nine were refused already, and only the wording of
the refusal changed.

## 0.7.1 — 2026-10-22

A patch release: an ingredient failure counts as acknowledged only when the
signer recorded it as a failure. The public contract is unchanged.

### Changed

- **The list a recorded status is in counts (SPEC-070 amendment 2).** C2PA
  2.4 §18.16.12.4.3 compares a recorded status on its type (success,
  informational or failure), code and url. A v3 ingredient assertion that
  filed an ingredient's failure under `success` or `informational`
  acknowledged it here as if it were under `failure`, as in `c2pa-rs`,
  which derives the type from the code. Such an entry now excuses nothing:
  the failure stays, and the file is `Invalid`. `c2patool` 0.27.22 and
  0.28.2 still call these files `Trusted`. A v1/v2 `validationStatus` has
  no lists and is compared as before.

  Over the fixtures and corpora, no verdict moves between 0.7.0 and 0.7.1;
  no real file was found that files a failure in another list.

## 0.7.0 — 2026-10-13

A minor release. A data hash or a BMFF hash may no longer leave the image
or the media outside what is signed. Pixel Camera videos verify. Ingredient
failures that a signer acknowledged are reported. The public contract grows
by one property, `ValidationStatus::$acknowledged`.

### Security

- **What a hash may exclude (SPEC-068, SPEC-069, SPEC-071).** D. Buchanan's
  "How to Hack Time, With C2PA" (2 October 2026) signs one exclusion over a
  whole file. That shape was already refused here (SPEC-012 amendment 7),
  but the same effect survived. A `c2pa.hash.data` that excluded
  everything but the manifest store, or only the PNG image data, and a
  `c2pa.hash.bmff` that excluded `/mdat` or `/moov`, were `Trusted` here
  with a pixel or a sample changed after signing. `c2patool` 0.28.1 and
  0.28.2 still call all of these `Trusted`. Now:
  - a data hash that leaves no byte of the file is
    `assertion.dataHash.mismatch` (SPEC-068);
  - an exclusion beyond the manifest store is allowed only wholly inside
    the payload of a JPEG APP1 Exif or XMP segment of the primary image,
    which is what the Google Pixel 10 writes, and nowhere in any other
    format (SPEC-069);
  - a BMFF exclusion is allowed only for what `c2pa-rs` and Pixel Camera
    write: the C2PA `uuid` box, `ftyp`, `mfra`, `free`, `skip`,
    `meta/iloc`, `mfra/tfra`, `moov/free`, `moov/udta`, `moov/meta`, the
    offset fields of `stco`, `co64`, `tfhd` and `trun` from offset 16, and
    a track excluded by index only when its handler is timed metadata
    (SPEC-071, SPEC-072);
  - anything else is a mismatch, judged by where the range or path lies,
    before the file is hashed.

  Over the fixtures and corpora, no verdict moves between 0.6.1 and 0.7.0.
  A writer that excludes something else will now get `Invalid` here. The
  lists grow only by amendment, with a real file.

### Added

- **Pixel Camera video (SPEC-072).** A QuickTime-style `meta` box (no
  version and flags) is read, and indexed xpath steps (`/moov[1]/trak[3]`,
  1-based, as `c2pa-rs` 0.91) are resolved. A Pixel Camera video was a
  wrong `Invalid` here, and now verifies as it does in `c2patool`. A
  malformed index is `assertion.bmffHash.malformed`.
- **`ValidationStatus::$acknowledged` (SPEC-070).** A failure an
  ingredient assertion recorded (C2PA 2.4 §18.16.12.4) used to be dropped
  from the report, as `c2pa-rs` does. It is now kept, marked as
  acknowledged, explained as *"acknowledged by the signer of … not counted
  in the verdict"*, and rendered under that ingredient's
  `validationDeltas.informational`. The verdict is unchanged, and
  `validation_status` (failures only) is unchanged. **A caller that walks
  `$report->result->statuses` and asks `$status->code->isFailure()` must
  now skip `$status->acknowledged`.**

### Changed

- The informational for an allowed exclusion says that the excluded bytes
  are not signed: Exif or XMP values such as a GPS position or a capture
  time (SPEC-069 amendment 1, after Golaszewski et al.'s Pixel 10 Pro GPS
  alteration), and `udta`, `meta` or a timed-metadata track in a video
  (SPEC-072).

## 0.6.1 — 2026-10-10

A patch release: icons in the data boxes of earlier C2PA versions are read
and checked, where they were refused. The public contract is unchanged.

### Changed

- **An icon in a data box is read, and its hash checked (SPEC-067).** C2PA
  2.4 §10.2.3.2 asks consumers to keep supporting the data boxes of earlier
  versions, and this verifier refused them (SPEC-034) until a real file had
  one: the Drupal module C2PA Sign 1.4.11, which signs with `c2patool`
  0.9.12, stores a site's logo as `c2pa.databoxes/c2pa.data` in every
  manifest it writes. Such a file was `Invalid` here and `Trusted` in
  `c2patool` 0.28.1. The `c2pa.databoxes` store is now walked, an icon url
  naming a box of its own manifest resolves, and the box's payload must hash
  to the icon's hash; a box that is not there stays `assertion.missing`, and
  changed bytes are `assertion.hashedURI.mismatch`. `c2pa-rs` checks neither:
  `c2patool` 0.28.1 reads three such altered variants as `Trusted`. Over the
  corpus, only the five new data-box fixtures move. The public contract is
  unchanged.

## 0.6.0 — 2026-10-09

The whole of C2PA 2.4 read against the verifier (`docs/reading-c2pa-2.4.md`):
562 rules, each now covered, partly covered, not applicable or different by
design, except eight ISOBMFF details that stay open. What the reading found
too lenient was measured with probes against `c2patool` 0.27.22 and 0.28.1
and now agrees with the specification. Where the specification is stricter
than `c2patool`, this verifier follows the specification, and
`docs/comparison.md` names each case. Five validator rules that neither
this verifier nor `c2patool` applied are now checked: cloud data, the
time-stamp assertion, the original preservation image, the revocation of a
CA, and certificate-status assertions.

No real file in the test corpus changes its verdict; 38 probes made for
these rules do.

**A `0.6`, not a `0.5.4`:** the public `StatusCode` grows by nine cases
(the recorded API, 126 symbols in 0.5.3, is 135). A caller that matches
every case of `StatusCode` needs to know the new ones. `^0.5` keeps 0.5.3.


### Added

- **The cloud-data assertion is checked (SPEC-063).** C2PA 2.4
  §15.10.3.2.1: a `c2pa.cloud-data` assertion without `label`, `size` or a
  hashed `location`, or one standing for a hard binding, an actions,
  ingredient or cloud-data assertion, is refused with
  `assertion.cloud-data.malformed`, `.hardBinding` or `.actions`. These
  are three new status codes. Nothing is fetched.
- **Plain text names its two failures (SPEC-060 amendment 3).** C2PA 2.4
  §15.12.1.3 and A.8.7.1: a corrupted text wrapper (another version, or a
  length that does not fit) is `manifest.text.corruptedWrapper`, and two
  wrappers are `manifest.text.multipleWrappers`, where `general.error` was
  reported. A wrapper of another version is no longer read as plain text.
  These are two new status codes; no verdict changes.
- **The time-stamp assertion is read (SPEC-064).** C2PA 2.4 §15.8.1.2: a
  later manifest's `c2pa.time-stamp` assertion gives an earlier manifest a
  trusted time when its own header token is absent or did not pass, so a
  manifest stamped while its signer was valid is no longer called expired.
  A header token that passed still stands. A malformed time-stamp assertion,
  or a second one in a manifest, is `assertion.timestamp.malformed` (a new
  status code), and its tokens are not used.
- **The original preservation image is checked (SPEC-065).** C2PA 2.4
  §15.10.3.2.7: a `c2pa.alternative-content-representation` of type
  `exif.originalPreservationImage` holds exactly one of a multi-asset part
  index (inside the parts of the active manifest's multi-asset hash) and an
  embedded reference whose hash matches; at most one per manifest. Three
  new status codes: `assertion.alternativeContentRepresentation.malformed`,
  `.hashMismatch` and `.match`. `c2patool` does not check the assertion.
- **Revocation beyond the signer's own staple (SPEC-066).** C2PA 2.4 §15.9:
  a stapled OCSP response that verifiably reports a CA of the path revoked
  leaves the signer untrusted (`signingCredential.untrusted`). The OCSP
  responses of `c2pa.certificate-status` assertions in the store are used
  for the signers they name, the active manifest's and an ingredient's.
  Nothing is fetched; `c2patool` uses neither.

### Changed

- **A signer certificate with an issuerUniqueID or subjectUniqueID is
  refused (SPEC-015 amendment 9).** C2PA 2.4 §14.5.1.1 does not allow these
  fields. A leaf that carried one was `Trusted` here and `Invalid`
  (`signingCredential.invalid`) in both `c2patool` versions. Found by
  reading the specification beside the verifier
  (`docs/reading-c2pa-2.4.md`).
- **A timestamp header with more than one token gives no time (SPEC-017
  amendment 9).** C2PA 2.4 §15.8.1.1 calls it malformed. This verifier
  used the first token, so a signer that had expired stayed `Trusted` on a
  trusted token; both `c2patool` versions say `Invalid`. Now the header is
  `timeStamp.malformed` and the signer is judged at the current time.
- **A BMFF hash without its own `alg` uses the claim's (SPEC-027 amendment
  8).** It fell back to SHA-256, unlike a data hash, the specification and
  `c2patool`.
- **A merkle map needs a `count` of at least 1 (SPEC-028 amendment 2).**
  Without one, an init segment alone matched as "0 of 0 fragments" and was
  `Trusted`; both `c2patool` versions refuse it. It is
  `assertion.bmffHash.malformed` now.
- **A signer's chain under label 33 in both COSE headers is refused
  (SPEC-047 amendment 2).** C2PA 2.4 §14.5 calls it malformed: two
  credentials. Only the `"x5chain"` label was caught there before. Both
  `c2patool` versions accept it.
- **A manifest signer's certificate must assert Digital Signature (SPEC-015
  amendment 10).** C2PA 2.4 §14.5.1.1. A KeyUsage of Non Repudiation alone
  was accepted, as `c2patool` accepts it; it is `signingCredential.invalid`
  now. A timestamp authority's certificate keeps the old rule.
- **Every certificate above the signer meets the C2PA certificate profile
  (SPEC-014 amendment 8).** C2PA 2.4 §14.5.1.1 says "all certificates".
  An intermediate or trust anchor with an RSA key under 2048 bits, a curve
  outside P-256/384/521, a signature algorithm outside the list, a version
  other than 3, or no Authority Key Identifier (unless self-signed) now
  reaches no anchor: `signingCredential.untrusted`. `c2patool` checks the
  signer's certificate only. No real file in the test corpus moves.
- **Four manifest rules from C2PA 2.4 §8.1 and §11.2.2 (SPEC-007
  amendment 7).** A manifest box of type `c2md` is read as a manifest. A
  store with two manifests of one label is `claim.malformed`. A version 2
  manifest whose label is neither a C2PA URN nor the deprecated `urn:uuid`
  form (Appendix C: validators are encouraged to accept it; SPEC-007
  amendment 8) is `claim.malformed`. So is a version 2 claim whose
  `claim_generator_info` is an empty map.

## 0.5.3 — 2026-10-09

A consolidation release. Four rules that a trust matrix, a timestamp
matrix and fuzzing with trust settings found too lenient now agree with
`c2patool` 0.28.1, or are stricter where named in `docs/comparison.md`.
Two of them gave `Trusted` for a certificate issued as it is, with no byte
changed: a leaf signed with RSASSA-PSS over SHA-224, or with an MGF1 hash
other than its PSS hash. Both `c2patool` versions say `Invalid`. The
others need a certificate or a manifest box that breaks a rule of
RFC 5280 or of the C2PA specification.

**A `0.5.3`, not a `0.6`:** the recorded public API is the same 126
symbols, and there is no new status code. Seven files of `src/` changed.

### Changed

- **A timestamp authority's certificate that fails the certificate profile
  makes the file `Invalid` (SPEC-017 amendment 8).** A version 2 claim
  whose timestamp comes from a TSA whose leaf has no keyUsage, is a CA, or
  is signed over SHA-1 was `Trusted` or `Valid` here, the timestamp
  ignored; `c2patool` 0.28.1 says `Invalid` with
  `signingCredential.invalid`, and so does this verifier now, naming the
  timestamp authority in the explanation. Found by the timestamp matrix
  (`bin/make-tsa-matrix.php`). No file of the corpus moves.
- **A JUMBF description box without Requestable is an error (SPEC-005
  amendment 2).** The parser checked Label Present but not Requestable,
  though SPEC-005 asked for both (C2PA 2.4 §11.1.4.1.2). A signed file
  whose store, manifest, assertion store, claim or signature box had
  Requestable cleared stayed `Trusted`; both `c2patool` versions refuse
  such a file. No signed byte went unchecked. Found by fuzzing with trust
  settings (`bin/fuzz.php --trust`). No file of the corpus moves.
- **A certificate serial number that is not positive is refused (SPEC-015
  amendment 7).** RFC 5280 §4.1.2.2 asks for a positive integer. A leaf
  with a negative or zero serial is now `signingCredential.invalid`; any
  other certificate of the path with one makes the chain untrusted. Before,
  such a serial was converted without its sign (with a PHP deprecation
  notice, which broke the command's JSON when `display_errors` was on).
  A stapled OCSP response about such a certificate could not be read, so
  a revocation was skipped. Both `c2patool` versions accept these serials;
  this verifier is stricter here (`docs/comparison.md`). Found by fuzzing
  ISOBMFF. No file of the corpus moves.
- **A leaf signed with RSASSA-PSS must name SHA-256, SHA-384 or SHA-512
  and the same hash for MGF1 (SPEC-015 amendment 8).** A leaf signed with
  PSS over SHA-224, or with an MGF1 hash other than the PSS hash, was
  `Trusted` here and `Invalid` in both `c2patool` versions. A leaf whose
  outer `signatureAlgorithm` was changed after signing was `Valid` here.
  All are `signingCredential.invalid` now. Stricter than `c2patool` on
  two cases (`docs/comparison.md`): the default MGF1 (SHA-1) under a
  SHA-256 PSS hash, and two copies of the algorithm that differ. Found by
  fuzzing with trust settings.

## 0.5.2 — 2026-10-08

A security release, the second of the day. A certificate authority
without a keyUsage extension could issue, and a chain through it was
`Trusted`. It was found by the trust matrix, the first step of a
consolidation after 0.5.1. It needs a certificate authority without
keyUsage under one of your anchors, or as one; none of the anchors in
the C2PA trust lists of 2026-08-14 lacks it.

**A `0.5.2`, not a `0.6`:** the recorded public API is the same 126
symbols, and there is no new status code. One file of `src/` changed.

### Security

- **A certificate authority without keyUsage no longer issues (SPEC-014
  amendment 7).** An intermediate or an anchor that carried no keyUsage
  extension could issue certificates, and the chain was `Trusted`; both
  `c2patool` versions and OpenSSL refuse it. Every issuing certificate now
  needs keyUsage with `keyCertSign` (RFC 5280 §4.2.1.3). Present in 0.2.2
  to 0.5.1. Found by the trust matrix (`bin/make-trust-matrix.php`).

## 0.5.1 — 2026-10-08

A security release. A trust anchor outside its own validity period still
vouched for a signer: a chain under a root that had expired, or that was
not yet valid, was `Trusted`. Present in every release up to 0.5.0;
reported privately. It needs a configured anchor that is out of date and
a signing key under it. Every user who configures trust anchors should
upgrade.

**A `0.5.1`, not a `0.6`:** no class, method, member or settings shape
changes, the recorded public API is the same 126 symbols, and there is no
new status code. What changes is the verdict on the files described below.

### Security

- **A trust anchor is judged at the time the signer is judged (SPEC-014
  amendment 5).** The chain walk checked the validity of every x5chain
  intermediate, but not of the anchor it reached. A chain under an anchor
  that expired years ago, or one that is valid only from some date in the
  future, was `Trusted`; so was a chain under an expired intermediate
  configured as the anchor, and a chain that carried the expired root in
  x5chain as well. The anchor must now be valid at a trusted timestamp's
  time, or else now, the same time an intermediate is judged at; otherwise
  `signingCredential.untrusted`, naming the anchor and its validity
  period. The timestamp authority's chain gets the same rule, at the
  timestamp's time. `c2patool` 0.28.1 says `untrusted` on each probe;
  0.27.22 says `Trusted`. Present in 0.1.0 to 0.5.0.

### Changed

- **Only the active manifest's own signer makes a file `Trusted`
  (SPEC-014 amendment 6).** The state counted a `signingCredential.trusted`
  reported for an ingredient as much as the active manifest's own, where
  `c2pa-rs` counts the active manifest's only. Not reachable in any
  release, because the active manifest always reports its own trust; the
  rule no longer depends on that.

### Notes for users

- If you configured DigiCert's **cross-certificate** for `DigiCert Trusted
  Root G4` (issued by `DigiCert Assured ID Root CA`, valid from
  2022-08-01) instead of the self-signed root that
  [`docs/trust-settings.md`](docs/trust-settings.md) recommends, DigiCert
  timestamps from before August 2022 are now `timeStamp.untrusted`. The
  self-signed root (valid from 2013) is not affected. This project's own
  test fixture was the cross-certificate and is now the root (SPEC-017
  amendment 7).
- Adobe's signing certificate `CN=Adobe C2PA` that Lightroom used in 2026
  expired on 2026-10-07. Without a DigiCert anchor for its timestamps this
  verifier judges such a file at now and reports
  `signingCredential.expired` (`Invalid`), where `c2patool` says `Valid`
  (ADR-0004). With the DigiCert root from `docs/trust-settings.md` it is
  `Valid`. This is not a change in 0.5.1; it happened to 0.5.0 users on
  that day.

## 0.5.0 — 2026-10-07

Plain text, read and verified when the caller asks for it, and measured
against the only oracle there is: `c2patool` 0.28.1 built with `c2pa-rs`'s
experimental `unstable_plain_text`. Nothing changes for a caller who does
not turn it on: every report is the same as under 0.4.0 (the corpus and
the same fuzzing seed agree). The recorded API surface is unchanged; the
`Verifier` constructor has a new last parameter and `Cli\Command` a new
optional one, so this is a minor version: a caller on `^0.4` keeps 0.4.0
until it asks for `^0.5`.

### Added

- **Plain text, opt-in** (SPEC-060; C2PA 2.4 §A.8): a text that carries its
  manifest store as invisible Unicode variation selectors is read and
  verified when the caller turns text on — `new Verifier(text: new
  PlainTextManifestStoreExtractor)`, or `c2pa-verify --text`. Off by
  default: the scheme is experimental in `c2pa-rs` and stock `c2patool`
  does not read it. Measured against `c2patool` 0.28.1 built with
  `unstable_plain_text` (step 265). The report's `format` has a new value,
  `text`, only when text is on; the `Verifier` constructor has a new last
  parameter, `null` by default, and `Cli\Command` an optional second one.
  The wrapper must be the only one, of version 1, with a store that fits
  its run and whose LBox equals the length field; anything else of its
  magic is `general.error`, stricter than the oracle and named in
  `docs/comparison.md`. The text is hashed byte for byte, without Unicode
  normalisation, as `c2pa-rs` verifies it. A run is decoded a buffered
  stretch at a time: a 16 MiB store with as much padding takes 0.4 s
  (amendment 2, after a review).

## 0.4.0 — 2026-10-06

One new format, GIF, read and verified like the others and measured
against `c2patool` 0.27.22 and 0.28.1; verifying a manifest that is
checked more than once is faster. The recorded API surface is unchanged;
the report's `format` has a new value and the `Verifier` constructor a new
last parameter, so this is a minor version: a caller on `^0.3` keeps 0.3.0
until it asks for `^0.4`.

### Added
- **GIF (SPEC-059).** A GIF whose manifest store sits in a `C2PA_GIF`
  Application Extension (C2PA 2.4 §A.3.8) is read and verified like the
  other formats; `format` is `gif`. The blocks before the first image are
  walked, each extension as sub-blocks after its label; the block is
  recognised by its size, identifier and version `01 00 00` together, its
  sub-blocks are joined, and the whole block is the store's range, as the
  exclusion `c2patool` writes. Two blocks (an empty one counts), a
  malformed block, more than 4,096 blocks before the first image or a
  store cut short are `general.error`. Measured against `c2patool` 0.27.22
  and 0.28.1 over 21 variants and five more GIF shapes signed by both
  (steps 256–261). The `Verifier` constructor takes the GIF reader as its
  new last parameter (SPEC-013 amendment 22).

### Changed
- Verifying is faster where a manifest is checked more than once: its
  signature is decoded once and its certificate chain built once (steps
  253–255, no change in what is reported).
- **`bin/package-check.php`: the dist ceiling is 16 MB (SPEC-023 amendment
  3)**, and the check also shows the size of the zip Composer fetches;
  a zip over 5 MB is a finding that returns the question of what the
  package ships to the maintainer. The 0.3.0 dist: 3.8 MB as tar, 1.3 MB
  as zip.

## 0.3.0 — 2026-10-05

Four new formats — WAV, AVI, MP3 and FLAC — read and verified like the
others, each measured against `c2patool` 0.27.22 and 0.28.1 and, where they
exist, against files from other writers. On the way, every container
became stricter about its C2PA store and as lenient as `c2patool` about the
rest, and `has_manifest` now says a manifest is there only when the store
was reached. The recorded API surface is unchanged; the report's `format`
has four new values, so this is a minor version: a caller on `^0.2` keeps
0.2.9 until it asks for `^0.3`.

A review of the whole of `src/` before the release found one wrong
`Trusted`, present since 0.2.5, and a report that a single assertion could
stop from being written. Both are fixed here; there is no 0.2.x release
with them, so every user of 0.2.5 to 0.2.9 should move to 0.3.0.

### Security
- **A name constraint over a name that is not UTF-8 (SPEC-046 amendment
  1).** Such names were all folded to the empty string, so a leaf outside
  a name-constrained intermediate's permitted directoryName could be
  `Trusted`. They are now compared byte for byte. Measured on a throw-away
  hierarchy against OpenSSL and `c2patool` 0.28.1; present in 0.2.5 to
  0.2.9.
- **A CBOR NaN or infinity in an assertion (SPEC-007 amendment 6, SPEC-019
  amendment 2).** The report could not be encoded: `toJson()` threw, and
  `bin/c2pa-verify` died with PHP's fatal error and a stack trace on
  standard error (exit 255). Such a value is now rendered as `"NaN"`,
  `"Infinity"` or `"-Infinity"`; a report that still cannot be encoded is
  `Error: …` and exit 2. The fuzzer now encodes every report.
- **Bytes that are not UTF-8 in an explanation (SPEC-043 amendment 3).**
  A damaged KeyUsage extension reached the explanation of
  `signingCredential.invalid` as raw bytes, with the same effect, found by
  the fuzzer once it encoded every report. Every explanation now replaces
  such bytes with `?` when it is made.

### Fixed
- **PS256/384/512 under an `id-RSASSA-PSS` key (SPEC-009 amendment 3):**
  any salt length was accepted; RFC 8230 §2 fixes it at the hash length,
  as was already required under an ordinary RSA key.
- **A hard binding under an instance label (SPEC-012 amendment 9):**
  `c2pa.hash.data__1` (C2PA 2.4 §6.4) is a hard binding. Alone it is
  verified, as `c2patool` verifies it (it was `claim.hardBindings.missing`);
  beside another it is `assertion.multipleHardBindings`. Hard bindings of
  every kind are now counted before the data-hash or the BMFF check is
  chosen, so a second one is never passed over.
- **A stream that cannot seek (SPEC-043 amendment 2):** `verify()` still
  refuses it with `InvalidArgumentException`, now without printing PHP's
  `rewind()` warning first.

### Added
- **WAV (SPEC-055).** A WAV whose manifest store sits in a RIFF `C2PA`
  chunk (C2PA 2.4 §A.3.7) is read and verified like the other formats:
  signature, data hash, chain, trust and timestamp. The report's `format`
  is `wav`. The chunk is read under SPEC-003's rules for WebP, whose walk
  WebP and WAV now share. A `C2PA` chunk that is not the last is read and
  left to the data hash, as `c2patool` does. RF64 (WAV over 4 GB) and a
  `C2PA` nested in a `LIST` chunk are not read, as in `c2patool`.
  Measured against `c2patool` 0.27.22 and 0.28.1 (steps 203–204), and
  held against the WAVs of `contentauth/c2pa-rs` and
  `contentauth/c2pa-python` too (step 210: a file signed through the C
  binding, an oversized RIFF size, a 1,000-deep `LIST` bomb). Memory stays
  flat on large files: 33 MB peak at 2 GB (step 211).

- **MP3 (SPEC-056).** An MP3 whose manifest store sits in an ID3v2 tag's
  GEOB frame with the MIME type `application/c2pa` (C2PA 2.4 §A.3.4) is read
  and verified like the other formats; `format` is `mp3`. ID3v2.3 and v2.4,
  every text encoding, an extended header, a footer and padding are read;
  a C2PA GEOB that is compressed, unsynchronised, duplicated, too long for
  its tag, or whose LBox differs, is refused. A file is `mp3` when MPEG
  audio follows the tag (after any zero padding and further tags) or
  opens the file (two frame headers); a tagless MP3 has no manifest. The
  GEOB's MIME type may be `application/c2pa` or the legacy
  `application/x-c2pa-manifest-store`, the two `c2patool` accepts; iTunes
  frame sizes are read; a C2PA GEOB under a grouping flag is refused; a tag
  that runs past the end of the file, or an ID3v2.2 tag, is reported as MP3
  with the reason. Measured against `c2patool` 0.27.22 and 0.28.1 over
  35 variants, six real tags signed by `c2patool`, an MP3 signed by c2pa-ts
  (an independent writer) and a 300 MB file (steps 225, 230, 232).

- **AVI (SPEC-058).** The third RIFF form C2PA 2.4 §A.3.7 names, read with
  the WebP and WAV walk; `format` is `avi`. An OpenDML AVI over 1 GB, several
  RIFF chunks in a row (`AVI ` then `AVIX`), is read from the first chunk and
  the rest is hashed, as `c2patool` does. Measured against `c2patool`
  0.27.22 and 0.28.1 (steps 209, 239).
- **FLAC (SPEC-057).** C2PA puts FLAC's store in the same ID3v2 tag as
  MP3's, in front of the FLAC stream (C2PA 2.4 §A.3.4); it is read by the
  MP3 reader and verified like the other formats; `format` is `flac`. A
  file is FLAC when `fLaC` opens it or follows the tag(s); an untagged FLAC
  has no manifest. Measured against `c2patool` 0.27.22 and 0.28.1 (step 234).

### Changed
- **WebP and WAV: strict about the `C2PA` chunk, lenient as `c2patool`
  about the rest (SPEC-003 amendment 3, SPEC-055 amendment 3).** Bytes
  after the RIFF chunk (an ID3v1 tag on a WAV, for instance), a header size
  that ends before the file does, and a pad byte after another chunk that
  is missing at the end or not zero are no longer container faults; the
  data hash still covers every one of those bytes in a signed file. An
  unsigned WAV or WebP with such a quirk was `Invalid` with
  `has_manifest: true` and is now a file without a manifest, as `c2patool`
  says. Everything about the `C2PA` chunk itself stays as strict as before.
- **`has_manifest` after a RIFF container fault (SPEC-013 amendment 16)**
  is `true` only when the walk had reached a `C2PA` chunk. A truncated
  WebP or WAV, or one whose header size exceeds the file, is still
  `Invalid` with `general.error`, now with `has_manifest: false`. Since
  SPEC-013 amendment 18 the same holds for JPEG, PNG and ISOBMFF: the store
  is reached at an APP11 JUMBF piece, a `caBX` chunk, a C2PA `uuid` box (even
  one whose size field is broken). No verdict changes. A file whose header size exceeds the file is
  reported with `has_manifest: true` when its `C2PA` chunk header is
  there (a signed file cut short), found by scanning the chunk headers
  only (SPEC-003 amendment 4, SPEC-013 amendment 17).
- **The edges of that leniency (SPEC-003 and SPEC-055 amendment 4).** A
  short tail inside the RIFF chunk, or a chunk other than `C2PA` that runs
  past its end, stops the walk, as `c2patool`'s does, instead of being a
  fault; a header size below 4 is now refused.
- The unknown-format explanation names WAV, AVI, MP3 and FLAC among the formats read.
- **A bound on the RIFF walk (SPEC-003 amendment 5):** more than 4,096
  top-level chunks in a WebP, WAV or AVI is refused, as ISOBMFF's 4,096
  boxes and ID3's 4,096 frames are; a file of empty chunks otherwise cost
  about 90 s per GB.
- A RIFF file with the form type `WAVE` that was `unknown` before is now a
  WAV. The one such file among the fixtures, a WebP with its form type
  changed (`webp/riff-not-webp.webp`), is now `Invalid` with
  `assertion.dataHash.mismatch`, as `c2patool` says, instead of
  `general.error`.
- The dist no longer carries the fixture builders (`bin/make-*.php` and
  `bin/variant-helpers.php`): they read `tests/Fixtures/`, which never
  shipped, so they could not run from `vendor/`. The dist stays under
  SPEC-023's 4 MB ceiling (SPEC-023 amendment 2).

### Known limits
- A FLAC cut short inside its ID3 tag is reported as `mp3`: what follows
  the tag cannot be seen. Its verdict, `Invalid`, is right.
- A JPEG with a JUMBF box in APP11 that is not C2PA (JPEG 360, JPEG XT),
  followed by a broken segment, reports `has_manifest: true`.
- `Verifier`'s constructor takes its extractors as `@internal` parameters;
  a caller who passed a WebP extractor with tighter limits gets the
  default limits for WAV, AVI, MP3 and FLAC, whose extractors are new
  trailing parameters.

## 0.2.9 — 2026-10-02

One addition and no fix: `requirements.php`, a file a host on older PHP
can read before it loads `src/`. Nothing in `src/` changed since 0.2.8, so
no verdict, report, class, method, status code or settings shape moved.

### Added
- **A requirements check older PHP can read (SPEC-054).** Everything in
  `src/` needs PHP 8.3, and on anything older requiring one of its files is
  a fatal parse error a host cannot catch. `requirements.php` at the
  package root is written in PHP 7.4 syntax, declares nothing and returns a
  closure answering `supported`, `missing` (`php>=8.3`, `ext-openssl`,
  `ext-mbstring`) and `ed25519`. A host that also runs on PHP 7.4 to 8.2,
  such as a WordPress plugin, asks it first; README "Before loading". It
  is part of the public API, and CI runs it on PHP 7.4, 8.0, 8.1 and 8.2.

## 0.2.8 — 2026-09-30

Three fixes from a review of `src/`, one of them a wrong `Trusted`. No
class, method, member, status code or settings shape changed. The
verdicts that move: an ingredient reference whose hash algorithm is not
the one `c2patool` uses goes from `Trusted` to `Invalid`, as `c2patool`
says; a BMFF hash with a merkle map naming an unknown algorithm, or with
more exclusions than the new limits, is reported as `Invalid` instead of
crashing or stalling. No corpus file's verdict moved.

### Fixed
- **The merkle map names one of the three hash algorithms (SPEC-051).** A
  fragmented ISOBMFF file's merkle map may name its own `alg`, and that
  name went unchecked to PHP's `hash_init()`. Six edited bytes in an
  existing file (`sha256` → `fooooo`) made `verify()` throw a `ValueError`
  instead of returning a report; no key was needed. A name PHP knows but
  C2PA does not (`crc32b`) was computed, and an `alg` that was not text
  fell back silently to the assertion's. Now each is
  `assertion.bmffHash.mismatch`, and the digest itself refuses any
  algorithm but `sha256`, `sha384` and `sha512`. Never a wrong `Valid`: the
  crash replaced the report.
- **An ingredient reference is hashed as `c2patool` hashes it (SPEC-052).**
  The manifest box hash was computed with the algorithm the reference
  named, held only to what PHP can compute. A signed reference naming
  `crc32b`, with the right `crc32b`, was `Trusted`; `c2patool` 0.27.22 says
  `Invalid`. Now the box hash uses the ingredient claim's algorithm, as
  `c2pa-rs` does, and every route holds to `sha256`, `sha384` and `sha512`.
  A reference that names another algorithm than its ingredient claim is
  `ingredient.manifest.mismatch`, as at `c2patool`; the ingredient check no
  longer reports `algorithm.unsupported`.
- **A BMFF hash's exclusions are bounded (SPEC-053).** Planning which
  bytes a BMFF hash covers cost boxes × boxes × exclusions, with no limit:
  an MP4 with 4000 empty boxes and 8 exclusions took 11.4 s to verify, and
  larger ones ran out of memory, with no key needed. Now an assertion
  with more than 64 exclusions, an exclusion with more than 64 subsets,
  or a plan of more than 4096 excluded ranges is
  `assertion.bmffHash.malformed`, before any hashing. Real files use at
  most 8 exclusions and 2 subsets.

## 0.2.7 — 2026-09-30

A fix for callers that pass a stream that is not a plain local file. No
class, method, member, status code or settings shape changed, and no
verdict on a local file moved.

### Fixed
- **A short read is not the end of the file (SPEC-050).** PHP's `fread()`
  may return fewer bytes than asked on a stream that is not a plain file,
  such as a stream wrapper. Five read sites took that for the end of the
  file, so a genuine file read through such a stream came out `Invalid`
  ("unexpected end of file") or "unsupported file type". Measured with the
  AWS SDK's S3 stream wrapper, as WP Offload Media hands it out. Every read
  now asks again until it has the bytes or the stream gives nothing more; a
  stream that gives nothing is treated as ended, so the verifier never
  waits forever. Never a wrong `Valid`: a short read only ever removed
  bytes.

## 0.2.6 — 2026-09-28

A security release for one wrong `Trusted`, found by reading the
verification fixes of c2pa-rs 0.91.1 against this verifier. No class,
method, member, status code or settings shape changed. Every user should
upgrade.

### Security
- **An RSA key needs a real public exponent (SPEC-049).** With public
  exponent 1, raising a signature to the exponent changes nothing, so a
  PS256 claim verified without the private key, and a leaf with such a
  key could come out `Trusted`. An exponent below 3, an even exponent and
  a negative one are now `signingCredential.invalid` in the leaf. Present
  in 0.1.0 to 0.2.5. `c2patool` 0.27.22 and 0.28.0 give the same wrong
  `Trusted`; c2pa-rs 0.91.1 refuses it (CAI-13156).

### Changed
- **The same exponent rule between the anchor and the leaf (SPEC-049).**
  An intermediate with such a key makes the path
  `signingCredential.untrusted`, since anyone could issue certificates
  under it. Stricter than `c2patool` and c2pa-rs 0.91.1, which check the
  leaf only.
- The exponent is read from the key's subjectPublicKeyInfo, so RSA keys
  with the `id-RSASSA-PSS` algorithm are covered too; PHP reports no RSA
  details for them.

## 0.2.5 — 2026-09-27

A security release for four wrong `Trusted` in the certificate chain,
found by measuring the lower findings of the review of 2026-09-27, and for
three refusals that are stricter than `c2patool` by the maintainer's
decision. No class, method, member, status code or settings shape
changed. Every user should upgrade.

### Security
- **Name constraints are enforced (SPEC-046).** A subordinate CA limited
  to one organisation's names could issue a certificate naming any
  organisation, and the file was `Trusted`. `directoryName` and
  `rfc822Name` constraints are now evaluated as RFC 5280 §4.2.1.10 says;
  a leaf outside them is `signingCredential.untrusted`. Present in 0.1.0
  to 0.2.4. Both `c2patool` versions say `untrusted`.
- **An unknown critical extension stops the path (SPEC-046).** A
  certificate's extensions are read from its DER with their critical flag.
  One this verifier does not process is `signingCredential.untrusted`
  anywhere in the path and also `signingCredential.invalid` in the leaf,
  as both `c2patool` versions give it. Present in 0.1.0 to 0.2.4.
- **The signer's chain is looked for where it may be (SPEC-047).** A
  chain under label 33 in the unprotected header, and a chain in both
  headers, were read and could be `Trusted`. Both are now refused, as
  `c2patool` refuses them. Present in 0.1.0 to 0.2.4.

### Changed
- **In a claim v2 or later, the chain must be in the protected header
  (SPEC-047).** The unprotected header is not signed, so a chain there
  could be exchanged for another certificate for the same key, and that
  certificate was shown as the signer. Such a file is now
  `signingCredential.invalid`. A claim v1 keeps the older form. Stricter
  than `c2patool`.
- **No MD5 or SHA-1 signature between the anchor and the leaf
  (SPEC-048).** RSASSA-PSS counts by its hash, and an absent hash is
  SHA-1. Such a path is `signingCredential.untrusted`, and a PSS leaf over
  SHA-1 is `signingCredential.invalid`. Stricter than OpenSSL and
  `c2patool`.
- **A name constraint of another form (`dNSName`, URI, IP address) fails
  closed (SPEC-046).** Stricter than OpenSSL, which evaluates them.

The differences are named in `docs/comparison.md`.

## 0.2.4 — 2026-09-27

A security release for two wrong verdicts and four inputs that exhausted
memory or time. All six were found in a review from the side of the first
plugin that bundles this library. No class, method, member, status code
or settings shape changed. Every user should upgrade.

### Security
- **Only a manifest the ingredient graph reaches may redact (SPEC-035
  amendment 5).** Redactions were collected from every manifest in the
  store, including one that nothing references and that is never
  validated. An unsigned manifest of that kind could declare an assertion
  of the active manifest redacted, and with that box removed the file
  stayed `Valid`, or `Trusted`. Present in 0.2.1 to 0.2.3. Both `c2patool`
  versions say `Invalid` (`assertion.missing`), and so does this verifier
  now.
- **Under the legacy `trust.trust_anchors`, a certificate whose only EKU
  is Time Stamping no longer signs a `Trusted` manifest (SPEC-031
  amendment 3).** That field anchors time-stamping authorities as well as
  signers, so a TSA key could sign content that came out `Trusted`. Such a
  signer is now trusted only by a `"manifest"` entry of `trust.anchors` or
  the allowed list. Both `c2patool` versions still call it `Trusted`; the
  difference is named in `docs/comparison.md`.

### Fixed
- **Four inputs no longer exhaust memory or time, or throw (SPEC-045).**
  A JSON assertion over 256 KiB is refused before it is decoded, and JSON
  items count against the manifest store's CBOR item budget (a 4 MB one
  was fatal at 256M). An assertion that the claim names many times is
  hashed once (1,000 references to 8 MB took 20.6 s). Every chunk of an
  indefinite-length CBOR string costs an item (14 million empty chunks in
  the unsigned COSE header took 47 s). An empty embedded-file description
  box is `Invalid` instead of a `ValueError` that reached the caller.

## 0.2.3 — 2026-09-25

A security release for one wrong `Trusted`, found while measuring the
verifier in PHP compiled to WebAssembly. No class, method, member, status
code or settings shape changed. Every user should upgrade.

### Security
- **An expired certificate whose notAfter carries a fraction of a second
  is expired (SPEC-044).** PHP's `openssl_x509_parse()` misreads such a
  time: `20250101000000.5Z` became 2500-12-31, so a certificate that
  expired on 2025-01-01 was `Trusted`. RFC 5280 forbids the fraction, so
  only a CA that breaks it can issue one. Present in 0.1.0 to 0.2.2. Both
  `c2patool` versions say `signingCredential.expired`, and so does this
  verifier now.

### Fixed
- **A certificate's validity no longer depends on the runtime (SPEC-044).**
  Under PHP compiled to WebAssembly (php-wasm), the same PHP function
  shifted the validity by the host's timezone, up to hours. The validity
  is now read from the certificate's own DER, as the timestamp's and
  OCSP's times already were (ADR-0003 amendment 1).

## 0.2.2 — 2026-09-25

A security release. A review of the whole code base on 2026-09-25 found
five ways to reach a wrong verdict, and eight ways to crash the verifier,
exhaust its memory or time, or corrupt its output. Each is present in
0.1.0 to 0.2.1, and none needs a signing key. Every user should upgrade.

**A `0.2.2`, not a `0.3`:** no class, method, member or settings shape
changes, the recorded public API is the same 126 symbols, and there is no
new status code. What changes is the verdict on the files described below,
and the command's handling of input that is not a local, seekable file.

### Security

A wrong verdict:
- **A certificate that is not a certificate authority no longer
  issues (SPEC-014 amendment 4).** The chain walk accepted any issuer whose
  name and key matched. A signer under a configured anchor could issue a
  leaf on any name and be `Trusted` under it. Every issuer must now carry
  `CA:TRUE`, `keyCertSign` when keyUsage is present, and a `pathlen` that
  allows the chain; an intermediate must be valid when the leaf is judged.
  Present in 0.1.0 to 0.2.1.
- **Only a manifest that is validated may acknowledge a fault
  (SPEC-021 amendment 6).** The faults an ingredient assertion recorded
  were taken from every manifest in the store, including manifests that
  are never validated. Such a manifest could cancel a real fault of one
  that is, and a changed asset could stay `Valid` or `Trusted`. The set
  now comes only from the active manifest and the manifests its
  ingredients reach, and a failure of the manifest that binds an update
  manifest's asset is never dropped. Present in 0.1.0 to 0.2.1.
- **A standard manifest no longer gets an update manifest's
  exclusion adjustment (SPEC-022 amendment 6).** When the store held any
  update manifest, a standard active manifest without a hard binding of
  its own borrowed its parent's binding with the exclusion widened to the
  current store, and could be `Valid`. The adjustment of C2PA 2.4
  §15.12.1.1 now applies only when the active manifest is an update
  manifest. Such a file is `Invalid` with `assertion.dataHash.mismatch`.
  Present in 0.1.0 to 0.2.1.
- **A fragment's Merkle location must lie inside the tree
  (SPEC-028 amendment 1).** A fragmented BMFF stream with one fragment
  withheld and a copy of another fragment carrying an out-of-range
  `location` in its merkle box could be `Trusted`. A location must now
  be at least 0 and less than the declared `count`, else
  `assertion.bmffHash.mismatch` naming the fragment. Present in 0.1.0 to
  0.2.1.
- **The bytes after the last ISOBMFF box are hashed (SPEC-027
  amendment 4).** Fewer than eight bytes after the last top-level box were
  never hashed, so bytes appended or changed there after signing left an
  MP4, MOV, AVIF or HEIC file, or a fragment, `Trusted`. They are now
  hashed as `c2pa-rs` hashes them: last, with no offset marker. The same
  change makes a genuine file that `c2pa-rs` signed with such a tail
  `Trusted` here; it was `Invalid`.
  Present in 0.1.0 to 0.2.1.

A crash, a hang or a corrupted report:
- **A timestamp token or OCSP response with an empty element
  no longer ends the process (SPEC-016 amendment 4, SPEC-030 amendment
  3).** An empty SEQUENCE where a field was read by position gave a
  fatal PHP error: no report, exit status 255. Such a token is now
  `timeStamp.malformed`, and such a response `signingCredential.ocsp.skipped`.
  No key is needed to write such a file. Present in 0.1.0 to 0.2.1.
- **A very long INTEGER no longer takes minutes (SPEC-016
  amendment 5, SPEC-015 amendment 6).** Converting an INTEGER to decimal
  was quadratic, so a file with a long INTEGER in a timestamp token, an
  OCSP response or a certificate serial kept the verifier busy for tens
  of seconds or more. The conversion is now about 60 times faster, with
  the same results, and an INTEGER longer than 256 octets is refused:
  `timeStamp.malformed`, `signingCredential.ocsp.skipped`, or, for a
  certificate serial, `signingCredential.invalid`. The last is a stated
  difference: `c2patool` reads such a certificate. RFC 5280 allows 20
  octets, and no file measured carries more. Present in 0.1.0 to 0.2.1.
- **Hostile input ends in a report or a refusal (SPEC-043).**
  Six ways to crash the verifier, exhaust its memory or corrupt its output,
  none needing a key. All were present in 0.1.0 to 0.2.1:
  - a claim of nested CBOR arrays took hundreds of MiB; the items of a
    manifest store now have one budget of 65,536;
  - an ISOBMFF `merkle` box was read whole whatever its size, and a
    purpose string without a NUL was read to the box's end; both are
    bounded now;
  - a thumbnail media type that is not UTF-8 made the JSON report throw;
    it now reads as `""`, as in `c2patool`;
  - OpenSSL warnings reached standard output ahead of the JSON; they are
    caught, and a certificate whose parse warns is
    `signingCredential.invalid`;
  - a pipe or FIFO as input ended with a PHP error; the command now exits
    2 with a reason;
  - the command opened PHP stream wrappers (`data:`, `php://`, and
    `http://`, a network request); it now opens local files only, for the
    input and for `--settings`, and a file named like a wrapper is read as
    that file.

### Fixed
- **A JPEG whose first APP11 piece carries packet sequence number 0 is
  read (SPEC-041).** Microsoft Bing Image Creator writes every store this
  way, and both `c2patool` versions read it. Every later piece must still
  carry its own number, and any other first number is still refused. The
  Bing files stay `Invalid`, now with `claimSignature.missing` instead of
  `general.error`. Their signature URI and their box hash are gaps, not
  yet built (`docs/comparison.md`).

### Added
- `docs/trust-settings.md`: how to build trust settings from the C2PA's
  own trust and TSA lists, with DigiCert Trusted Root G4 recommended as a
  timestamp-authority anchor, and what that choice means. No list is
  bundled. Under it, each of 78 files from current writers on Wikimedia
  Commons gets the verdict of at least one `c2patool` version, and 63 get
  the verdict of both.
- ADR-0005: this verifier is stricter than `c2patool` only where the
  strictness prevents a wrong `Valid` or trust in something unchecked.
  `docs/comparison.md` was held against it: 33 differences by design
  remain, and five moved to the gaps. No behaviour changed.

### Changed
- The README no longer says `c2patool` falls back to the operating
  system's trust store for timestamp authorities. For a claim of version
  1, `c2pa-rs` does not check the authority's trust at all.

## 0.2.1 — 2026-09-24

Closer to `c2patool`, in eight specifications
(SPEC-033 to SPEC-040). Each was measured against `c2patool` 0.27.22 and
0.28.0 on signed probes before any code was written. Where the two
versions differ, this release follows 0.28.0 and names the difference in
`docs/comparison.md`.

**A `0.2.1`, not a `0.3`:** nothing that worked in 0.2.0 is refused by the
API. No settings shape, class, method or member changes, and the public
API only grows (112 → 126 symbols, all of them new status codes), as
0.2.0's did. What changes is:
- verdicts, on shapes that both `c2patool` versions already judged
  differently, listed under *Fixed*;
- the codes and success lines of some reports, listed under *Changed*.

A caller that matches exhaustively on `StatusCode` without a default arm
has 14 more cases to cover.

### Fixed
The verdict is now the one both `c2patool` versions give. No byte of an
image or video could be changed unnoticed through any of these. Each is
about what a manifest says of itself.
- **Actions content rules (SPEC-033), for v2 claims.** Only one opening
  action is allowed. `c2pa.opened`, `c2pa.placed` and `c2pa.removed` need
  ingredient references of the right relationship, and `c2pa.transcoded`
  and `c2pa.repackaged` need a `parentOf` when they name one.
  `c2pa.translated` needs both languages. `relatedAssertions` must be
  non-empty and resolvable, and must not name actions or ingredients. A
  watermark action needs a soft binding. Shapes that broke these rules
  were `Trusted` here (step 123).
- **Icon references (SPEC-034)**, in `claim_generator_info` and, in v2
  claims, in `softwareAgents`, `templates` and an action's
  `softwareAgent`. An icon whose hash differs, that does not resolve, or
  that points outside the manifest was `Trusted` here. Issue #11.
- **Redactions (SPEC-035).** A child that redacts an assertion of its
  parent, as `c2patool` 0.28.0's builder writes it, was `Invalid` here.
  It is now read and judged, and is `Trusted` where both `c2patool`
  versions say so. A v2 ingredient manifest with redacted assertions is
  bound by the hash of its signature box
  (`ingredient.claimSignature.validated`, informational, or `.mismatch`,
  `.missing`).
- **The `c2pa.redacted` action (SPEC-037).** In v2 claims, an action with
  `parameters` must name in `redacted` an assertion that the named
  manifest lists. A missing, relative or foreign reference is
  `assertion.action.redactionMismatch`, and an unlisted label is
  `assertion.notRedacted`. Four such shapes were `Trusted` here (step
  131). A bare `c2pa.redacted` still passes, as in `c2patool`.
- **The BMFF hash (SPEC-038).** Unsorted or overlapping `subset` ranges
  are `assertion.bmffHash.malformed`. A top-level box that a `subset`
  touches keeps its offset in the hash, at the box's start, as `c2pa-rs`
  hashes it. Before, shapes with a `subset` covering a whole box were
  `Trusted` here, and a correctly signed `subset` removing a box's head
  was `Invalid` here (steps 133 and 134). Issue #4.

### Changed
Same verdict, but the report differs:
- **The codes `c2patool` uses, where a refusal or an older code stood.**
  - A redacted actions assertion, a self-redaction, and a redacted box
    still holding content are `assertion.action.redacted`,
    `assertion.selfRedacted` and `assertion.notRedacted` (SPEC-035). Two
    existing test files move from `general.error` to these codes.
  - A redacted hard binding is `assertion.hardBinding.redacted`
    (SPEC-036). 0.27.22 says the deprecated `assertion.dataHash.redacted`.
  - An empty or absent BMFF `exclusions` list is
    `assertion.bmffHash.malformed`, where it was
    `assertion.bmffHash.mismatch` (SPEC-038).
  - A claim entry naming another manifest's assertion is
    `assertion.outsideManifest` on that entry (SPEC-040), where it was
    `assertion.missing` on the store.
- **Every verified signature now carries `claimSignature.insideValidity`**
  (a success), directly before `claimSignature.validated`, for the active
  manifest and for ingredients (SPEC-039). As in `c2patool`, this includes
  an expired signer. The expiry itself stays `signingCredential.expired`.
- **Nearly every BMFF file now carries
  `assertion.bmffHash.additionalExclusionsPresent`** (informational), as
  0.28.0 reports it. `c2pa-rs`'s writer excludes `/free` and `/skip`, which
  count as additional (SPEC-038). 0.27.22 does not emit it.

### Added
- 14 status codes, verbatim from C2PA 2.4 §15:
  - `assertion.action.ingredientMismatch`,
    `assertion.action.softBindingMissing`,
    `assertion.action.redacted` and
    `assertion.action.redactionMismatch`;
  - `assertion.selfRedacted`, `assertion.notRedacted` and
    `assertion.hardBinding.redacted`;
  - `assertion.bmffHash.malformed` and
    `assertion.bmffHash.additionalExclusionsPresent`;
  - `assertion.outsideManifest`;
  - `ingredient.claimSignature.validated`, `.mismatch` and `.missing`;
  - `claimSignature.insideValidity`.
- `docs/conformance.md`: gaps go from 15 to 11, and 63 obligations are now
  met in full (55 at 0.2.0).

## 0.2.0 — 2026-09-24

Safety fixes, and the first measurement against `c2patool` 0.28.0. Every
file whose result 0.28.0 changed was examined. Two were holes here and
are closed; the rest are aligned or named in `docs/comparison.md`.

**A `0.2`, not a `0.1.1`:** a settings file with a top-level
`trust.allowed_list` that worked in 0.1.0 is refused now. That is a
break, and `^0.1` does not pull it in. Everything else a 0.1.0 caller
wrote keeps working. The public API grew (eleven classes, 112 symbols)
and lost nothing.

### Added
- SPEC-032: two rules the oracles enforce.
  - A `c2pa.created` action without `digitalSourceType` in a v2 claim is
    `assertion.action.malformed`, as in `c2patool` 0.27 and 0.28. v1
    claims are untouched, as `c2pa-rs` leaves them.
  - The `c2pa.external-reference` checks of C2PA 2.4 §15.10.3.2.2: a
    `location` with a `url`, `alg` and `hash` together, and fourteen
    forbidden labels. They report the new code
    `assertion.external-reference.malformed`. Nothing is fetched.
  - The contract's surface grows to 112 symbols. No corpus verdict
    changed.
- SPEC-031: `trust.anchors`, the settings shape of `c2patool` 0.28
  (`c2pa` 0.91). Each entry has its own `trust_kind`, `allowed_list` and
  `trust_config`. Every entry counts only for its own kind (C2PA 2.4
  §14.4.1–§14.4.3). An entry's EKUs widen only the chains that reach it.
  The legacy `trust.trust_anchors` is still read. New contract class
  `Trust\TrustAnchorSet`; `TrustSettings::$anchorSets` and
  `MAX_ANCHOR_ENTRIES` (SPEC-025 amendment 4: eleven classes, 111
  symbols).

### Fixed
- A one-certificate `x5chain` written as a bare byte string (RFC 9360; what
  `c2pa-rs` writes for a signer directly under a root) was refused as
  `signingCredential.invalid`. It is now read as a chain of one, under
  the same rules as an array element (SPEC-008 amendment 2). No corpus
  file has that shape, so no recorded verdict changed.

### Changed
- **A hard binding referenced only from `gathered_assertions` is
  `claim.hardBindings.missing`**: C2PA 2.4 §10.2.2 requires
  `created_assertions` to reference it. 0.1.0 accepted such a manifest,
  as `c2patool` 0.27 did; `c2patool` 0.28 refuses it too. No real file
  in the corpus has that shape (SPEC-013 amendment 13).
- **The allowed list never makes a timestamp authority trusted** (C2PA 2.4
  §14.4.3). Through the PHP constructor it still could, and a trusted TSA
  moves the moment a signer is judged at, so an expired signer could stop
  being expired (step 114). SPEC-017 amendment 5. No settings file is
  affected.
- **A top-level `trust.allowed_list` is refused**, with a message saying
  where it belongs (`trust.anchors[].allowed_list`). `c2patool` 0.28
  moved it there and ignores a loose one without a word (step 107).
  SPEC-014 amendment 3.

### Security
- **Fixed: an exclusion wider than the manifest store was accepted.**
  Up to and including 0.1.0, a `c2pa.hash.data` exclusion that held the
  manifest store *and* other bytes passed as long as it covered the store.
  The three official `truepic-20230212-*` files exclude the whole EXIF
  segment that way, and a copy with its EXIF capture date changed stayed
  `Trusted`. C2PA 2.4 (VAL-ASSE-0043/0044) requires
  `assertion.dataHash.mismatch`, and that is what this version gives
  (SPEC-012 amendment 7, which reverses amendment 5; SPEC-017 amendment 4).
  Found 2026-09-24 through `c2patool` 0.28.0 (step 108), fixed the same
  day (step 109). Over 864 runs, it changed the verdict of those three
  files and no others.
- `docs/conformance.md`: `PRED-IMG-004` had been marked enforced since
  step 90 while it was not. The table now says when it became true.

## 0.1.0 — 2026-09-23

The first tag. A `0.x` on purpose: the public API is recorded and guarded
(ten classes, 99 symbols, a snapshot that fails the build on drift), but
nobody outside this project has used it yet, and a `1.0` would promise a
stability that has not been earned. `^0.1` receives every 0.1.x fix; a
change that breaks the API will be `0.2.0`.

Everything below shipped in this tag.

### Revocation without a network (2026-09-22)
- SPEC-030: the OCSP responses a signer staples into its own signature
  (`rVals.ocspVals`) are read, parsed per RFC 6960, matched to the
  signer's certificate and verified under a responder tied to its own
  issuer. A verified `revoked` makes the file `Invalid`
  (`signingCredential.ocsp.revoked`); everything unreadable,
  unverifiable, stale or about another certificate is
  `signingCredential.ocsp.skipped` and costs no verdict.
- The shape of that rule comes from one measurement: **`rVals` sits in
  the COSE unprotected bucket**, so anyone holding the file can add,
  alter or strip it. A stapled response may therefore lower trust and
  never raise it, and may never fail a file it cannot prove anything
  about — otherwise editing one unsigned byte would deny any valid asset.
- **Every file now reports whether revocation was checked at all**, and
  `checksPerformed` carries `revocation`. A skipped check that leaves no
  trace is the shape of silence this project refuses elsewhere. This is a
  second deliberate divergence from `c2patool`, which emits no OCSP code
  of its own on the two fixtures that carry a stapled response.
- Still out of scope, by rule rather than by milestone: any revocation
  that needs the network — online OCSP, an AIA fetch, a CRL.
- `StatusCode` grows by four cases; the recorded public surface goes from
  95 symbols to 99 (SPEC-025 amendment 3).

### Every obligation of the specification, listed (2026-09-22)
- `docs/conformance.md`: the 111 predicates of
  `encypherai/c2pa-conformance-suite` that apply to the formats this
  verifier reads, laid one by one next to what it actually does — 54
  enforced, 12 partial, 7 closed by refusing the feature, 21 out of scope
  by design, **17 gaps**, each with what it would cost. The catalogue is
  used as a checklist of named obligations, not as an oracle: that
  suite's own JPEG path disagrees with `c2patool`, this verifier and the
  Go implementation on files all three accept.
- The table found the gap SPEC-030 then closed, and says plainly that it
  is reasoned rather than measured except where an entry names a test.

### M8 — ISOBMFF (2026-09-22)
- SPEC-026: the ISOBMFF container. One top-level `uuid` box with the C2PA
  UUID and a 21-byte preamble, read with a bounded box walk; a second
  such box, a `purpose` this verifier does not read, or a header that
  does not fit is an error naming it. MP4, MOV, AVIF and HEIC, each held
  by a fixture here — a format is not named anywhere unless a file in
  this repository carries it.
- SPEC-027: `c2pa.hash.bmff.v3`. The digest was measured by instrumenting
  `c2pa-rs` rather than guessed: for each top-level box no exclusion
  matches, in file order, the eight-byte big-endian offset and then the
  box's bytes.
- SPEC-028: fragmented streams. A DASH init segment and its fragments as
  one verdict — the init against `initHash`, every fragment against the
  Merkle root, and the count as part of the promise: a withheld, repeated
  or foreign fragment is `Invalid` and **named**, which `c2patool` 0.27.22
  does not do (it answers in text, not JSON, and says only that something
  failed). `FragmentedVerifier` is the tenth class of the public contract,
  and takes the fragments one open stream at a time.
- SPEC-029: `c2pa.hash.bmff.v2`, after `c2pa-rs`'s own `video1.mp4` turned
  one up. The digest is identical to v3's; what differs is the exclusion
  list, and v2's needs nested box paths and `subset` filters. A filter this
  verifier cannot honour is refused only once its path resolves to a box
  the file actually has.

### Assurance: what a version number promises (2026-09-22)
- SPEC-025: the public API is ten classes and 99 recorded symbols, every
  other public class marked `@internal`. The surface is recorded in
  `tests/Fixtures/api/public-surface.txt` so a change to the promise shows
  up as a diff in review; `bin/api-check.php` is a step of `composer
  check` and fails the build on drift.
- SPEC-024: resource bounds. A manifest store that used to end a 128 MB
  process fatally now returns `Invalid` in 6 MB and 2 ms. Every parser has
  a limit and every limit has a message.
- Mutation testing with Pest's `--mutate`: **98.06 %**.

### The published package (2026-09-22)
- SPEC-023: what a `composer require` actually installs. `.gitattributes`
  keeps the 63 MB of fixtures, the tooling and the tool configuration out
  of the distributed archive; the documentation stays in, because the
  README and the log link to `notes/`, `specs/` and `docs/` and the spec
  requires every relative link in shipped markdown to resolve to something
  also shipped. `bin/package-check.php` measures the archive rather than
  trusting the list: 245 files, 2.5 MB, every top-level path classified.

### Coverage and a fix (2026-09-22)
- `tests/Fixtures/matrix/`: the three unsigned fixtures signed with all
  seven signature algorithms in all three containers, plus two files
  whose data hash is sha384 and sha512 — the cells the four corpora left
  empty (Es512, Ps384, Ps512 and Ed25519 were in no file at all, and
  WebP in one). The fifth drift alarm compares every one with
  `c2patool`'s JSON, with and without the test roots.
- Fixed: **on PHP 8.3 every Ed25519-signed file was `Invalid`**
  (`signingCredential.invalid`, "key of type other") and `Trusted` on
  8.4 and 8.5 — the key's kind is now read from the
  SubjectPublicKeyInfo's algorithm OID, as the RSASSA-PSS case already
  was. The C2PA rule never changed; no fixture could show it until the
  matrix existed (SPEC-015 amendment 5).

### M7 — ingredient and update manifests (2026-09-22)
- SPEC-022: update manifests (`c2um`) are read and judged — C2PA 2.4
  §11.2.3's rules (`manifest.update.invalid`,
  `manifest.update.wrongParents`), §15.11's one-parent rule
  (`manifest.multipleParents`, which closes a leniency: two parents were
  `Trusted` here), the hard binding found up the `parentOf` chain
  (§15.12) and its stale exclusion adjusted to the store's current range
  (§15.12.1.1) with the cover rule still over it. Time-stamp manifests
  (`c2tm`) are refused in their place; compressed manifests (`c2cm`)
  stay refused. **Every multi-manifest file in the four corpora is now
  measured rather than refused.**
- Stricter than `c2patool` by the specification: a hash assertion in an
  update manifest is `manifest.update.invalid` here and `Trusted` there
  (the rule sits in unreachable code in c2pa-rs) — `docs/comparison.md`.
- Fixed: the opening rule of SPEC-018 no longer applies to an update
  manifest (it never should have — the spec said so, the code could not);
  an empty `claim_generator_info` is read rather than refused; the set of
  statuses an ingredient assertion's record silences is the store's, not
  one assertion's (SPEC-021 amendment 4).
- SPEC-021: the manifests an ingredient assertion names are validated —
  the box hash it recorded (`ingredient.manifest.validated` /
  `.mismatch`; the pre-1.3 hash over the claim accepted silently) and
  then the manifest itself: timestamp, signature, certificate profile,
  chain and trust, hashed URIs, actions. Never the data hash: an
  ingredient's hard binding covers its own asset. A fault the ingredient
  assertion *recorded* is dropped, as the specification says and
  `c2patool` does — except when it names the active manifest, which no
  ingredient assertion may speak for. **A store with more than one
  manifest is no longer refused** (SPEC-013 amendment 5 lifted):
  seventeen corpus files are measured now, sixteen with c2patool's
  verdict exactly. Still refused by name: update manifests (`c2um`),
  CAWG identity assertions, claims with redactions.
- SPEC-020: the ingredient assertion (`c2pa.ingredient`, `.v2`, `.v3`)
  and the graph it draws over the manifest store — the walk from the
  active manifest with bounds and cycle detection,
  `ingredient.unknownProvenance`, `ingredient.manifest.missing`,
  `assertion.ingredient.malformed`; statuses scoped to their ingredient
  assertion and rendered under `validation_results.ingredientDeltas`,
  and `ingredients` per manifest, both as `c2patool` prints them.
  No verdict changed: a store with more than one manifest is still
  refused until SPEC-021 validates the manifests the graph found.

### The command line (2026-09-22)
- SPEC-019: `bin/c2pa-verify <file> [--settings <path>]` — the report as
  `toJson()` on standard output, `Error: …` on standard error, exit status
  0 (`Trusted`/`Valid`), 1 (`Invalid`, report printed), 2 (no report).
  Registered as a Composer `bin`. `c2patool`'s exit status was measured
  first and departed from where it is fail-open (exit 0 on `Invalid`; a
  missing settings file ignored).
- Fixed: `toJson()` threw `JsonException` on a manifest whose
  `claim_generator_info` carries a byte string (OpenAI's generator icon);
  bytes now render as base64 like every other value (SPEC-007
  amendment 5, found by SPEC-019's corpus criterion).

### M6 — RFC 3161 timestamps (2026-09-22)
- SPEC-016: an own DER reader (`src/Asn1/`) and the timestamp token as data
  (`src/Timestamp/`) — `TimeStampResp`/`TimeStampToken`, `SignedData`,
  `SignerInfo`, `TSTInfo`, bounded, every fault with its offset.
- SPEC-017: the timestamp check — the CMS signature (RSA PKCS#1, ECDSA,
  RSASSA-PSS; DER and raw R‖S; the DER-sorted `SET` of signed attributes),
  the imprint over the `CounterSignature` bytes, the TSA's profile and
  chain through the trust settings, the six `timeStamp.*` codes (all
  informational), `signature_info.time`, and the time the signer's
  validity is judged at — only a validated *and* trusted timestamp
  supplies it. ADR-0004.
- SPEC-018: the actions assertion — a 2.x manifest opens with
  `c2pa.created` or `c2pa.opened`, or it is `assertion.action.malformed`.
- SPEC-013 amendment 9: a remote manifest declared by URL is reported
  (`remote_manifest`), never fetched.
- Fixed: a correctly signed manifest with no hard binding was `Valid`
  (SPEC-013 amendment 10, `claim.hardBindings.missing`); a 2.x manifest
  without an actions assertion was `Valid` (SPEC-018). See `SECURITY.md`.
- Fixed: negative RFC 3161 nonces refused as malformed; fractional `genTime`
  dropped from `signature_info.time`; the CMS signature verified over the
  attributes as written rather than the DER-sorted `SET` (SPEC-016
  amendment 3, SPEC-017 amendments 2–3 — found through the writers corpus).
- Fixtures: `tests/Fixtures/writers/` (OpenAI, Amazon Bedrock, `c2pa-ts`,
  Adobe Photoshop, a CAWG file, a Pixel 10 photo, a Lightroom Classic
  export) as a fourth drift alarm; TSA anchors cut from the tokens;
  `tests/Fixtures/absence/` (signed manifests with one thing absent);
  `bin/fuzz.php` (70 870 mutated files, no exception escaped).

### M5 — certificate chain and trust (2026-09-21)
- SPEC-014: trust settings in `c2patool`'s JSON shape, the allowed list,
  the chain walk to an anchor on `openssl_x509_verify`; `Trusted`.
- SPEC-015: the C2PA 2.4 §14.5 certificate profile; `signature_info`.
  ADR-0003.
- Fixtures: `c2pa-org/public-testfiles` (24 JPEGs) and `c2pa-rs`'s own (17
  files) as drift alarms; CBOR floats and indefinite lengths accepted and
  bounded (SPEC-006 amendments 2–3); a data-hash exclusion must *cover*
  the store (SPEC-012 amendment 5); multi-manifest stores and CAWG
  assertions refused until validated (SPEC-013 amendments 5, 7).

### M4 — hash binding (2026-09-21)
- SPEC-011: the hashed URI of every assertion the claim names;
  undeclared assertions refused.
- SPEC-012: `c2pa.hash.data` over the asset, streamed in 64 KiB chunks,
  exclusions sorted and checked, one hard binding per manifest.

### M3 — COSE_Sign1 (2026-09-21)
- SPEC-008: the COSE_Sign1 structure, protected `x5chain`, Sig_structure.
- SPEC-009: ES256/384/512, PS256/384/512 (an own EMSA-PSS verifier for
  ordinary RSA keys), Ed25519 (opt-in `sodium`), R‖S → DER.
- SPEC-010: the report — `validation_state`, the §15 status codes
  verbatim, `validation_results` and `validation_status` as `c2patool`
  prints them.

### M2 — JUMBF and CBOR (2026-09-21)
- SPEC-005: the JUMBF box tree with bounds. SPEC-006: a CBOR decoder for
  the subset C2PA uses. SPEC-007: claim v1 and v2, assertions,
  `claim_generator_info`; equal to `c2patool`'s JSON through the sister
  library's parser.

### M1 — containers (2026-09-20)
- SPEC-001: JPEG APP11 (multi-segment). SPEC-002: PNG `caBX`. SPEC-003:
  WebP RIFF `C2PA`. SPEC-004: a bounded stream reader. Byte-exact
  extraction, measured by hash against `c2patool`.

### M0 — skeleton (2026-09-19)
- SPEC-000: the spec template and `bin/spec-check.php`; CI on PHP
  8.3/8.4/8.5; Pint, PHPStan level max, Deptrac, Pest; ADR-0001
  (dependencies: none), ADR-0002 (name, namespace, MIT).
