# Step 157 — A review from the first user's side

*2026-09-27.* The WordPress plugin that bundles this library (Provemark
C2PA Check) runs `verify()` synchronously on every uploaded JPEG, PNG and
WebP, inside an admin request (typically `memory_limit` 256M,
`max_execution_time` 30–60 s), on files from any user who may upload
media. Before that plugin is submitted anywhere, `v0.2.3` was reviewed in
three parts: the parsing of untrusted bytes; signatures, trust and
timestamps; hashes, manifests and the verdict.

**Nothing in `src/` changed in this step.** It records findings; each fix
follows the project's rule (a spec or amendment, a test red on the file,
then the change). The probes are small scripts that splice bytes into
`tests/Fixtures/fixture-signed.png` (lengths and CRCs fixed) and call the
public `Verifier::verify()`. They are kept locally in
`out/step-157-probes/` (gitignored, so not published): `verdict/`
(finding 1: `poc_redact.php`, `poc_redact_multi.php`; duplicate labels:
`poc_duplabel.php`), `crypto/` (finding 2: `tsa-as-signer.php`; the PHP
8.3/8.5 comparison: `diff.php`), `parsers/` (finding 3: `p1_json.php <MB>`,
run with `-d memory_limit=256M`; 4: `p11_hash_repeat.php 1 <N> 8`; 5:
`p7_cose_chunks.php`, `p4_cbor_chunks.php`; 6: `p2_bfdb.php`; the lower
ones `p3`, `p5`, `p6`, `p9`, `p10`). The fixes should turn them into
`bin/make-*-variants.php` fixtures, as before.

Not measured: `c2patool` on the probes (not installed on the machine used);
PHP 8.4 (only 8.3.33 and 8.5.8).

## Wrong verdicts

### 1. An unvalidated manifest can redact the active manifest's assertions — **wrong `Valid`, measured**

`ManifestStore` collects `redacted_assertions` from every manifest it reads
(`src/Manifest/ManifestStore.php:52`, `Manifest::redactionsOf`,
`ManifestGraph::redactions`), and `Manifest.php:236` and
`src/Hash/HashedUriCheck.php:46` skip any claim entry that is redacted and
absent. A manifest that nothing references is never validated, yet its
redactions count, and they are applied to the active manifest too.
C2PA 2.4 §6.8 and §15.11.3.3.1 let only a later manifest that takes the
redacted one as an ingredient redact, so an active manifest's assertion
can never be redacted.

- Measured: `fixture-signed.png` with its signed `c2pa.thumbnail.claim`
  box removed and, before the active manifest, an unsigned manifest of the
  same length whose claim redacts that assertion (store length unchanged,
  so the data hash still matches): **`Valid`**, the same success statuses
  as the original, `toArray()` listing only `c2pa.actions.v2`.
- Measured: removing `c2pa.actions.v2` the same way gives `Invalid` (a v2
  claim needs an actions assertion).
- Reasoned: a v1 claim may have no actions assertion (`ActionsCheck`), so
  its actions, and a generative-AI `digitalSourceType` in them, can be
  removed silently; any assertion other than the hard binding can be
  removed from any version. A trusted file stays `Trusted` (the trust path
  does not look at assertions).
- Direction: honour a redaction of manifest M only when a validated
  manifest that references M as an ingredient declares it; never one
  against the active manifest.

This is the kind of finding step 149 closed for acknowledged faults: data
from a manifest the graph never validates must not change the verdict.

### 2. A time-stamping certificate signs manifests under legacy anchors — **wrong `Trusted`, measured**

With the legacy `trust.trust_anchors` field, `ChainCheck::anchorsOf` and
`tsaAnchorsOf` (`src/Trust/ChainCheck.php:142-168`) share the anchors, and
`CertificateProfileCheck` (`BUILT_IN_EKUS`, line 31) accepts a signer whose
only EKU is timeStamping.

- Measured: the Pixel 10 fixture's TSA leaf ("Google Pixel Time Stamping
  Authority", EKU Time Stamping) checked as a manifest signer with
  `google-pixel-intermediates.settings.json` (legacy format): chain
  `signingCredential.trusted`, 0 profile faults. With the same
  intermediates as a `tsa` entry of `trust.anchors`: `untrusted`.
- Reasoned: whoever holds a TSA key under such anchors can sign manifests
  that come out `Trusted`; this matches `c2pa-rs`'s legacy semantics,
  which is why it is a finding and not a divergence. Kind-separated
  `trust.anchors`, as `docs/trust-settings.md` recommends, avoids it.
- Direction: under legacy anchors, refuse a signer whose EKUs are only
  timeStamping; or document the legacy field as unsafe for mixed lists.

## Crashes and exhaustion (SECURITY.md: "exhaust memory or time")

### 3. A JSON assertion has no size or item limit — **fatal, measured**

`Manifest.php:321` decodes JSON with only a depth limit; SPEC-043's CBOR
item budget does not apply. Measured at `memory_limit=256M`, a JSON
assertion `[[0],[0],…]` in a signed PNG: 1 MB peaks at 72 MB, 3 MB at
203 MB, **4 MB is a fatal "Allowed memory size … exhausted" at
`Manifest.php:321`, exit 255**. Direction: charge JSON to the store's
budget (a byte cap, or count items before decoding).

### 4. A large assertion referenced many times is hashed per reference — **measured**

`HashedUriCheck.php:167/186` hashes `$box->payload()` for every claim entry;
duplicate URLs are neither refused nor cached, and these checks run even
after `claimSignature.mismatch`. Measured with one 8 MB assertion: 300
references 6.1 s, 1,000 references 20.9 s, 3,000 references 62.8 s.
Reasoned: the CBOR budget allows about 12,000, roughly four minutes; the
loop over redacted URIs (lines 85–89) has the same shape. Direction:
refuse duplicate URLs in the claim's lists, or cache the digest per box and
algorithm.

