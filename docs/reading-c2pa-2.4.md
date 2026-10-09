# Reading C2PA 2.4 against the verifier

`docs/conformance.md` checks this verifier against a third party's catalogue
of C2PA 2.4 rules. This document goes to the source instead. Chapter by
chapter, it lists every normative sentence of the C2PA Technical
Specification 2.4 (April 2026, the newest published version on 2026-10-09)
that a validator has to meet. For each one it says where this verifier
meets it, and how that is known.

The text was read from
<https://spec.c2pa.org/specifications/specifications/2.4/specs/C2PA_Specification.html>
(SHA-256 of the page as fetched on 2026-10-09: `d55caebd…d26d`). Rules are
paraphrased; the section number is the reference.

**Why it is needed.** The fuzzer and the matrices test only rules someone
thought of. `c2patool` shows what `c2pa-rs` does, which is not always what
the specification says. The catalogue summarises 237 rules as 150
predicates. On 2026-10-09 a fault in the RSASSA-PSS parameters (SPEC-015
amendment 8) passed all three, although §14.5 states the rule.

## How to read the tables

| verdict | meaning |
|---|---|
| **covered** | the rule is enforced, or met by construction |
| **partial** | part of the rule is enforced; the row says which part is not |
| **by design** | this verifier does something else on purpose; the row names the decision |
| **n/a** | the rule addresses something this verifier does not have |
| **candidate** | not met, or not known to be met; listed under *Candidates* for a probe |

*How known* is **measured** (a test, a probe or a corpus run says so) or
**read** (from the code, not yet measured).

## §14 Trust Model — §14.1 to §14.4 (step 308)

§14.1 and §14.3.1 are descriptive; they hold no rule for a validator.

