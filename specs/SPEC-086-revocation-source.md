# SPEC-086: Revocation data from a source the caller injects — the signer

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-27                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The verifier judges a signer's revocation only by the OCSP responses a file
carries: stapled in the claim signature's `rVals` (SPEC-030) or held in
`c2pa.certificate-status` assertions (SPEC-066). Most files carry none, and
then the answer is `signingCredential.ocsp.skipped`, always. A certificate
revoked after signing, or a signer whose key was compromised, stays
`Trusted`.

C2PA 2.4 §15.9.2 lets a validator ask the responder online when no stored
response proves anything, and defines `signingCredential.ocsp.inaccessible`
for a query that gets no answer. ADR-0007 (accepted 2026-10-27) allows this
here through a source the caller injects: the package itself opens no
connection, and with the default source nothing changes.

This is the first spec under ADR-0007: the signer's certificate. The CAs of
the path through their AIA responders may follow in a second.

Measured on 2026-10-27 (the verifier at `c0eaf42`, `c2patool` 0.28.3; see
ADR-0007 and `notes/step-471-adr-0007-proposed.md`):

- `c2patool` asks only with `verify.ocsp_fetch` on (default off). With it
  on, `writers/google-20250919-pixel10-npld-picnic-table.jpg` gets a live
  `signingCredential.ocsp.notRevoked`; `writers/microsoft-20260609-bing-fast-heartbeat.jpg`
  gets `signingCredential.ocsp.inaccessible`.
- Here, with the programme's trust lists, the Google file is `Trusted` and
  `signingCredential.ocsp.skipped`; its signer's certificate names one OCSP
  responder in its AIA extension, `http://c2pa-ocsp.pki.goog/`.
- `revocation/cs-parent.png` under `revocation/throw-away-root.settings.json`
  is `Trusted`, carries no response, and is `skipped` for its signer P.
  Responses about P already exist in the corpus: `good` (SHA-256
  `427b01dc…`) in `revocation/cs-good.png`, `revoked` (`d39d48fa…`) in
  `revocation/cs-revoked.png`, both current from 2026-10-09T14:26:59Z to
  2036-10-06T14:26:59Z; and one about signer U, `revoked` (`3490ee03…`), in
  `revocation/cs-own.png`.
- In `revocation/cs-good.png` the ingredient (P) has a stored response that
  proves `notRevoked`; the active manifest (U) has only a response about
  another certificate, so nothing stored proves anything for U. In
  `revocation/cs-own.png` it is the other way round.

## Scope

**In scope**

- A new contract interface `Trust\RevocationSource` with one method,
  `ocspResponse(string $subject, string $issuer, array $urls): ?string`:
  the DER of the signer's certificate and of its issuer, and the OCSP
  responder urls of the signer's certificate; it returns the DER of an OCSP
  response, or null.
- A new contract class `Trust\NoRevocationSource`, which returns null and
  is never called by the verifier: with it (the default) no status, report
  or evidence changes.
- A new last parameter of the `Verifier` constructor,
  `RevocationSource $revocationSource = new NoRevocationSource`: no source
  is given by giving none (amendment 5; it read
  `?RevocationSource $revocationSource = null`, null meaning the default).
- **When the source is asked:** for each judged manifest (active and
  ingredient alike, as `OcspCheck` runs for both), exactly once, only when
  - its signer's chain reaches an anchor (the manifest's
    `signingCredential.trusted`), and
  - the check over the stored responses gave `signingCredential.ocsp.skipped`
    (no stored response proves anything, C2PA 2.4 §15.9.2), and
  - the signer's issuer is in `x5chain`, so a response can be matched.
- **The urls:** the `id-ad-ocsp` access locations (RFC 5280 §4.2.2.1) of
  the signer's certificate's AIA extension, in order, kept only when they
  are `http` or `https` URIs of at most 2,048 bytes, every byte visible
  ASCII (0x21–0x7E, amendment 4), at most four. An AIA
  that cannot be read gives an empty list; the source is still asked.
