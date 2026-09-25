# What this verifier does, does not do, and where it differs from `c2patool`

Measured against `c2patool` 0.27.22 (`c2pa/0.90.22`), last reviewed
2026-09-24, over the five fixture corpora — 22 own variants, 24 files of
`c2pa-org/public-testfiles`, 17 of `c2pa-rs`'s own fixtures, 7 from other
writers and 23 of the algorithm matrix, 93 in all — plus the signed
absence variants. The drift alarms run all of it on every `composer
check`: four of them from the lists in `tests/Pest.php`, the fifth in
`tests/Unit/Verifier/MatrixTest.php`. The exceptions below are those
lists, by name. "Stricter" means this verifier refuses
where `c2patool` accepts; the project allows that only for a named reason
and never the other way round. ADR-0005 says what counts as a reason: the
strictness must prevent a wrong `Valid` or trust in something unchecked.

**And against `c2patool` 0.28.0** (`c2pa` 0.91.0, released 2026-09-22),
measured on 2026-09-24 over the whole corpus (steps 107–118). The pinned
oracle, whose JSON the drift alarms compare with, is still 0.27.22.
0.28.0's answers are recorded where a criterion rests on them, under
`tests/Fixtures/c2patool/anchors/`, `x5chain/` and `absence/`. Of the 14
files whose result 0.28.0 changed, every one has been examined:
- two were holes here and are closed (step 108, an exclusion wider than
  the store; step 118, a hard binding only gathered);
- one was a refusal here that 0.28.0 now shares (`webp/length-differs`);
- one is 0.28.0 dropping the claim-signing EKU, which this verifier keeps
  (step 112);
- the rest keep their state with other codes, or are places where this
  verifier was already stricter by name.

The settings shape 0.28.0 reads (`trust.anchors`) is read here too
(SPEC-031). Each remaining difference is a row below.

**Since 0.2.0** (SPEC-033 to SPEC-040), every new rule was measured on
signed probes under **both** versions before it was built. Their answers
are recorded beside the probes, under `tests/Fixtures/c2patool/`:
`actions-rules/`, `icons/`, `redactions/`, `hard-binding-redacted/`,
`redacted-action/`, `bmff-shape/`, `outside-manifest/` and
`inside-validity/`.

Where the two versions differ, this verifier follows 0.28.0:
- it checks `relatedAssertions` and a watermark's soft binding (SPEC-033),
  which 0.27.22 did not;
- it reports a redacted hard binding as `assertion.hardBinding.redacted`
  (SPEC-036), where 0.27.22 says the deprecated
  `assertion.dataHash.redacted`;
- it reports `assertion.bmffHash.additionalExclusionsPresent` (SPEC-038),
  which 0.27.22 never emits;
- it reports `assertion.notRedacted` beside the other two codes on a
  self-redacted actions assertion (SPEC-035), where 0.27.22 reports only
  those two.

The drift alarms still compare with 0.27.22's recorded JSON, so none of
these rows moves an alarm.

## Where `c2patool` can do more

