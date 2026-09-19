# ADR-0001: Dependencies — what is written here and what is taken from Packagist

| Field    | Value                          |
|----------|--------------------------------|
| Status   | accepted                       |
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
- **COSE_Sign1: `web-auth/cose-lib` first.** Added only when milestone M3's
  spec introduces it, as that spec's decision. It may be replaced by own code
  later, but only when that code is demonstrably equal on the same test
  vectors — never before.
- **ASN.1 / X.509 / RFC 3161: open until M5.** Probably `phpseclib`, but
  deciding now would be an unmeasured claim.

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