- **What becomes of the answer:**
  - a response that passes the same reading as a stored one (`OcspCheck`:
    about the signer, verified under a responder tied to its issuer, at
    most the size limit) is judged exactly as a stored one at the same
    time — `notRevoked`, `revoked`, `unknown`, or `skipped` when it is not
    current — and the status's explanation says the response was fetched
    from the caller's source;
  - null, an exception or error thrown by the source, or a response that
    does not pass the reading: `signingCredential.ocsp.inaccessible`
    (informational), with the reason, in place of `skipped`.
- A new `StatusCode::SigningCredentialOcspInaccessible`
  (`signingCredential.ocsp.inaccessible`), informational.
- The evidence (SPEC-085): a third source, `OcspEvidence::FETCHED`
  (`fetched`), with the signature's url and the bytes the source returned,
  appended after the stored responses, outcome `chosen` when it was judged
  and `unusable` when it did not pass the reading. Null or a throw adds no
  entry.
- The contract (SPEC-025) grows by one interface, one class, one enum
  case, one constant and one constructor parameter, in the same minor as
  SPEC-081 to SPEC-085.
- With the build, the lines of SPEC-014's and SPEC-030's *Out of scope*
  that rule out an online query get an amendment each, and the README's
  design rule and `docs/comparison.md` say what ADR-0007 decided.

**Out of scope** (each needs its own spec before it may be built)

- The CAs of the path through their own AIA responders (`c2pa-rs` asks
  those first; a revoked CA makes the signer untrusted): a second spec.
- An issuer completed from the trust anchors when `x5chain` holds the
  signer alone (`c2pa-rs` does): the source is not asked.
- CRLs, AIA `caIssuers`, any HTTP code, caching, retries, time-outs: the
  source's business.
- A source for an untrusted signer. `c2pa-rs` with fetching on asks there
  too (the Bing file: `inaccessible`); here it stays `skipped`, a
  difference in the report only, recorded in `docs/comparison.md`.
- `c2pa-rs`'s second time-stamp validation on its fetch path (ADR-0007,
  decided question 1): not copied.
- The source in `toArray()`, `toJson()`, `toCrJson()` or the CLI.

## Behavior

The test source records each call and returns a fixed answer.

- **AC1 — a fetched `good`**
  - Given `revocation/cs-parent.png` with
    `revocation/throw-away-root.settings.json` and a source returning the
    `good` response about P (`427b01dc…`, taken from `revocation/cs-good.png`)
  - Then the source is called once, with P's certificate, its issuer's and
    no urls; the signature's status is `signingCredential.ocsp.notRevoked`
    and its explanation says fetched; the verdict is `Trusted`; the
    evidence holds one entry, `fetched`, `chosen`, `good`.

- **AC2 — a fetched `revoked`**
  - Given the same file and settings and a source returning the `revoked`
    response about P (`d39d48fa…`, from `revocation/cs-revoked.png`)
  - Then `signingCredential.ocsp.revoked` and the verdict `Invalid`; the
    evidence entry is `fetched`, `chosen`, `revoked`.

- **AC3 — an answer about another certificate** *(required: error / malformed input)*
  - Given the same file and settings and a source returning the response
    about U (`3490ee03…`, from `revocation/cs-own.png`)
  - Then `signingCredential.ocsp.inaccessible` with the reason, no
    `skipped`; the verdict `Trusted`; the evidence entry `fetched`,
    `unusable`.

- **AC4 — no answer** *(required: error / malformed input)*
  - Given the same file and settings, and a source that returns null; one
    that throws an exception; one that throws an `Error`; one that returns
    bytes that are not DER
  - Then each time `signingCredential.ocsp.inaccessible` with the reason,
    the verdict `Trusted`, and nothing escapes `verify()`; the evidence is
    empty for null and the throws, and one `fetched` `unusable` entry for
    the bytes.