| what | `c2patool` | this verifier | until |
|---|---|---|---|
| Time-stamp manifests (`c2tm`), compressed manifests (`c2cm`) | `c2tm` ignored, `c2cm` decompressed | refused with a message of their own — deprecated (§11.2.5) and Brotli, which PHP does not carry | — |
| GIF, TIFF, SVG, audio, PDF | yes | JPEG, PNG, WebP and ISOBMFF only (`unsupported file type`) | later |
| A JPEG whose first APP11 piece carries a packet sequence number Z other than 1, or whose later pieces skip a number | read: `c2pa-rs` does not check the first piece's Z, and a later Z only has to exceed the pieces already taken | **Z = 0 on the first piece is read** (SPEC-041: Microsoft Bing Image Creator writes every store this way); any other first Z and any gap stay refused (`general.error`), measured on synthetic variants only | — |
| A claim whose URIs are written `self#jumbf=c2pa/<manifest>/…`, without the leading slash (the spec's own examples from 1.0 to 2.1; Microsoft Bing Image Creator, nine files from Commons, step 141) | read as absolute (`c2pa-rs` `to_normalized_uri`) | read as relative to the manifest, as C2PA 2.4 §8.4.2.1 says, so the signature is `claimSignature.missing` (§15.7). Deferred, not by design (ADR-0005): the strictness protects nothing, but reading the URI moves no verdict while these files' `c2pa.hash.boxes` is refused by name (`PRED-CONT-006`) | SPEC-042, as the first step of box hashes |
| An external reference whose `location` carries `alg` without `hash`, or `hash` without `alg` | read as unhashed and accepted (both versions) | `assertion.external-reference.malformed` (SPEC-032 AC5, C2PA 2.4 §15.10.3.2.2). The strictness protects nothing: an unhashed external reference is allowed anyway (ADR-0005, step 145) | a file that carries it; none has so far |
| A timestamp token whose `signingTime` attribute differs from `genTime` | `signingTime` used | `malformed` (ADR-0004 decision 5). The strictness protects nothing: both times are inside the TSA's signature (ADR-0005, step 145) | a file that carries it; none has so far |
| A `c2pa.redacted` reference to a data box that the claim's own `redactions` lists | passes | `assertion.notRedacted` (SPEC-037 open question 3). The reason was a missing fixture, not a protection (ADR-0005, step 145) | a file that carries it; none has so far |
| A COSE header with both `sigTst` and `sigTst2` | `sigTst2` used | `malformed` (SPEC-016 AC8). The strictness protects nothing: the token used must still match the signature and reach a trusted TSA (ADR-0005, step 145) | a file that carries it; none has so far |
| An ISOBMFF `uuid` box that announces C2PA but whose purpose cannot be read | "no claim found" | an error (SPEC-026 AC5). Neither answer is `Valid`; only what the report says differs (ADR-0005, step 145) | a file that carries it; none has so far |
| ISOBMFF | validated, hard binding included | **MP4, MOV, AVIF and HEIC** read and verified, hard binding included, each held by a fixture (SPEC-026, SPEC-027). **Fragmented streams verified too** (SPEC-028): the init segment against `initHash` and every fragment against the Merkle root. **`c2pa.hash.bmff.v2` is verified too** (SPEC-029), nested exclusion paths and `subset` filters included — `c2pa-rs`'s own `video1.mp4` carries one, and under the same trust anchors this verifier and `c2patool` agree status for status. The `length`/`version`/`flags`/`exact` filters and an assertion with more than one `merkle` map are refused by name | a stream with several renditions |
| CAWG identity assertions | validated (their own X.509 credential) | refused (`general.error` on the assertion) — `C_with_CAWG_data`, `cawg_ica` | a CAWG spec |
| Remote manifests (`dcterms:provenance` URL) | fetched over the network | reported as `remote_manifest`, never fetched — `cloud.jpg`, the Photoshop file | never (by design) |
| OCSP staples, certificate revocation | checked (with network) | **the responses stapled into the signature are checked** (SPEC-030): a verified `revoked` makes the file `Invalid`, and every file reports whether revocation was checked at all. c2patool 0.27.22 emits no OCSP code of its own on the two fixtures that carry a stapled response, so this verifier says more here, not less | an online OCSP query, an AIA fetch or a CRL — never in the verification path |
| Assertion content beyond the actions rules of SPEC-018, SPEC-032, SPEC-033 and SPEC-034 — `assertion.required.missing` | validated | not read (`SPEC013_NOT_YET`) — no corpus file shows a difference | M7 / a spec |
| `c2pa.hash.data.part`, `c2pa.hash.multi-asset` (a second asset's hashes, e.g. Ultra HDR) | not validated either | not read | — |
| Unknown critical X.509 extensions on the signer | refused | not seen (`openssl_x509_parse` does not flag them) — the one place this verifier is *more lenient* by omission, no corpus file shows it | an amendment with the DER reader |
| JSON report | assertions rendered, thumbnails, ingredient tree | `c2patool`'s five keys, `format`, `has_manifest`, `remote_manifest`, `checks_performed`; assertions decoded but not rendered | — |
| Command line | `c2patool <file>` with `--detailed`, `--info`, signing, trust sub-commands, fragments | `bin/c2pa-verify <file> [--settings <path>]`: the JSON report, nothing else (SPEC-019) | — |

## Where the verdicts are equal (measured, code for code)

Container extraction (byte-exact by hash), JUMBF, CBOR (floats, indefinite
lengths), claim v1/v2, COSE ES256/384/512, PS256/384/512, Ed25519, hashed
URIs, the data hash with the *cover* rule, certificate profile, chain and
trust under nine settings variants, the timestamp (35 corpus tokens
`validated` with `signature_info.time` byte-equal), the actions opening
rule, the ingredient graph and the ingredient manifests (SPEC-020/021:
seventeen multi-manifest files, sixteen verdicts exactly c2patool's, the
two others by the TSA leniency below) — on every corpus file that is not in an exception list, and on every
own variant, the state and the failure codes with their URLs are
`c2patool`'s. Nothing is more lenient.

## Where a second implementation disagrees

Measured on 2026-09-22 (step 61) by running `richardwooding/c2pa`
v0.22.0 — an independently written pure-Go verifier — over the same 257
files with the same trust anchors.

| what | here and at `c2patool` | the Go verifier |
|---|---|---|
| A signer whose KeyUsage is `nonRepudiation` alone | `Trusted` (`c2pa-rs`'s rule, mirrored by SPEC-015 on the maintainer's decision) | `signingCredential.invalid` |
| A signer whose EKU is the C2PA signing OID `1.3.6.1.4.1.62558.2.1` | `Trusted` (the OID is on `c2pa-rs`'s accepted list) | `signingCredential.invalid` |
| `c2pa-rs/update_manifest.jpg`: the stale exclusion of a binding written before an update manifest was appended (C2PA 2.4 §15.12.1.1) | `assertion.dataHash.match` — and the digest the assertion records matches the *adjusted* exclusion, measured both ways | `assertion.dataHash.mismatch` |
| Thirteen container-, JUMBF- and claim-level malformations this verifier refuses (`png/crc-wrong`, `jumbf/root-label`, …) | refused here, accepted by `c2patool` | accepted |

## Where this verifier differs by design

| difference | why | where named |
|---|---|---|
| An icon that names a data box (earlier versions' mechanism) is `assertion.missing`; `c2pa-rs` accepts it without a hash check, and C2PA 2.4 §10.2.3.2 says consumers *should* support data boxes | maintainer's decision (SPEC-034 option A): no file shows one, and an unchecked reference is what the rule exists to refuse | SPEC-034 amendment 1 |
| An icon map without a `url` (a resource reference, which `c2patool` 0.28.0's builder writes for `softwareAgents`) is not checked, as in `c2pa-rs` | only hashed URIs are references | SPEC-034 amendment 2 |
| `relatedAssertions` and the watermark's soft binding are checked; `c2patool` 0.27.22 did not check them (0.28.0 does) | C2PA 2.4 §15.10.3.2.3 | SPEC-033 AC6–AC7 |
| An action's ingredient reference is resolved by its label, as `c2pa-rs` does, not by its hash; a `c2pa.removed` reference is looked up in the current claim, as `c2pa-rs` does, where §15.10.3.2.3 says *another manifest* | maintainer's decision (open question 2); no fixture shows either reading | SPEC-033 open questions 2–3 |
| The external-reference checks themselves (a `location` with a `url`, the forbidden labels) are `c2patool` 0.28.0's; 0.27.22 accepted all of them | §15.10.3.2.2 | SPEC-032 AC4–AC5 |
| `c2pa.created` without `digitalSourceType` is refused in v2 claims only, as `c2pa-rs` does; C2PA 2.4 states it for the claim generator (§18.15.2), not among §15's validation steps | the verdict follows `c2patool` (maintainer's decision) | SPEC-032 AC1–AC2 |
| A hard binding referenced only from `gathered_assertions` is `claim.hardBindings.missing`; `c2patool` 0.27.22 accepted it (0.28.0 refuses it too, without a report) | C2PA 2.4 §10.2.2: `created_assertions` shall reference the hard binding | SPEC-013 amendment 13, AC19 |
| The allowed list never trusts a timestamp authority; `c2pa-rs` 0.91.0 checks its end-entity set for TSAs too (read, not measurable through `c2patool`, which trusts these TSAs without anchors) | C2PA 2.4 §14.4.3: the private credential store *"shall not apply to validating time-stamps"*; step 114 showed a TSA on the list excusing an expired signer | SPEC-017 amendment 5, AC13 |
| Neither this verifier nor `c2patool` ties trust anchors to EKUs (C2PA 2.4 §14.5.1.2): a certificate without the claim-signing EKU under a C2PA Trust List anchor is `Trusted` in both | the shared settings format cannot express the association; named rather than invented | `docs/conformance.md`, *Outside the catalogue*; `notes/step-113-anchors-and-ekus.md` |
| A leaf whose only EKU is the C2PA claim-signing OID (`1.3.6.1.4.1.62558.2.1`), or documentSigning, is accepted by default; `c2patool` 0.28.0 calls it `signingCredential.invalid` unless `trust_config` lists it (0.27.22 accepted it) | C2PA 2.4 §14.4.1 makes claim-signing *the* C2PA signer EKU; 0.28.0 no longer applies its own `valid_eku_oids.cfg` by default (step 112). It shows on real files too: OpenAI signers issued by Trufo carry only these two EKUs, and 0.28.0 calls six GPT Image 2 and 2.5 files from September 2026 `Invalid` that both this verifier and 0.27.22 call `Valid` (step 141) | SPEC-015; `notes/step-112-claim-signing-eku.md`; ADR-0005, addendum |
| A `trust.anchors` entry counts only for its own `trust_kind`: a `"tsa"` or `"cawg"` entry anchors no signer, a `"manifest"` entry anchors no timestamp authority; `c2patool` 0.28.0 lets every entry anchor everything | C2PA 2.4 §14.4.1–§14.4.2 keep the lists separate; the `"cawg"` list is the Mozilla S/MIME root store, and copying `c2patool` would let an ordinary S/MIME certificate sign C2PA content as `Trusted` | SPEC-031 AC6 |
| A top-level `trust.allowed_list` is refused (exit 2, a message naming `trust.anchors[].allowed_list`); `c2patool` 0.28.0 drops it without a word, 0.27.22 honoured it | the same settings file would otherwise mean `Trusted` here and `Valid` there, silently; it prevents no wrong `Valid`, but a shared settings file whose meaning differs without a word is a trust fault for whoever shares it (step 145) | SPEC-031 AC4 |
| An unknown key inside a `trust.anchors` entry, or an `allowed_list` on a `"tsa"` entry, is refused; `c2patool` 0.28.0 ignores both | settings are whole or absent (SPEC-014 AC7): a mistyped key would otherwise drop a restriction silently and widen trust; §14.4.3 keeps the allowed list away from time-stamps | SPEC-031 AC5, AC6 |
| The legacy `trust.trust_anchors` string anchors signers *and* timestamp authorities, as in `c2patool` — which §14.4.2 (*"shall be separate"*) does not want | kept for compatibility: every existing settings file keeps its meaning; `trust.anchors` is the conformant way | SPEC-031 open question 5 |
| A `c2pa.hash.data` exclusion that holds the store **and** other bytes is `assertion.dataHash.mismatch`; `c2patool` 0.27.22 accepts it and calls the three `truepic-20230212-*` files `Trusted` under their root | C2PA 2.4 VAL-ASSE-0043/0044, and a changed EXIF date stayed `Trusted` under 0.27.22's rule (step 108); `c2patool` 0.28.0 agrees with this verifier | SPEC-012 amendment 7 |
| A timestamp authority is trusted **only** through the configured anchors; `c2patool` reports `timeStamp.trusted` for DigiCert and Truepic TSAs with no anchor configured and `untrusted` for a 2025 DigiCert responder. Step 40 §5 found this not derivable from the 0.90.22 source; `c2pa-rs` at `ada3e4a` says why: for a **claim v1** it switches `verify_timestamp_trust` off (`claim.rs`), and `c2patool` 0.28.0 reports such a stamp as *"legacy timestamp cert trusted"* (step 146) | C2PA 2.4 §14.6.1: a *trusted* timestamp; trust by observation is not trust. It prevents a wrong `Valid`: an unanchored TSA can place an expired or stolen signer's signature inside its validity (ADR-0005) | ADR-0004 decision 3; `_TSA_NOT_CONFIGURED` (Truepic ×3, `ocsp*`, `exp-test1`, Amazon, Pixel — `expired` at now here, `Valid` there; with the anchor configured they are equal, measured in SPEC-017 AC6/AC11/AC12) |
| `timeStamp.*` is informational, as at `c2patool`; the timestamp's one effect is the time the signer's validity is judged at | c2pa-rs logs every timestamp fault informational | SPEC-017 |
| A failing fragmented stream says **which file** failed; `c2patool` gives the same code for a changed init segment, a changed fragment and a foreign one | a stream is many files, and a verdict that names none leaves the caller to bisect by hand | SPEC-028 AC2–AC4 |
| An ingredient manifest with a **v1 claim** whose assertions the store redacts is judged by its box hash, which then fails (`ingredient.manifest.mismatch`); `c2pa-rs` skips both the box hash and the claim-signature method and says nothing | a manifest that nothing binds is not passed on trust; no file has one | SPEC-035 amendment 3 |
| A `c2pa.redacted` action **without `parameters`** passes, as in both `c2patool` versions; C2PA 2.4 §15.10.3.2.3 would reject it with `assertion.action.redactionMismatch` | the maintainer's choice (SPEC-037 open question 1): verdicts equal to `c2patool`'s; no asset byte is involved | SPEC-037 |
| A `c2pa.redacted` reference to a manifest's label it does not list is `assertion.notRedacted`, as `c2pa-rs` names it; 2.4 says `redactionMismatch` | the codes compare with `c2patool` | SPEC-037 open question 4 |
| A BMFF hash assertion without an `exclusions` key is `assertion.bmffHash.malformed`; `c2patool` gives no report at all (*"missing field `exclusions`"*) | a report that says why | SPEC-038 open question 2 |
| A `subset` entry of length 0 that is not the last is `malformed` (it runs to the end of the box, so it overlaps what follows); `c2pa-rs` does not check it | C2PA 2.4: only the last entry may run to the end; otherwise the bytes after it could escape the hash (reasoned, not measured against `c2pa-rs`'s hashing) | SPEC-038 open question 3 |
| `claimSignature.insideValidity` is reported beside every verified signature, **an expired signer's included**, as both `c2patool` versions report it; C2PA 2.4 §15.8 ties it to the signer's validity period | the maintainer's choice (SPEC-039 open question 1): the reports compare line for line, and its explanation says *"claim signature valid"*; the validity itself is `signingCredential.expired` | SPEC-039 |
| A relative entry in `redacted_assertions` excuses no missing assertion; `c2pa-rs` does not resolve it either, and reports it verbatim | measured on `binding/claim-redacted.png` | SPEC-035 amendment 2 |
| A CAWG identity assertion is `Invalid` until validated | `Trusted` on a credential never examined (`C_with_CAWG_data`) | SPEC-013 amendment 7 |
| The data hash is not read after a hashed-URI *mismatch* on `c2pa.hash.data` (four own variants report a strict subset of `c2patool`'s failures) | the assertion is not what the signer saw | SPEC-011 decision 1, `SPEC013_SUBSET_ONLY` |
| A parse fault stops this verifier where `c2patool` goes on (`json-broken`) | a report, not a guess | `SPEC013_SUBSET_ONLY` |
| Some faults `c2patool` reports with a hard exit (no JSON) are a report here: `claim missing hard binding`, `No Action array in Actions`, undecodable assertions | the caller gets a verdict and a reason either way | SPEC-012, SPEC-018 |
| A hash assertion in an update manifest is `manifest.update.invalid`; `c2patool` reports nothing and validates the assertion as the asset's binding (`Trusted`) — its rule for this sits in unreachable code (`c2pa-rs claim.rs verify_internal`) | C2PA 2.4 §11.2.3: "An Update Manifest shall not contain assertions of types `c2pa.hash.data` …"; otherwise an update manifest could rebind the asset to other bytes | SPEC-022 amendment 2 |
| A manifest in the store that **no ingredient assertion names** is never validated — its signature may be broken and the file is still `Trusted` — while both this verifier and `c2patool` still render it under `manifests` | C2PA 2.4 §15.11.3.3: "Validators should ignore any additional C2PA Manifests that appear in the C2PA Manifest Store but are not in the list of ingredient manifests"; §15's vocabulary has no code for one, and this project invents none | step 60, `tests/Fixtures/m7-absence/unreferenced-broken.png` |
| A v3 ingredient assertion **without `claimSignature`** is read, though §18.16.12.3 says both hashed URIs shall be stored (`c2patool` reads it too) | the second URI is needed only for the claim-signature method, which redactions force; when they do and it is absent, the ingredient is `ingredient.claimSignature.missing` (SPEC-035) | step 60, `no-claim-signature.png` |
| `assertion.action.malformed` on the manifest carries the bare manifest label as its url — `c2patool`'s inconsistency, copied so that code and url compare | drift-alarm equality | SPEC-018 amendment 2 |
| The command's exit status carries the verdict (0 `Trusted`/`Valid`, 1 `Invalid`, 2 no report); `c2patool` exits 0 on an `Invalid` report and 1 only when it prints no JSON. A `--settings` file that cannot be read is a refusal (exit 2); `c2patool` ignores it and reports without trust | fail closed: `c2pa-verify "$f" && publish "$f"` must not publish a tampered file, and a mistyped settings path must not turn `Trusted` into an unexamined `Valid` | SPEC-019 (exit status measured 2026-09-22) |

## Same verdict, different informational code

`timeStamp.untrusted` here where `c2patool` says `timeStamp.trusted`
without an anchor — 34 corpus files, informational, no verdict changes.

## What "works" rests on

- One pinned oracle (`c2patool` 0.27.22), and its successor 0.28.0
  compared file by file (steps 107–118). A second independent
  implementation (Go, `richardwooding/c2pa`) was run over 257 files in
  step 61.
- Test anchors and anchors cut from tokens. The production C2PA trust
  lists were used once, as a measurement (step 113): two corpus files
  reach an official anchor. The project does not bundle or fetch them.
- 70 870 randomly mutated files without an escaping exception
  (`bin/fuzz.php`); the 312 mutations that stayed `Valid` were confirmed
  `Valid` by `c2patool` and land in bytes the format leaves uncovered.
- Two wrong `Valid`s found by the absence audit and closed (see
  `SECURITY.md`); the method is now part of every spec.
- Every signature algorithm and every hash algorithm exercised by a file
  a writer produced, not only by a vector (`tests/Fixtures/matrix/`,
  step 59) — which is how a wrong `Invalid` on PHP 8.3 for every
  Ed25519-signed file was found and fixed (SPEC-015 amendment 5).
