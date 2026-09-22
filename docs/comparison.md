# What this verifier does, does not do, and where it differs from `c2patool`

Measured on 2026-09-22 against `c2patool` 0.27.22 (`c2pa/0.90.22`) over the
four fixture corpora — 22 own variants, 24 files of
`c2pa-org/public-testfiles`, 17 of `c2pa-rs`'s own fixtures, 7 from other
writers — plus the signed absence variants. The drift alarms in
`tests/Pest.php` run all of it on every `composer check`; the exceptions
below are the lists there, by name. "Stricter" means this verifier refuses
where `c2patool` accepts; the project allows that only for a named reason
and never the other way round.

## Where `c2patool` can do more

| what | `c2patool` | this verifier | until |
|---|---|---|---|
| Ingredient manifests, manifest chains, update manifests | validates the whole tree | a store with more than one manifest is refused (`general.error`, `Invalid`) — `_MULTI` lists: 10 official files, 7 c2pa-rs, 1 writers | M7 |
| ISOBMFF (MP4, MOV, AVIF), GIF, TIFF, SVG, audio, PDF | yes | JPEG, PNG, WebP only (`unsupported file type`) | M8 and later |
| CAWG identity assertions | validated (their own X.509 credential) | refused (`general.error` on the assertion) — `C_with_CAWG_data`, `cawg_ica` | a CAWG spec |
| Remote manifests (`dcterms:provenance` URL) | fetched over the network | reported as `remote_manifest`, never fetched — `cloud.jpg`, the Photoshop file | never (by design) |
| OCSP staples, certificate revocation | checked (with network) | not checked | never in the verification path |
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
rule — on every corpus file that is not in an exception list, and on every
own variant, the state and the failure codes with their URLs are
`c2patool`'s. Nothing is more lenient.

## Where this verifier differs by design

| difference | why | where named |
|---|---|---|
| A timestamp authority is trusted **only** through the configured anchors; `c2patool` reports `timeStamp.trusted` for DigiCert and Truepic TSAs with no anchor configured and `untrusted` for a 2025 DigiCert responder — not derivable from the 0.90.22 source (step 40 §5) | C2PA 2.4 §14.6.1: a *trusted* timestamp; trust by observation is not trust | ADR-0004 decision 3; `_TSA_NOT_CONFIGURED` (Truepic ×3, `ocsp*`, `exp-test1`, Amazon, Pixel — `expired` at now here, `Valid` there; with the anchor configured they are equal, measured in SPEC-017 AC6/AC11/AC12) |
| `timeStamp.*` is informational, as at `c2patool`; the timestamp's one effect is the time the signer's validity is judged at | c2pa-rs logs every timestamp fault informational | SPEC-017 |
| A `signingTime` attribute that differs from `genTime` is `malformed` (c2pa-rs prefers `signingTime`) | fail closed; no corpus token has them differ | ADR-0004 decision 5 |
| A store with more than one manifest is `Invalid` until M7 | a fault in a manifest not looked at must not yield `Trusted` (`adobe-20220124-E-uri-CIE-sig-CA` was) | SPEC-013 amendment 5 |
| A CAWG identity assertion is `Invalid` until validated | `Trusted` on a credential never examined (`C_with_CAWG_data`) | SPEC-013 amendment 7 |
| A header with both `sigTst` and `sigTst2` is `malformed` (c2pa-rs takes `sigTst2`) | fail closed; no corpus file has both | SPEC-016 AC8 |
| The data hash is not read after a hashed-URI *mismatch* on `c2pa.hash.data` (four own variants report a strict subset of `c2patool`'s failures) | the assertion is not what the signer saw | SPEC-011 decision 1, `SPEC013_SUBSET_ONLY` |
| A parse fault stops this verifier where `c2patool` goes on (`json-broken`) | a report, not a guess | `SPEC013_SUBSET_ONLY` |
| Some faults `c2patool` reports with a hard exit (no JSON) are a report here: `claim missing hard binding`, `No Action array in Actions`, undecodable assertions | the caller gets a verdict and a reason either way | SPEC-012, SPEC-018 |
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