- **AC5 — not asked where a stored response proves something**
  - Given `revocation/cs-good.png` with the throw-away settings and a source
    returning null
  - Then the source is called once, for U (the active manifest), not for P,
    whose stored `notRevoked` stands.
  - And given `revocation/cs-own.png`: called once, for P (the ingredient),
    not for U, whose stored `revoked` stands.

- **AC6 — not asked for an untrusted signer**
  - Given `revocation/cs-parent.png` with no settings, and
    `writers/microsoft-20260609-bing-fast-heartbeat.jpg` with the
    programme's lists, each with a source returning `good` about P
  - Then the source is never called, and the statuses equal those without a
    source.

- **AC7 — the urls from the AIA**
  - Given `writers/google-20250919-pixel10-npld-picnic-table.jpg` with the
    programme's lists and a source returning null
  - Then the source is called once, with the urls
    `['http://c2pa-ocsp.pki.goog/']`, and the status is
    `signingCredential.ocsp.inaccessible`.

- **AC8 — urls that are not kept** *(required: error / malformed input)*
  - Given signer certificates (public test certificates, keys deleted at
    build time) whose AIA holds an `ldap` URI, a `caIssuers` http URI, five
    OCSP http URIs, an OCSP URI over 2,048 bytes, and an AIA that is not
    valid DER
  - Then the urls passed are only the OCSP `http`/`https` ones, at most
    four, and an empty list for the broken AIA.

- **AC9 — the default changes nothing**
  - Given every corpus file under no settings, `trust/full.settings.json`
    and the programme's lists, with no source and with
    `new NoRevocationSource`
  - Then `toJson()` and `toCrJson()` are byte-identical to this verifier
    before this spec, in every run.

- **AC10 — asked exactly where allowed**
  - Given the same runs with a source that records its calls and returns
    null
  - Then each call belongs to a judged manifest whose signer is trusted and
    whose stored responses gave `skipped`; each such manifest with its
    issuer in `x5chain` is called exactly once; and every
    `signingCredential.ocsp.inaccessible` status belongs to such a call.

- **AC11 — the contract**
  - `Trust\RevocationSource`, `Trust\NoRevocationSource`,
    `StatusCode::SigningCredentialOcspInaccessible`,
    `OcspEvidence::FETCHED` and the new constructor parameter are in
    `public-surface.txt`; the surface test fails without them.

## References

- Specification: C2PA 2.4 §15.9.2 (an online query, its rules,
  `signingCredential.ocsp.inaccessible`); RFC 6960 §3.2 (accepting a
  response), RFC 5280 §4.2.2.1 (authority information access).
- Decision: ADR-0007 (accepted 2026-10-27), ADR-0005 (and the exception
  ADR-0007 makes beside it).
- Measured: the statuses, hashes, times and the Google AIA url above, over
  the verifier at `c0eaf42`; `c2patool` 0.28.3 with `verify.ocsp_fetch`
  (step 471).
- Read: `c2pa` 0.91.1 `crypto/cose/ocsp.rs` (`check_ocsp_status`,
  `fetch_and_check_ocsp_response`) for the order and the
  `inaccessible` code.
- Oracle: `c2patool` with `verify.ocsp_fetch` for the codes, not for the
  verdict where ADR-0007 departs from it; recorded responses for the tests.

## API sketch

```php
namespace Provemark\C2paVerifier\Trust;

interface RevocationSource
{
    /**
     * An OCSP response about $subject, or null.
     *
     * @param  string  $subject  DER of the signer's certificate
     * @param  string  $issuer  DER of its issuer
     * @param  list<string>  $urls  the OCSP responder urls of $subject's AIA (http/https, visible ASCII only, at most four)
     * @return string|null  DER of an OCSP response; untrusted, read like any stored one
     */
    public function ocspResponse(string $subject, string $issuer, array $urls): ?string;
}

final readonly class NoRevocationSource implements RevocationSource
{
    public function ocspResponse(string $subject, string $issuer, array $urls): ?string
    {
        return null;
    }
}

// OcspEvidence gains: public const FETCHED = 'fetched';
// StatusCode gains:   case SigningCredentialOcspInaccessible = 'signingCredential.ocsp.inaccessible';
// Verifier::__construct gains, last: private RevocationSource $revocationSource = new NoRevocationSource (amendment 5)
```