### 5. Indefinite-length string chunks are free, and COSE is decoded about seven times — **measured**

`CborDecoder::chunks()` (lines 230–251) never charges the budget, and
`CoseSign1::fromBytes()` is called from `Verifier.php:176,437,449`,
`ChainCheck:36`, `CertificateProfileCheck:50`, `TimestampCheck:68` and
`ClaimSignatureCheck:46`, each with a fresh budget. An unprotected COSE
header holding a byte string of 14 million empty chunks (unsigned, so the
signature still validates): 48.6 s, peak 102 MB, `Invalid`. The same in one
assertion, decoded once: 7.5 s. Direction: charge each chunk, and decode
the COSE once per manifest.

### 6. An empty `bfdb` box throws `ValueError` — **measured**

`Manifest.php:348` calls `strpos($bfdb, "\0", 1)` before its
`strlen($bfdb) < 2` check; on an empty box PHP 8 throws `ValueError`, which
the README says cannot reach callers (only `TrustException` does). The CLI
exits 255. Direction: check the length first.

## Lower

- **Duplicate manifest labels** (`ManifestStore.php:59/65`): the later
  manifest overwrites the earlier in place, and `array_key_last` then picks
  by first appearance, so with `[L, M, L]` the active manifest is `M`, not
  the physically last (§11.1.4.2). Measured: the report's
  `active_manifest` is the middle one; `Invalid` in the probe. Reasoned: a
  `Valid` would need an earlier binding to match the current asset.
  Direction: refuse duplicate manifest labels.
- **Duplicate assertion labels in one store** (`Manifest.php:160`): the
  verdict fails closed (`assertion.undeclared`, measured), but
  `toArray()` shows the last, unsigned box while the checks hash the first.
- **`x5chain` in the unprotected header** is accepted for every claim
  version (`CoseSign1.php:168-178`; `chainProtected` is computed and
  unused). Reasoned: the leaf can be swapped for another certificate with
  the same key, changing the signer shown.
- **The chain walk** checks signatures, names, `CA:TRUE`, `keyCertSign`
  and `pathlen`, but not nameConstraints, policy constraints, unknown
  critical extensions (RFC 5280 §4.2, §6.1) or intermediates' signature
  algorithms (`ChainCheck.php:205-221`, `Certificate.php:197-204`).
  Reasoned.
- **Stapled OCSP** is matched against `chain[1]` without checking that it
  issued the leaf (`OcspCheck.php:118-120`); it can add a misleading
  `ocsp.notRevoked`, never turn a failure into a pass. Reasoned.
- **ESSCertID(v2)** in the timestamp's signed attributes is not compared
  with the TSA certificate (`SignerInfo.php:129-150`; RFC 3161 §2.4.1,
  RFC 5816). Reasoned.
- **Key type by substring** (`PublicKey.php:61-79`): an EC key whose X
  coordinate happens to contain the Ed25519 OID would be misread; fails
  closed. Reasoned, about 1 in 2^40.
- **Linear scans on tiny items**: 16 MB of 4-byte JPEG segments 4.2 s, of
  `FF` fill bytes 2.8 s, zero-length PNG chunks 2.8 s, WebP 1.1 s
  (measured); a chunk-count cap like ISOBMFF's `maxBoxes` would bound it.
- **DER copies contents per nesting level** (`DerReader.php:129`): 32
  nested SEQUENCEs in 1 MB retain 33 MB, transiently (measured).
- **Colliding CBOR integer keys**: 32k keys that are multiples of 65,536
  take 1.46 s against 0.05 s (measured); capped by the item budget.
- Exclusions outside the manifest store (`DataHashCheck.php:217-236`) and
  numbered hard-binding labels (`c2pa.hash.data__1`, line 58) follow the
  specification and are signed; recorded for completeness.

## Checked and sound

Algorithm only from the protected header, integer label; the Sig_structure
from the stored protected bytes and the exact claim box; key type and size
bound to the algorithm; every `openssl_verify` and `openssl_x509_verify`
counted only on `=== 1`; ECDSA raw-to-DER including P-521; RSA-PSS without
a PKCS#1 v1.5 fallback; Ed25519 through `sodium`. Anchors matched by DER or
a verified signature, never by name. Timestamp imprint, `messageDigest`,
content type, single SignerInfo, TSA profile and chain; its time used only
when validated and trusted. Data-hash exclusions sorted, bounded, capped
at 1024, the store covered exactly. Every superbox the claim does not name
is `assertion.undeclared`. The verdict: `Valid` needs a success and no
failure but `untrusted`. No network code. `TrustSettings` come only from
the caller. Container, JUMBF, CBOR and DER limits from SPEC-043 hold where
they apply. All 349 image fixtures with and without settings give
identical verdicts on PHP 8.3.33 and 8.5.8, with no warning.

## What this means for the project's record

By SECURITY.md's definition a wrong verdict is one that `c2patool` or the
specification would refuse. Finding 1 is refused by the specification's
text (§6.8, §15.11.3.3.1; `c2patool` still to be run on the probe).
Finding 2 is what `c2pa-rs` does with legacy anchors, so it may be a
divergence to document rather than a vulnerability; to decide. Findings
3–6 are crashes or exhaustion, which SECURITY.md counts.

This note is committed locally and not pushed: the repository is public,
and SECURITY.md keeps unfixed wrong verdicts out of public view. When to
publish it is the maintainer's decision; SECURITY.md's "Findings so far"
follows then.
