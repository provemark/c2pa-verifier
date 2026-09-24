# What this verifier does, does not do, and where it differs from `c2patool`

Measured against `c2patool` 0.27.22 (`c2pa/0.90.22`), last reviewed
2026-09-23, over the five fixture corpora — 22 own variants, 24 files of
`c2pa-org/public-testfiles`, 17 of `c2pa-rs`'s own fixtures, 7 from other
writers and 23 of the algorithm matrix, 93 in all — plus the signed
absence variants. The drift alarms run all of it on every `composer
check`: four of them from the lists in `tests/Pest.php`, the fifth in
`tests/Unit/Verifier/MatrixTest.php`. The exceptions below are those
lists, by name. "Stricter" means this verifier refuses
where `c2patool` accepts; the project allows that only for a named reason
and never the other way round.

## Where `c2patool` can do more

| what | `c2patool` | this verifier | until |
|---|---|---|---|
| Time-stamp manifests (`c2tm`), compressed manifests (`c2cm`) | `c2tm` ignored, `c2cm` decompressed | refused with a message of their own — deprecated (§11.2.5) and Brotli, which PHP does not carry | — |
| Redacted assertions | validated (`assertion.notRedacted`, the claim-signature hash method) | a claim with a non-empty `redacted_assertions` is refused (`general.error`) — no corpus file has a real redaction to measure against | a fixture, then a spec |
| GIF, TIFF, SVG, audio, PDF | yes | JPEG, PNG, WebP and ISOBMFF only (`unsupported file type`) | later |
| ISOBMFF | validated, hard binding included | **MP4, MOV, AVIF and HEIC** read and verified, hard binding included, each held by a fixture (SPEC-026, SPEC-027). **Fragmented streams verified too** (SPEC-028): the init segment against `initHash` and every fragment against the Merkle root. **`c2pa.hash.bmff.v2` is verified too** (SPEC-029), nested exclusion paths and `subset` filters included — `c2pa-rs`'s own `video1.mp4` carries one, and under the same trust anchors this verifier and `c2patool` agree status for status. The `length`/`version`/`flags`/`exact` filters and an assertion with more than one `merkle` map are refused by name | a stream with several renditions |
| CAWG identity assertions | validated (their own X.509 credential) | refused (`general.error` on the assertion) — `C_with_CAWG_data`, `cawg_ica` | a CAWG spec |
| Remote manifests (`dcterms:provenance` URL) | fetched over the network | reported as `remote_manifest`, never fetched — `cloud.jpg`, the Photoshop file | never (by design) |
| OCSP staples, certificate revocation | checked (with network) | **the responses stapled into the signature are checked** (SPEC-030): a verified `revoked` makes the file `Invalid`, and every file reports whether revocation was checked at all. c2patool 0.27.22 emits no OCSP code of its own on the two fixtures that carry a stapled response, so this verifier says more here, not less | an online OCSP query, an AIA fetch or a CRL — never in the verification path |
| Assertion content beyond the actions opening rule (c2pa-rs `verify_actions` 2.b–2.f, `assertion.required.missing`, `assertion.action.redacted`) | validated | not read (`SPEC013_NOT_YET`) — no corpus file shows a difference | M7 / a spec |
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
| A `c2pa.hash.data` exclusion that holds the store **and** other bytes is `assertion.dataHash.mismatch`; `c2patool` 0.27.22 accepts it and calls the three `truepic-20230212-*` files `Trusted` under their root | C2PA 2.4 VAL-ASSE-0043/0044, and a changed EXIF date stayed `Trusted` under 0.27.22's rule (step 108); `c2patool` 0.28.0 agrees with this verifier | SPEC-012 amendment 7 |
| A timestamp authority is trusted **only** through the configured anchors; `c2patool` reports `timeStamp.trusted` for DigiCert and Truepic TSAs with no anchor configured and `untrusted` for a 2025 DigiCert responder — not derivable from the 0.90.22 source (step 40 §5) | C2PA 2.4 §14.6.1: a *trusted* timestamp; trust by observation is not trust | ADR-0004 decision 3; `_TSA_NOT_CONFIGURED` (Truepic ×3, `ocsp*`, `exp-test1`, Amazon, Pixel — `expired` at now here, `Valid` there; with the anchor configured they are equal, measured in SPEC-017 AC6/AC11/AC12) |
| `timeStamp.*` is informational, as at `c2patool`; the timestamp's one effect is the time the signer's validity is judged at | c2pa-rs logs every timestamp fault informational | SPEC-017 |
| A `signingTime` attribute that differs from `genTime` is `malformed` (c2pa-rs prefers `signingTime`) | fail closed; no corpus token has them differ | ADR-0004 decision 5 |
| A failing fragmented stream says **which file** failed; `c2patool` gives the same code for a changed init segment, a changed fragment and a foreign one | a stream is many files, and a verdict that names none leaves the caller to bisect by hand | SPEC-028 AC2–AC4 |
| An ISOBMFF `uuid` box whose purpose this verifier cannot read is an error; `c2patool` reports "no claim found" | a box that announces itself as C2PA and then says something unreadable is not a file without credentials | SPEC-026 AC5 |
| A claim with `redacted_assertions` is refused | the claim-signature hash method and `assertion.notRedacted` are unmeasured; a claim that says "redacted" is not passed on trust | SPEC-011, kept by SPEC-021 |
| A CAWG identity assertion is `Invalid` until validated | `Trusted` on a credential never examined (`C_with_CAWG_data`) | SPEC-013 amendment 7 |
| A header with both `sigTst` and `sigTst2` is `malformed` (c2pa-rs takes `sigTst2`) | fail closed; no corpus file has both | SPEC-016 AC8 |
| The data hash is not read after a hashed-URI *mismatch* on `c2pa.hash.data` (four own variants report a strict subset of `c2patool`'s failures) | the assertion is not what the signer saw | SPEC-011 decision 1, `SPEC013_SUBSET_ONLY` |
| A parse fault stops this verifier where `c2patool` goes on (`json-broken`) | a report, not a guess | `SPEC013_SUBSET_ONLY` |
| Some faults `c2patool` reports with a hard exit (no JSON) are a report here: `claim missing hard binding`, `No Action array in Actions`, undecodable assertions | the caller gets a verdict and a reason either way | SPEC-012, SPEC-018 |
| A hash assertion in an update manifest is `manifest.update.invalid`; `c2patool` reports nothing and validates the assertion as the asset's binding (`Trusted`) — its rule for this sits in unreachable code (`c2pa-rs claim.rs verify_internal`) | C2PA 2.4 §11.2.3: "An Update Manifest shall not contain assertions of types `c2pa.hash.data` …" | SPEC-022 amendment 2 |
| A manifest in the store that **no ingredient assertion names** is never validated — its signature may be broken and the file is still `Trusted` — while both this verifier and `c2patool` still render it under `manifests` | C2PA 2.4 §15.11.3.3: "Validators should ignore any additional C2PA Manifests that appear in the C2PA Manifest Store but are not in the list of ingredient manifests"; §15's vocabulary has no code for one, and this project invents none | step 60, `tests/Fixtures/m7-absence/unreferenced-broken.png` |
| A v3 ingredient assertion **without `claimSignature`** is read, though §18.16.12.3 says both hashed URIs shall be stored (`c2patool` reads it too) | the second URI is needed only for the claim-signature method, which redactions force — and redactions are refused here | step 60, `no-claim-signature.png` |
| `assertion.action.malformed` on the manifest carries the bare manifest label as its url — `c2patool`'s inconsistency, copied so that code and url compare | drift-alarm equality | SPEC-018 amendment 2 |
| The command's exit status carries the verdict (0 `Trusted`/`Valid`, 1 `Invalid`, 2 no report); `c2patool` exits 0 on an `Invalid` report and 1 only when it prints no JSON. A `--settings` file that cannot be read is a refusal (exit 2); `c2patool` ignores it and reports without trust | fail closed: `c2pa-verify "$f" && publish "$f"` must not publish a tampered file, and a mistyped settings path must not turn `Trusted` into an unexamined `Valid` | SPEC-019 (exit status measured 2026-09-22) |

## Same verdict, different informational code

`timeStamp.untrusted` here where `c2patool` says `timeStamp.trusted`
without an anchor — 34 corpus files, informational, no verdict changes.

## What "works" rests on

- One oracle (`c2patool` 0.27.22, pinned); no second independent
  implementation has been run yet.
- Test anchors and anchors cut from tokens; no file has been measured
  under the production C2PA trust list (the project does not fetch it).
- 70 870 randomly mutated files without an escaping exception
  (`bin/fuzz.php`); the 312 mutations that stayed `Valid` were confirmed
  `Valid` by `c2patool` and land in bytes the format leaves uncovered.
- Two wrong `Valid`s found by the absence audit and closed (see
  `SECURITY.md`); the method is now part of every spec.
- Every signature algorithm and every hash algorithm exercised by a file
  a writer produced, not only by a vector (`tests/Fixtures/matrix/`,
  step 59) — which is how a wrong `Invalid` on PHP 8.3 for every
  Ed25519-signed file was found and fixed (SPEC-015 amendment 5).