## Open questions

None.

## Amendments

1. **2026-10-27, step 478, with SPEC-087** *(approved by Maurice van Loon, 2026-10-27)* —
   SPEC-087 lets the source be asked about the CAs of the path too, each
   before the signer. AC1, AC4, AC5, AC7 and AC10 count the calls the
   source receives ("called once", "each call belongs to …"), written when
   only the signer was asked. They now count **the calls about a judged
   manifest's signer**; the calls about the CAs are SPEC-087's (its AC2,
   AC4, AC10). Nothing else changes: when the signer is asked, with what,
   and what becomes of the answer. The tests filter the recorded calls to
   those whose subject is some judged manifest's signer
   (`spec086SignerCalls()`).

2. **2026-10-27, step 483, found by the review of step 482; measured** *(approved by Maurice van Loon, 2026-10-27, option b)* —
   The source was told that `x5chain[1]` issued the signer, whatever that
   certificate was, and was not asked when `x5chain` held the signer
   alone. The chain walk (SPEC-016) does not need `x5chain[1]` when an
   anchor issued the signer itself, so a signer issued directly by an
   anchor escaped the source by leaving `x5chain[1]` out or putting an
   unrelated certificate there: the source got a question it cannot
   answer about the right certificate, or none, and a revoked signer
   stayed `Trusted`. Measured with
   `bin/make-revocation-issuer-variants.php`: a signer L issued by the
   anchor R, with `x5chain` [L], [L, B] (unrelated), [L, N] (R's subject,
   another key) and [L, R]; a source that answers L revoked turned only
   [L, R] `Invalid`.

   The source is now told **the issuer the chain walk verified**: the
   certificate above the signer in the path that reached the anchor — the
   anchor itself when it issued the signer. Where the walk vouches for the
   signer without a certificate above it (the signer on the allowed list,
   or itself an anchor), **option b**: `x5chain[1]` is used only when it
   demonstrably issued the signer — its subject is the signer's issuer and
   its key verifies the signer's signature — and otherwise the source is
   not asked, as when the issuer was not in `x5chain` before.
   `OcspCheck::checkWithEvidence()` takes the issuer as a new last
   parameter (null: not asked); `ChainCheck::checkWithAnchor()` and
   `walk()` return it as a third element. No contract symbol is added.
   `c2patool` 0.28.3 calls all the probes `Trusted`: it asks no source.
   Tests: `tests/Unit/Verifier/RevocationIssuerTest.php`, group
   `SPEC-086`. The *Scope* test "the source is not asked when the issuer
   is not in x5chain" now reads "… without an issuer the walk verified".

   **Weight A: a revoked signer wrongly `Trusted` under a source becomes `Invalid`.**

