# ADR-0001: Dependencies — what is written here and what is taken from Packagist

| Field    | Value                          |
|----------|--------------------------------|
| Status   | accepted, amended 2026-09-21    |
| Date     | 2026-09-19                     |
| Decided  | Maurice van Loon               |

## Context

A verifier for C2PA needs four things that already exist on Packagist in
some form: a JUMBF box reader (ISO 19566-5), a CBOR decoder (RFC 8949),
COSE_Sign1 verification (RFC 9052), and ASN.1/X.509 handling for the chain
and the RFC 3161 timestamp. Measured on 2026-09-19:

| layer | package | downloads | note |
|---|---|---|---|
| JUMBF | `belisoful/php-image` | 3 (Aug 2026) | a box model for JPEG APP11; young |
| CBOR | `spomky-labs/cbor-php` | 23M | full RFC 8949 |
| COSE | `web-auth/cose-lib` | 22M | ES256/PS256/EdDSA verification, in production for WebAuthn logins |
| ASN.1, X.509 | `phpseclib/phpseclib` | — | the standard PHP implementation |

Three forces pull in different directions:

1. **Shared hosting.** The point of this project is hosts where nothing can
   be installed. Every package is one more thing that must arrive with the
   verifier and stay compatible.
2. **Understanding.** The maintainer wants to know every layer of the
   verifier well enough to explain it. What is written here is understood;
   what is imported is trusted.
3. **A wrong `Valid`.** The single risk that counts. COSE detail mistakes —
   the `Sig_structure` encoding, converting an `R||S` signature to DER — are
   exactly the mistakes that produce a wrong `Valid` and pass every happy-path
   test. `cose-lib` has been exercised on millions of logins; new code has
   not.

## Decision

Per layer:

- **JUMBF: written here.** No mature package exists, the box model is
  small, and it is the first milestone — measurable against `c2patool` with
  a hash and no cryptography.
- **CBOR: written here**, as the subset C2PA needs (major types 0–7,
  definite lengths) with hard limits on depth and length. The learning value
  is high, the surface is small, and the fail-closed rule (unknown tag →
  error) is easier to guarantee in code that accepts only what it
  understands than in a library that accepts everything.
- **COSE_Sign1: written here, on `ext-openssl`** (amendment 1, 2026-09-21;
  the original decision, "`web-auth/cose-lib` first", is kept below for
  the record). The `Sig_structure`, the R‖S → DER conversion and the two
  PSS paths are measured against four real signatures and three broken
  ones before any spec names them (`notes/step-16-cose-signature.md`);
  `cose-lib`'s ECDSA and PSS code is reference reading, never copied
  without saying so.
- **ASN.1 / X.509 / RFC 3161: open until M5.** Probably `phpseclib`, but
  deciding now would be an unmeasured claim. *(Closed since: X.509 on
  `ext-openssl` in [ADR-0003](ADR-0003-x509-on-ext-openssl.md), and RFC
  3161 on a DER reader written here in
  [ADR-0004](ADR-0004-rfc3161-on-an-own-der-reader.md). The package list
  is still `php`, `ext-openssl`, `ext-mbstring` and nothing else.)*

Consequences for `composer.json` at M0: `require` holds `php`, `ext-openssl`
and `ext-mbstring` and no packages. `ext-sodium` is a suggestion (Ed25519,
opt-in). Development tools (Pint, PHPStan, Deptrac, Pest) are `require-dev`
and never reach a host.

The rule from the sister project holds here: no new runtime dependency
without a spec and an ADR.

## Alternatives rejected

- **Everything written here.** Highest learning value, zero packages, and
  the highest chance of a wrong `Valid` in exactly the layer where a mistake
  is invisible. Rejected for COSE; kept as a goal, not a starting point.
- **Everything from Packagist.** Fastest to a working verifier, and a
  verifier whose core the maintainer does not know. It would also bring
  `cbor-php`, `cose-lib` and its transitive dependencies (`pki-framework`,
  `brick/math`, …) to hosts that wanted as little as possible. Rejected.

## Consequences

- `cose-lib` will bring its own dependencies in M3. Accepted as a starting
  point; the cost is written into that milestone's note.
- `ext-mbstring` is required because the brief this project started from
  lists it, but no call site is known yet. Byte parsing uses `strlen` and
  `substr`. If M2 ends without a single `mb_*` call, a spec removes it.
- The own CBOR decoder must be measured against `cbor-php` on the same
  inputs before it is trusted — a second implementation is the oracle.

## Amendments

### Amendment 1 — 2026-09-21: COSE_Sign1 written here, not `cose-lib`

Decided by Maurice van Loon after step 16 (`notes/step-16-cose-signature.md`),
which was the falsification attempt the original decision asked for.

**Original decision:** COSE_Sign1 verification through `web-auth/cose-lib`
first, replaced by own code only when demonstrably equal on the same
vectors. Reason: COSE detail mistakes — the `Sig_structure`, R‖S → DER —
produce a wrong `Valid` and pass every happy-path test; the library had
been exercised on millions of logins.

**What was measured (2026-09-21, cose-lib 4.8.2 in a scratch directory):**

- For ES256 the library's `verify()` is the same `openssl_verify` call as
  a hand-built verifier, after the same R‖S → DER conversion.
- For PS256 the library cannot load the one PS256 certificate we have
  (`adobe-20220124-C.jpg`, an `rsassaPss`-typed key): "Unable to read the
  certificate". `ext-openssl` verifies that signature.
- It brings `spomky-labs/pki-framework` and `brick/math`: three packages,
  2.5 MB, to hosts that want none.
- The details the original decision feared were measured by hand: the
  `Sig_structure` (1,895 bytes for the PNG) verifies all four fixtures;
  one flipped claim byte, one flipped signature bit, a cross-fixture claim
  and a wrong `alg` all fail; a plain-RSA PSS signature made by OpenSSL
  verifies through a forty-line EMSA-PSS-VERIFY and not through
  `openssl_verify`, and a PKCS#1 v1.5 signature the reverse.

**Amended decision:** COSE_Sign1 verification is written here on
`ext-openssl` (and `ext-sodium`, opt-in, for Ed25519). Every algorithm
path gets a positive vector, a flipped-byte vector and a key-does-not-fit
vector in its spec before it is built. `cose-lib` (MIT) is reference
reading for the ECDSA and PSS code; any borrowed idea is named in the
spec. The rule "no new runtime dependency without a spec and an ADR"
stands; `require` still holds no packages.

**Two consequences of the original decision, closed by the same
measurement:** the own CBOR decoder was measured against `cbor-php` on the
sixteen real blobs (steps 12–13) and reproduces them; and `ext-mbstring`
now has call sites — `mb_check_encoding` in the JUMBF label check and the
CBOR text-string check — so it stays.