| § | rule (paraphrased) | where | verdict | how known |
|---|---|---|---|---|
| 14.2 | The signer's credential is listed in the COSE protected header. Older versions allowed the unprotected header. | `CoseSign1::ofManifest()`: a version 2 claim whose chain is not protected is refused; a version 1 claim keeps the older form (SPEC-047) | covered | measured: `X5chainPlacementTest` AC3 |
| 14.2 | A COSE_Sign1 with no credential is rejected. | `CoseSign1::findChain()` | covered | measured: `X5chainPlacementTest` AC1 |
| 14.2 | Exactly one credential in the union of both headers; two or more, also the same one repeated in both headers, are rejected. | `findChain()` refuses an `x5chain` in both headers (SPEC-047). It does not refuse label 33 and `"x5chain"` together in the protected header (33 wins), and it does not read label 33 from the unprotected header, so 33 in both headers counts as one | **partial** | measured: `X5chainPlacementTest` AC2; the rest read → candidate C1 |
| 14.3.2 | A manifest is Well-Formed, Valid or Trusted; Trusted implies Valid, Valid implies Well-Formed. | `ValidationResult`: `Invalid`, `Valid`, `Trusted`, the vocabulary of `c2patool`; Well-Formed is not reported as a state of its own | by design: `c2patool`'s `validation_state` is the only vocabulary (README, "`c2patool`'s verdict, verbatim") | read |
| 14.3.3 | An asset is Valid when the bytes its content bindings cover are unchanged and its active manifest is Valid or Trusted. | the hard binding's failure codes make the file `Invalid` (SPEC-012 for `c2pa.hash.data`, SPEC-027 and SPEC-029 for ISOBMFF, and each format's reader for the bytes it excludes) | covered | measured: those specs' tests |
| 14.3.4 | Well-Formed: the normative requirements, the assertions allowed for the manifest's type, the assertion rules, the ingredient rules. | each through its own spec; §15.10 and §15.11 will be read in their own steps | covered, as far as those chapters are | read |
| 14.3.5 | Valid: Well-Formed; unmodified since signing; `claimSignature.validated`; `claimSignature.insideValidity`; not `signingCredential.ocsp.revoked`. | `ValidationResult` reads it the other way round, as `c2pa-rs` does: `Valid` is at least one success and no failure but `signingCredential.untrusted`. `ocsp.revoked` is a failure (SPEC-030) | covered in effect | measured: of 29,915 `Valid` or `Trusted` reports (every fixture under no settings and each settings file, 103,796 runs), none lacks `claimSignature.validated` or `claimSignature.insideValidity`; `OcspCheckTest` AC3 → candidate C2 for a positive check |
| 14.3.6 | Trusted: Valid, and `signingCredential.trusted`. | `ValidationResult`: the active manifest's own `signingCredential.trusted` and no failure (SPEC-014 amendment 6) | covered | measured: the same run, none `Trusted` without it |
| 14.4.1 | A validator keeps a list of accepted EKUs and, for each, a list of trust anchor configurations. | `trust_config` (the EKUs) and `trust.anchors` (the anchors) are kept, but not tied together per EKU | by design: gap recorded in `docs/conformance.md` (which names it §14.5.1.2; see C3) | read |
| 14.4.1 | A trust anchor configuration holds the anchor's certificate; it should hold a `notBefore` and, once trust ended, a `notAfter`; when present, the configuration is not used outside them. | the settings format (`c2patool`'s) has no such dates, so none is ever present. An anchor's own validity is judged (SPEC-014 amendment 5) | n/a | read |
| 14.4.1 | For `c2pa-kp-claimSigning` the anchors include the C2PA Trust List. | the library bundles no list and fetches none (`docs/trust-settings.md`); the caller's settings hold the anchors. The OID is accepted (`CertificateProfileCheck::BUILT_IN_EKUS`). The WordPress plugin bundles the list | by design (a library; its users bundle the list) | read |
| 14.4.1 | A validator should let the user add anchors, for that EKU and others. | settings files, `trust_config` | covered | measured: SPEC-014, SPEC-031 tests |
| 14.4.2 | TSA anchors are a list of their own, separate from the signers'. | `trust_kind: "tsa"` entries count only for timestamps (SPEC-031); a `"manifest"` anchor does not vouch for a TSA. The legacy single `trust_anchors` string serves both, as in `c2patool` | covered; partial for the legacy string | measured: `TrustAnchorsTest` AC6; SPEC-062 probes `tsa-root-as-manifest` and `legacy-string-both` |
| 14.4.2 | The TSA anchors include the C2PA TSA Trust List; users should be able to add more. | as for 14.4.1 | by design (a library) | read |
| 14.4.3 | A private credential store applies to claim signatures only, never to timestamps. | the `allowed_list`: refused in a non-`"manifest"` entry (`TrustAnchorSet`); empty in the TSA's settings (`TimestampCheck::tsaSettings()`) | covered | read; `TrustAnchorsTest` AC3, AC4 for its place |
| 14.4.3 | Its entries are trusted only as signers; they issue nothing and are not anchors. | `ChainCheck::checkCertificates()` compares the leaf with the list; `anchorsOf()` never includes it | covered | measured: `ChainCheckTest` AC3; the issuing side read |
| 14.4.3 | Not pre-configured; entries added and removed only at the user's request. | the library holds no store of its own; it reads the caller's settings | covered by construction | read |

### Candidates

- **C1 — two credentials (§14.2).** Two cases are not refused: label 33 and
  `"x5chain"` together in the protected header, and label 33 in both
  headers. The code says this matches `c2pa-rs`'s `cert_chain_from_sign1`
  (read, not measured). Next: two probes, judged by both `c2patool`
  versions, then an amendment to SPEC-047 if Maurice agrees.
- **C2 — the state rule (§14.3.5).** `Valid` is decided by the absence of
  failures, not by the presence of `claimSignature.validated` and
  `claimSignature.insideValidity`. Measured: no report differs today. A
  positive check would guard a future path that skips a step without a
  failure code. It would be defence in depth, not a fix.
- **C3 — section numbers.** 22 places in `src/`, `specs/`, `docs/` and
  `bin/` cite "C2PA 2.4 §14.6" or "§14.6.1" for timestamps; 2.4 has no
  §14.6. In 2.4 the CounterSignature and `sigTst2` are in §10.3.2.5 and
  §15.8. `docs/conformance.md` names the anchors-per-EKU rule §14.5.1.2,
  but it is §14.4.1. No behaviour depends on it; a reader following the
  reference finds nothing.