3. **2026-10-27, step 484, found by the review of step 482; measured** *(approved by Maurice van Loon, 2026-10-27)* —
   A stored response that answers `unknown` about the signer gave
   `signingCredential.ocsp.unknown`, and the source was asked only after a
   `skipped`. The `rVals` header is not signed, so anyone could staple an
   `unknown` and keep a source from being asked: a revoked signer stayed
   `Trusted`. C2PA 2.4 §15.9.2 lets a validator query online when the
   stored information does not establish the status, and `unknown` does
   not; `c2pa-rs` 0.91.1 fetches when a stapled response is neither revoked
   nor not-revoked (read: `crypto/cose/ocsp.rs`, `check_ocsp_status()`).

   After a stored `unknown` the source is now asked as well, with the
   issuer the walk verified (amendment 2). A **decisive** answer counts: a
   `revoked` at the judged time, or a current `good`; the stored response
   becomes `notChosen` in the evidence and loses its code. **Anything
   else** — no answer, a failing source, an answer that cannot be used, a
   stale `good`, a revocation that does not count, another `unknown` —
   leaves `signingCredential.ocsp.unknown`, its explanation ending with
   what the source gave; a usable fetched response is then `notChosen`.
   One `signingCredential.ocsp.*` status per manifest, as before.

   Measured with `bin/make-revocation-unknown-variants.php`: signer L
   under root R, `x5chain` [L, R], L's `unknown` stapled. The first file
   to reach the `unknown` branch at all: no test or fixture did before.
   `c2patool` 0.28.3 calls it `Trusted` and reports no OCSP code. Tests:
   `tests/Unit/Verifier/RevocationUnknownTest.php`, group `SPEC-086`.

   **Weight A: a revoked signer wrongly `Trusted` under a source becomes `Invalid`.**

