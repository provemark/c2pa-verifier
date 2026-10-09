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
| TIFF, SVG, PDF | yes | JPEG, PNG, GIF, WebP, WAV, AVI, MP3, FLAC and ISOBMFF only (`unsupported file type`); RF64 (WAV over 4 GB) is refused by both | later, one spec per format (`notes/step-203-format-survey.md`) |
| A JPEG whose first APP11 piece carries a packet sequence number Z other than 1, or whose later pieces skip a number | read: `c2pa-rs` does not check the first piece's Z, and a later Z only has to exceed the pieces already taken | **Z = 0 on the first piece is read** (SPEC-041: Microsoft Bing Image Creator writes every store this way); any other first Z and any gap stay refused (`general.error`), measured on synthetic variants only | — |
| A claim whose URIs are written `self#jumbf=c2pa/<manifest>/…`, without the leading slash (the spec's own examples from 1.0 to 2.1; Microsoft Bing Image Creator, nine files from Commons, step 141) | read as absolute (`c2pa-rs` `to_normalized_uri`) | read as relative to the manifest, as C2PA 2.4 §8.4.2.1 says, so the signature is `claimSignature.missing` (§15.7). Deferred, not by design (ADR-0005): the strictness protects nothing, but reading the URI moves no verdict while these files' `c2pa.hash.boxes` is refused by name (`PRED-CONT-006`) | SPEC-042, as the first step of box hashes |
| An external reference whose `location` carries `alg` without `hash`, or `hash` without `alg` | read as unhashed and accepted (both versions) | `assertion.external-reference.malformed` (SPEC-032 AC5, C2PA 2.4 §15.10.3.2.2). The strictness protects nothing: an unhashed external reference is allowed anyway (ADR-0005, step 145) | a file that carries it; none has so far |
| A timestamp token whose `signingTime` attribute differs from `genTime` | `signingTime` used | `malformed` (ADR-0004 decision 5). The strictness protects nothing: both times are inside the TSA's signature (ADR-0005, step 145) | a file that carries it; none has so far |
| A `c2pa.redacted` reference to a data box that the claim's own `redactions` lists | passes | `assertion.notRedacted` (SPEC-037 open question 3). The reason was a missing fixture, not a protection (ADR-0005, step 145) | a file that carries it; none has so far |
| A COSE header with both `sigTst` and `sigTst2` | `sigTst2` used | `malformed` (SPEC-016 AC8). The strictness protects nothing: the token used must still match the signature and reach a trusted TSA (ADR-0005, step 145) | a file that carries it; none has so far |
| A certificate serial number longer than 256 octets (RFC 5280 allows 20; no file measured carries more than 20) | read: both versions call a 257-octet serial `Trusted` | `signingCredential.invalid`, naming the bound (SPEC-015 amendment 6). A resource bound, not a protection of the verdict: without gmp or bcmath the decimal conversion is quadratic, and an unbounded serial is a denial of service with no key (step 154) | a real certificate that needs more |
| A certificate serial number that is negative or zero (RFC 5280 §4.1.2.2: "MUST be a positive integer") | read: both versions, and OpenSSL, call a leaf or an intermediate with serial `-0x0FDB…` or `0` `Trusted` | the leaf `signingCredential.invalid`, any other certificate of the path `signingCredential.untrusted`, naming the serial (SPEC-015 amendment 7). The strictness protects a verdict: this verifier's DER reader refuses a negative INTEGER, so a stapled OCSP response about such a certificate could not be read and a revocation was skipped (step 301) | a real certificate authority that issues such serials |
| A leaf signed with RSASSA-PSS over SHA-256 whose MGF1 is left at its default, SHA-1 | `Trusted` in both versions: `c2pa-rs` means to refuse an MGF1 hash that differs from the PSS hash, but cannot parse the defaulted field and logs no status | `signingCredential.invalid`, naming MGF1 over SHA-1 (SPEC-015 amendment 8). The strictness keeps the rule `c2pa-rs` states, and fails closed | a real certificate authority that issues such certificates |
| A certificate whose outer `signatureAlgorithm` differs from tbsCertificate's `signature` (RFC 5280 §4.1.1.2), for a non-PSS algorithm | not compared by `c2pa-rs`; OpenSSL refuses it | the leaf `signingCredential.invalid` (SPEC-015 amendment 8). For RSASSA-PSS both versions refuse the measured cases on their own ground | — |
| An ISOBMFF `uuid` box that announces C2PA but whose purpose cannot be read | "no claim found" | an error (SPEC-026 AC5). Neither answer is `Valid`; only what the report says differs (ADR-0005, step 145) | a file that carries it; none has so far |
| ISOBMFF | validated, hard binding included | **MP4, MOV, AVIF and HEIC** read and verified, hard binding included, each held by a fixture (SPEC-026, SPEC-027). **Fragmented streams verified too** (SPEC-028): the init segment against `initHash` and every fragment against the Merkle root. **`c2pa.hash.bmff.v2` is verified too** (SPEC-029), nested exclusion paths and `subset` filters included — `c2pa-rs`'s own `video1.mp4` carries one, and under the same trust anchors this verifier and `c2patool` agree status for status. The `length`/`version`/`flags`/`exact` filters and an assertion with more than one `merkle` map are refused by name | a stream with several renditions |
| CAWG identity assertions | validated (their own X.509 credential) | refused (`general.error` on the assertion) — `C_with_CAWG_data`, `cawg_ica` | a CAWG spec |
| Remote manifests (`dcterms:provenance` URL) | fetched over the network | reported as `remote_manifest`, never fetched — `cloud.jpg`, the Photoshop file. Only the first 8 MiB are searched: XMP that sits later (a WAV's `_PMX` chunk after the audio, an MP4's metadata at the end) is not reported. For WAV, `c2patool` 0.27.22 and 0.28.1 report no remote manifest at all (measured in step 213 on a 1 kB and a 9 MB WAV) | never (by design) |
| OCSP staples, certificate revocation | checked (with network) | **the responses stapled into the signature are checked** (SPEC-030): a verified `revoked` makes the file `Invalid`, and every file reports whether revocation was checked at all. c2patool 0.27.22 emits no OCSP code of its own on the two fixtures that carry a stapled response, so this verifier says more here, not less | an online OCSP query, an AIA fetch or a CRL — never in the verification path |
| Assertion content beyond the actions rules of SPEC-018, SPEC-032, SPEC-033 and SPEC-034 — `assertion.required.missing` | validated | not read (`SPEC013_NOT_YET`) — no corpus file shows a difference | M7 / a spec |
| `c2pa.hash.data.part`, `c2pa.hash.multi-asset` (a second asset's hashes, e.g. Ultra HDR) | not validated either | not read | — |
| JSON report | assertions rendered, thumbnails, ingredient tree | `c2patool`'s five keys, `format`, `has_manifest`, `remote_manifest`, `checks_performed`; assertions rendered as decoded (byte strings as base64, a NaN or infinity as `"NaN"`, `"Infinity"`, `"-Infinity"`), no thumbnails | — |
| Command line | `c2patool <file>` with `--detailed`, `--info`, signing, trust sub-commands, fragments | `bin/c2pa-verify <file> [--settings <path>]`: the JSON report, nothing else (SPEC-019) | — |

## Where the verdicts are equal (measured, code for code)

**A store that can be hidden, here as in `c2patool`** (WebP, WAV; steps
214–218, both versions): a header size that ends before the `C2PA` chunk,
an earlier chunk whose length runs to or past the end of the RIFF chunk,
or stray bytes that misalign the walk before the `C2PA` chunk all make the
file read as having no manifest. Deleting the chunk does the same; C2PA
cannot prevent stripping. "No manifest" never proves that a file had none.

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

Since step 247 three more are equal. An unknown critical X.509 extension
is `signingCredential.invalid` on the signer and makes an intermediate
`signingCredential.untrusted`, as both versions say (SPEC-046, measured
on `tests/Fixtures/chain-constraints/`). A hard binding under an instance
label (`c2pa.hash.data__1`, C2PA 2.4 §6.4) is verified, and beside another
is `assertion.multipleHardBindings` (SPEC-012 amendment 9; `c2patool`
also runs both hashes). A name constraint over a name that is not UTF-8
is judged byte for byte, as `c2patool` 0.28.1 judges it; 0.27.22 calls
both such files `Invalid` with `claimSignature.mismatch` (SPEC-046
amendment 1).

One case is equal on purpose, and recorded because an upstream fix sits
next to it (step 192). Two fragments of a fragmented stream exchange their
contents, each keeping its own Merkle proof (`seg_2` ↔ `seg_3`). The set
is `Trusted` here and in `c2patool` 0.27.22 and 0.28.1 (step 193). `c2pa-rs` #2702 (on main
since 2026-09-28, in no release yet) makes `location` follow the
physical order of Merkle boxes *inside one file*. This verifier refuses
such a file outright (two C2PA `uuid` boxes, `general.error`). A check of
order across separate fragment files would need the caller's order to be
the playback order, and is left until `c2pa-rs` adds one.

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
| Plain text (C2PA 2.4 §A.8) is read only when the caller turns it on (`Verifier(text: …)`, `--text`); stock `c2patool` does not read text at all, and the oracle is `c2patool` 0.28.1 built with `unstable_plain_text` | experimental upstream, and Unicode's L2/26-042 objects to the scheme: a verdict format a caller chose, not one that appears under them (step 265) | SPEC-060 open question 1 |
| Plain text: two wrappers, a length field longer than the run (a store cut short, or a run broken by a letter), and a length field that differs from the store's LBox are refused (`general.error`); the oracle says *No claim found* to the first three and `Valid` to the last, reading the store with a padding byte after it | a text carries one store, and its frame must describe it; none can make a `Valid` here that the oracle does not give (step 265) | SPEC-060 AC5, AC6, amendment 1 B |
| Plain text: a candidate of the magic and version 1 whose store does not fit counts as a wrapper — before a good wrapper it is AC6's error, after one it makes two wrappers — where the oracle skips it and reads the good one. A length field under 8, and a store with an extended LBox (`1`), are refused; the oracle fails in its JUMBF reader or reads the extended size | a frame of this text's own magic that contradicts itself is not passed over in silence (step 269) | SPEC-060 amendment 2 B, D |
| Plain text, with text on: an empty file is `text` without a manifest, and a text that begins with another format's signature (`GIF89a`, `RIFF…WEBP`, `ID3`, `ftyp` at offset 4) is read as that format; the oracle picks its reader by the `.txt` extension | this verifier is given a stream, not a file name (SPEC-060 open question 2) | SPEC-060 AC16 (amendment 2) |
| MP3: a C2PA GEOB frame that is compressed, encrypted or under unsynchronisation, that runs past its tag, whose LBox differs from its object, or that appears twice, is refused (`general.error`); `c2patool` finds no claim, or reads the first and judges by the hash, or (LBox) says `Valid` | strict about the store, as for RIFF: each makes the store's bytes or extent uncertain (step 225, both versions) | SPEC-056 AC6–AC10 |
| An assertion holding a CBOR NaN or infinity: `c2patool` stops with a decode error; here the file gets its report, the value named `"NaN"`, `"Infinity"` or `"-Infinity"` | JSON has no such numbers (RFC 8259 §6), and a report that cannot be written was a crash (step 247) | SPEC-007 amendment 6, SPEC-019 amendment 2 |
| A FLAC cut short inside its ID3 tag is reported as `mp3`; `c2patool` errors | what follows a tag that runs past the end of the file cannot be seen; the verdict, `Invalid`, holds (step 243) | a known limit, CHANGELOG 0.3.0 |
| A JPEG with a non-C2PA JUMBF in APP11, then a broken segment, reports `has_manifest: true` | the store is taken as reached at an APP11 JUMBF piece, before its label is read; rare (step 243) | a known limit, SPEC-001 amendment 5 |
| FLAC: an ID3 tag followed by neither `fLaC` nor MPEG audio (a damaged marker, another stream) is `unknown`; `c2patool` reads the tag by the file extension and judges the hash | this verifier never reads a file extension; both say `Invalid` (step 234) | SPEC-057 AC4 |
| MP3: an ID3 tag size that is not syncsafe, or a tag whose size leaves no MPEG audio after it, is `unknown` or `general.error`; `c2patool` finds no claim | the tag header contradicts itself, or the file is not recognisably MP3 | SPEC-056 AC4, AC13, amendment 1 |
| MP3: the grouping flag on a GEOB that still reads as C2PA (no group byte where the flag says one is) is refused; `c2patool` finds no claim | the flag says the body's first byte is something else; reading it as C2PA anyway let a signer get `Valid` here where `c2patool` finds nothing (step 232) | SPEC-056 AC22 |
| MP3: free-format MPEG audio without an ID3 tag is `unknown` | its header gives no frame length to find a second header by (AC17), and accepting one header lets text files through; a known limit | SPEC-056 AC25 |
| MP3: a frame id that is not four capitals or digits, and unsynchronisation with `FF 00` inside the tag, are refused (`general.error`); `c2patool` reads past the first and finds no claim in the second | a walk that has lost its place, or bytes that unsynchronisation changes, could hide a store; the fault keeps that visible (step 230) | SPEC-056 AC18, AC19 |
| MP3 signed by c2pa-ts (an independent writer): its data-hash exclusion covers the whole ID3 tag, not the store; `Invalid` here and in `c2patool` 0.28.1, `Valid` in 0.27.22 | an exclusion wider than the store leaves bytes unhashed that the store does not need (SPEC-012's rule; 0.28.1 now checks the same) | SPEC-056 AC15 |
| A RIFF header size below 4 (WebP, WAV) is refused (`general.error`); `c2patool` finds no claim | the header cannot hold its own form type: it contradicts itself (step 218) | SPEC-003 amendment 4, AC19 |
| A RIFF header size larger than the file (WebP, WAV) is refused (`general.error`) after a scan of the chunk headers; `c2patool` reads `riff-size-plus-one` and calls it `Invalid` with a hash mismatch | reading it would mean walking past the end the header declares; both say `Invalid`, and the report says a manifest was there | SPEC-003 AC5, AC16 and amendment 4 |
| GIF: more than 4,096 extensions before the first image are refused (`general.error`); `c2patool` reads past them (a signed GIF with 5,000 comments: `Trusted` in 0.27.22 and 0.28.1) | a bound on every walk, as ISOBMFF's boxes, RIFF's chunks and ID3's frames (SPEC-024); a real GIF carries a handful | SPEC-059 AC10, amendment 1 D |
| GIF: two `C2PA_GIF` blocks are refused (`general.error`); `c2patool` reads the first and the data hash catches the second (`Invalid`, `assertion.dataHash.mismatch`), or, when the first is empty, says *No claim found* (amendment 1 C). A malformed block (a block size that is not 11, a payload that is not JUMBF, stray bytes after the store, a store cut short) is `Invalid`, `general.error`; `c2patool` stops with an error | a GIF carries one store (C2PA 2.4 §A.3.8: "Quantity: One"); the same verdict state, another code (step 256) | SPEC-059 AC4, AC5, AC6 |
| A RIFF `C2PA` chunk (WebP, WAV, AVI) whose length and the LBox of the box inside it disagree is refused (`general.error`); `c2patool` 0.27.22 and 0.28.1 call `webp/lbox-differs` and `wav/lbox-differs` `Valid`. With the chunk length one too long instead, 0.28.1 now refuses too (`length-differs`, its new location check) | the container disagrees with itself about where the store ends; SPEC-003 refused it from the start (decided 2026-09-20) and SPEC-055 holds WAV to the same rule. Measured on both versions in step 204 | SPEC-003 AC9–AC10, SPEC-055 AC8–AC9 |
| An icon that names a data box (earlier versions' mechanism) is `assertion.missing`; `c2pa-rs` accepts it without a hash check, and C2PA 2.4 §10.2.3.2 says consumers *should* support data boxes | maintainer's decision (SPEC-034 option A): no file shows one, and an unchecked reference is what the rule exists to refuse | SPEC-034 amendment 1 |
| An icon map without a `url` (a resource reference, which `c2patool` 0.28.0's builder writes for `softwareAgents`) is not checked, as in `c2pa-rs` | only hashed URIs are references | SPEC-034 amendment 2 |
| `relatedAssertions` and the watermark's soft binding are checked; `c2patool` 0.27.22 did not check them (0.28.0 does) | C2PA 2.4 §15.10.3.2.3 | SPEC-033 AC6–AC7 |
| An ingredient manifest whose claim `alg` is outside `sha256`/`sha384`/`sha512` (`crc32b`): its own hashed URIs are `algorithm.unsupported`; `c2patool` 0.27.22 reports `assertion.hashedURI.mismatch` for them. Same state (`Invalid`), and the ingredient check's own code is `c2patool`'s | `HashedUriCheck` names the reason (C2PA 2.4 §13.1) where `c2pa-rs` computes no hash and compares | SPEC-052 amendment 1, AC5 |
| A signer certificate whose validity is a UTCTime without seconds (`2401010000Z`, BER that RFC 5280 §4.1.2.5.1 and DER forbid) is `signingCredential.invalid`; both `c2patool` versions read it (`Valid`, `untrusted` with the anchor supplied) | OpenSSL 3.6 refuses to read the certificate, and the verifier's own DER reader refuses the time where an older OpenSSL reads it; no real certificate is known to carry one | SPEC-044 AC4 |
| A validity time with a fraction of a second (`20500101000000.5Z`, which RFC 5280 §4.1.2.5.2 forbids) is read with the fraction dropped, as `c2patool` 0.27.22 does; 0.28.0 does not trust such a certificate, for a reason not measured | ADR-0005: refusing would protect no verdict. An expired certificate with a fraction is `signingCredential.expired` in all three | SPEC-044 amendment 1 |
| An action's ingredient reference is resolved by its label, as `c2pa-rs` does, not by its hash; a `c2pa.removed` reference is looked up in the current claim, as `c2pa-rs` does, where §15.10.3.2.3 says *another manifest* | maintainer's decision (open question 2); no fixture shows either reading | SPEC-033 open questions 2–3 |
| The external-reference checks themselves (a `location` with a `url`, the forbidden labels) are `c2patool` 0.28.0's; 0.27.22 accepted all of them | §15.10.3.2.2 | SPEC-032 AC4–AC5 |
| `c2pa.created` without `digitalSourceType` is refused in v2 claims only, as `c2pa-rs` does; C2PA 2.4 states it for the claim generator (§18.15.2), not among §15's validation steps | the verdict follows `c2patool` (maintainer's decision) | SPEC-032 AC1–AC2 |
| A hard binding referenced only from `gathered_assertions` is `claim.hardBindings.missing`; `c2patool` 0.27.22 accepted it (0.28.0 refuses it too, without a report) | C2PA 2.4 §10.2.2: `created_assertions` shall reference the hard binding | SPEC-013 amendment 13, AC19 |
| The allowed list never trusts a timestamp authority; `c2pa-rs` 0.91.0 checks its end-entity set for TSAs too (read, not measurable through `c2patool`, which trusts these TSAs without anchors) | C2PA 2.4 §14.4.3: the private credential store *"shall not apply to validating time-stamps"*; step 114 showed a TSA on the list excusing an expired signer | SPEC-017 amendment 5, AC13 |
| Neither this verifier nor `c2patool` ties trust anchors to EKUs (C2PA 2.4 §14.5.1.2): a certificate without the claim-signing EKU under a C2PA Trust List anchor is `Trusted` in both | the shared settings format cannot express the association; named rather than invented | `docs/conformance.md`, *Outside the catalogue*; `notes/step-113-anchors-and-ekus.md` |
| A leaf whose only EKU is the C2PA claim-signing OID (`1.3.6.1.4.1.62558.2.1`), or documentSigning, is accepted by default; `c2patool` 0.28.0 calls it `signingCredential.invalid` unless `trust_config` lists it (0.27.22 accepted it) | C2PA 2.4 §14.4.1 makes claim-signing *the* C2PA signer EKU; 0.28.0 no longer applies its own `valid_eku_oids.cfg` by default (step 112). It shows on real files too: OpenAI signers issued by Trufo carry only these two EKUs, and 0.28.0 calls six GPT Image 2 and 2.5 files from September 2026 `Invalid` that both this verifier and 0.27.22 call `Valid` (step 141) | SPEC-015; `notes/step-112-claim-signing-eku.md`; ADR-0005, addendum |
| A `trust.anchors` entry counts only for its own `trust_kind`: a `"tsa"` or `"cawg"` entry anchors no signer, a `"manifest"` entry anchors no timestamp authority; `c2patool` 0.28.0 lets every entry anchor everything | C2PA 2.4 §14.4.1–§14.4.2 keep the lists separate; the `"cawg"` list is the Mozilla S/MIME root store, and copying `c2patool` would let an ordinary S/MIME certificate sign C2PA content as `Trusted` | SPEC-031 AC6 |
| Under the legacy `trust.trust_anchors`, a signer whose only EKU is Time Stamping is `signingCredential.untrusted` unless a `"manifest"` entry or the allowed list vouches for it; both `c2patool` versions call it `Trusted` | the legacy field anchors time-stamping authorities too, so a TSA key would otherwise sign manifests as `Trusted` (ADR-0005: it prevents unchecked trust) | SPEC-031 AC9 |
| A name constraint of a form other than `directoryName` or `rfc822Name` (`dNSName`, URI, IP address, …) makes every certificate below it `signingCredential.untrusted`; both `c2patool` versions, through OpenSSL, evaluate those forms and can call such a chain `Trusted` | ADR-0005: a constraint this verifier cannot evaluate is not taken as met; no signing chain measured carries name constraints at all | SPEC-046 AC5 |
| In a claim v2 or later, a signer's `x5chain` outside the protected header is `signingCredential.invalid`; both `c2patool` versions read an unprotected `"x5chain"` for every claim version and call such a file `Trusted` | the unprotected header is not signed (RFC 9052 §3), so the certificate shown as the signer could be swapped for another for the same key; a claim v1 keeps the older form (maintainer's decision, ADR-0005) | SPEC-047 AC3 |
| A certificate between the anchor and the leaf signed over MD5 or SHA-1 (RSASSA-PSS included, whose default hash is SHA-1) makes the path `signingCredential.untrusted`, and a leaf signed with RSASSA-PSS over SHA-1 is `signingCredential.invalid`; OpenSSL and both `c2patool` versions accept all of them | chosen-prefix collisions on SHA-1 and MD5 let a certificate be forged that its CA never issued (ADR-0005: it prevents unchecked trust); RSA keys of 1024 bits stay as the oracles judge them | SPEC-048 |
| An RSA key whose public exponent is below 3 or even (read from its subjectPublicKeyInfo, so `id-RSASSA-PSS` keys included) is `signingCredential.invalid` in the leaf and makes the path `signingCredential.untrusted` between the anchor and the leaf; `c2patool` 0.27.22 and 0.28.0 call an `e = 1` leaf `Trusted`, c2pa-rs 0.91.1 refuses it in the leaf only | with `e = 1` a signature needs no private key, so the leaf's claim, or anything an intermediate issued, could be made by anyone (ADR-0005: it prevents a wrong `Trusted`); RFC 8017 §3.1 requires an odd exponent of at least 3 | SPEC-049 |
| A top-level `trust.allowed_list` is refused (exit 2, a message naming `trust.anchors[].allowed_list`); `c2patool` 0.28.0 drops it without a word, 0.27.22 honoured it | the same settings file would otherwise mean `Trusted` here and `Valid` there, silently; it prevents no wrong `Valid`, but a shared settings file whose meaning differs without a word is a trust fault for whoever shares it (step 145) | SPEC-031 AC4 |
| An unknown key inside a `trust.anchors` entry, or an `allowed_list` on a `"tsa"` entry, is refused; `c2patool` 0.28.0 ignores both | settings are whole or absent (SPEC-014 AC7): a mistyped key would otherwise drop a restriction silently and widen trust; §14.4.3 keeps the allowed list away from time-stamps | SPEC-031 AC5, AC6 |
| The legacy `trust.trust_anchors` string anchors signers *and* timestamp authorities, as in `c2patool` — which §14.4.2 (*"shall be separate"*) does not want | kept for compatibility: every existing settings file keeps its meaning; `trust.anchors` is the conformant way | SPEC-031 open question 5 |
| A `c2pa.hash.data` exclusion that holds the store **and** other bytes is `assertion.dataHash.mismatch`; `c2patool` 0.27.22 accepts it and calls the three `truepic-20230212-*` files `Trusted` under their root | C2PA 2.4 VAL-ASSE-0043/0044, and a changed EXIF date stayed `Trusted` under 0.27.22's rule (step 108); `c2patool` 0.28.0 agrees with this verifier | SPEC-012 amendment 7 |
| A timestamp authority is trusted **only** through the configured anchors; `c2patool` reports `timeStamp.trusted` for DigiCert and Truepic TSAs with no anchor configured and `untrusted` for a 2025 DigiCert responder. Step 40 §5 found this not derivable from the 0.90.22 source; `c2pa-rs` at `ada3e4a` says why: for a **claim v1** it switches `verify_timestamp_trust` off (`claim.rs`), and `c2patool` 0.28.0 reports such a stamp as *"legacy timestamp cert trusted"* (step 146) | C2PA 2.4 §14.6.1: a *trusted* timestamp; trust by observation is not trust. It prevents a wrong `Valid`: an unanchored TSA can place an expired or stolen signer's signature inside its validity (ADR-0005) | ADR-0004 decision 3; `_TSA_NOT_CONFIGURED` (Truepic ×3, `ocsp*`, `exp-test1`, Amazon, Pixel — `expired` at now here, `Valid` there; with the anchor configured they are equal, measured in SPEC-017 AC6/AC11/AC12) |
| A timestamp authority's leaf that fails the certificate profile (no keyUsage, `CA:TRUE`, SHA-1) makes a version 2 claim's file `Invalid` with `signingCredential.invalid`, as in `c2patool` 0.28.1 — the code is `c2pa-rs`'s, which logs the TSA's profile faults under the signer's code; the explanation here names the timestamp authority. With `verify_trust: false` this verifier checks no TSA at all, where `c2patool` still does (its separate `verify_timestamp_trust`) | equal verdicts; the `verify_trust: false` case is the one place this verifier is more lenient, and only when the caller turns trust off | SPEC-017 amendment 8, AC14 |
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
- Fuzzing before 0.5.2 (step 285): the same seed over the same 249 files,
  12 903 runs, once in a worktree at `v0.5.1` and once at 0.5.2: 0 faults
  in both, the same 122 files `Valid`, each confirmed by the oracles as
  before 0.5.1.
- The trust matrix (step 283, `bin/make-trust-matrix.php`): 42 chains,
  each one property of one certificate away from a valid one, judged by
  both `c2patool` versions and OpenSSL. Under 0.5.2 this verifier differs
  from 0.28.1 on two, both stricter by design: a leaf whose only extended
  key usage is Time Stamping, and a SHA-1 signature between the anchor and
  the leaf.
- Fuzzing before 0.5.1 (step 277): the same seed (20261005, 60 rounds)
  over every format's corpus files, 12 903 runs over 249 files, once in a
  worktree at `v0.5.0` and once at 0.5.1: 0 faults in both, **the same 122
  files `Valid` in both**, 120 of them `Valid` in `c2patool` 0.27.22 and
  0.28.1 and the 2 texts `Valid` in 0.28.1 built with
  `unstable_plain_text`.
- Fuzzing before 0.5.0 (step 270): the same seed (20261005, 60 rounds)
  over the formats 0.4.0 read, 11 733 runs over 226 files, once under 0.4.0
  and once under 0.5.0: 0 faults in both, **the same 128 files `Valid` in
  both**, and all 128 `Valid` in `c2patool` 0.27.22 and 0.28.1. Plain text:
  three seeds of 400 rounds over the 23 text fixtures, 23 100 runs, 0
  faults, the 23 that stayed `Valid` (a bit flipped in the padding) `Valid`
  in the oracle too (step 269).
- Fuzzing before every release (`bin/fuzz.php`): before 0.4.0, 17 109
  randomly mutated files over 317 corpus files, GIF included, every report
  also written as JSON, without an escaping exception (step 261); the 127
  mutations that stayed `Valid` were confirmed `Valid` by `c2patool`
  0.27.22 and 0.28.1, as were the 471 of 47 340 GIF-focused runs. The same
  seed over the formats 0.3.0 already read leaves exactly the same 118
  files `Valid` under 0.3.0 and 0.4.0 (step 262); between 0.2.9 and 0.3.0
  it was 66 (step 243). Before 0.2.8, the same check found SPEC-052's fixed fault again on
  its own (step 194).
- Two wrong `Valid`s found by the absence audit and closed (see
  `SECURITY.md`); the method is now part of every spec.
- Every signature algorithm and every hash algorithm exercised by a file
  a writer produced, not only by a vector (`tests/Fixtures/matrix/`,
  step 59) — which is how a wrong `Invalid` on PHP 8.3 for every
  Ed25519-signed file was found and fixed (SPEC-015 amendment 5).