4. **2026-10-28, step 487, found by the review of step 482; measured** *(approved by Maurice van Loon, 2026-10-28)* —
   The urls passed to the source were checked for their scheme and their
   length only. A `uniformResourceIdentifier` is an IA5String, which
   holds any ASCII byte, CR, LF, NUL, space and tab included, and the
   certificate comes from whoever made the file. A caller that builds its
   own HTTP request from such a url (on shared hosting, say with
   `fsockopen`) could be made to send a header or a second request it
   never meant to. RFC 3986 §2 allows none of these bytes in a URI, so no
   such url is a valid one.

   A url is now kept only when **every byte is visible ASCII, 0x21 to
   0x7E**; any other is dropped, as an `ldap` url is. The limit of four
   counts the kept ones, as before. AC8 gains a certificate whose AIA
   holds OCSP urls with CR LF, a final LF, NUL, a space, a tab, DEL and a
   non-ASCII byte, then one plain url: only the plain one is passed.

   Measured with `bin/make-aia-certificates.php control` (the AIA written
   as DER, since openssl's configuration refuses those bytes in a URI):
   before, the first four of the eight were passed. Read, not measured: `c2pa-rs`
   0.91.1 passes the url to `url::Url::parse` (`crypto/ocsp/fetch.rs`),
   whose WHATWG parsing strips tab, CR and LF and uses the rest; this
   verifier drops the url instead, stricter, on a path that changes no
   verdict (ADR-0005).

   **Weight B: protects the caller's source; no verdict changes.**

5. **2026-10-28, step 488, found by the review of step 482** *(approved by Maurice van Loon, 2026-10-28)* —
   "No source" had two spellings in the contract: no argument or `null`,
   and `new NoRevocationSource`. A caller had to know that both mean the
   same, and once 0.9.0 is tagged the contract could not be narrowed to
   one without a breaking change. No caller uses the parameter yet.

   The parameter is now **`RevocationSource $revocationSource = new
   NoRevocationSource`**, not nullable: giving no argument works as
   before, passing `null` is a `TypeError`. Inside the verifier null still
   means "not asked"; that is not part of the contract. What the review
   also noted stays, on purpose: a source of the caller's that answers
   null was asked and gives `signingCredential.ocsp.inaccessible` (AC4),
   where `NoRevocationSource` is never asked and changes nothing (AC9).
   `RevocationSource`'s docblock says both. The contract keeps its 209
   symbols: the recorded surface holds names, not types. Tests:
   `RevocationSourceTest.php`, group `SPEC-086`.

   **Weight C: one spelling for "no source"; no verdict changes.**

## Traceability

All in `tests/Unit/Verifier/RevocationSourceTest.php`, group `SPEC-086`. Seen red before the implementation (all
21 tests: the missing interface, the unknown constructor parameter and the
missing `ocspUrls()`), and the surface test on the four new symbols. Three
tests for *Scope* lines no corpus file shows were added after the build,
when mutations M5, M8 and M16 survived; each now fails under its mutation.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | AC1: a fetched good | src/Trust/OcspCheck.php :: checkWithEvidence(), fetched(), statusOf(); src/Verifier/Verifier.php :: check(), source() |
| AC2                  | AC2: a fetched revoked | src/Trust/OcspCheck.php :: fetched(), statusOf() |
| AC3                  | AC3: an answer about another certificate is inaccessible | src/Trust/OcspCheck.php :: fetched(); src/Report/StatusCode.php :: SigningCredentialOcspInaccessible |
| AC4                  | AC4: no answer is inaccessible, and nothing escapes (null, an exception, an error, not DER) | src/Trust/OcspCheck.php :: fetched() |
| AC5                  | AC5: not asked where a stored response proves something (cs-good, cs-own) | src/Trust/OcspCheck.php :: checkWithEvidence(); src/Verifier/IngredientManifestCheck.php :: check(), manifest() |
| AC6                  | AC6: not asked for an untrusted signer | src/Verifier/IngredientManifestCheck.php :: trusted(); src/Verifier/Verifier.php :: check() |
| AC7                  | AC7: the urls from the AIA | src/Trust/CertificateExtensions.php :: ocspUrls(); src/Trust/OcspCheck.php :: fetched() |
| AC8                  | AC8: urls that are not kept; fixtures from `bin/make-aia-certificates.php` | src/Trust/CertificateExtensions.php :: ocspUrls() |
| AC9                  | AC9: the default source changes nothing; and 3,066 corpus runs before (worktree at `738e1dd`, own `vendor/` copy) and after, with no source and with `NoRevocationSource`, identical (`notes/step-475-revocation-source.md`) | src/Trust/NoRevocationSource.php; src/Verifier/Verifier.php :: source() |
| AC10                 | AC10: over the corpus, the source is asked exactly where allowed (none, full, lists) | src/Verifier/Verifier.php :: check(); src/Verifier/IngredientManifestCheck.php :: manifest() |
| AC11                 | tests/Unit/ApiSurfaceTest.php :: AC1 / SPEC-025 | src/Trust/RevocationSource.php; src/Trust/NoRevocationSource.php; src/Trust/OcspEvidence.php :: FETCHED; bin/api-check.php |
| *Scope*              | scope: a fetched response follows the stored ones in the evidence; scope: the source is not asked without an issuer the walk verified; scope: a fetched response over the size limit is not read | src/Trust/OcspCheck.php :: checkWithEvidence(), fetched() |
| Amendment 5          | amendment 5: no source has one spelling; amendment 5: a source of the caller's that answers null was asked / SPEC-086 | src/Verifier/Verifier.php :: __construct(), source(); src/Trust/RevocationSource.php |
| Amendment 4          | AC8: urls that are not kept (control bytes) / SPEC-086 | src/Trust/CertificateExtensions.php :: ocspUrls(); bin/make-aia-certificates.php |
| Amendment 3          | tests/Unit/Verifier/RevocationUnknownTest.php / SPEC-086 (6 of 7 tests red before the build; the guard without a source passed) | src/Trust/OcspCheck.php :: checkWithEvidence(); bin/make-revocation-unknown-variants.php |
| Amendment 2          | tests/Unit/Verifier/RevocationIssuerTest.php :: the signer is asked about with the anchor that issued it, whatever x5chain carries second; option b: an allowed-list signer …; option b: a signer that is itself an anchor … / SPEC-086 (11 of 16 tests of the file red before the build) | src/Trust/ChainCheck.php :: walk(), provenIssuer(); src/Trust/OcspCheck.php :: checkWithEvidence(); src/Verifier/Verifier.php :: check(); src/Verifier/IngredientManifestCheck.php :: manifest() |
