# ADR-0002: Name, namespace, organisation, licence

| Field    | Value                          |
|----------|--------------------------------|
| Status   | accepted                       |
| Date     | 2026-09-19                     |
| Decided  | Maurice van Loon               |

## Context

The package needs a Composer name, a PHP namespace, a home organisation and
a licence before the first source file exists; renaming later touches every
file. It sits next to `provemark/content-credentials` (MIT, namespace
`Provemark\ContentCredentials`), which signs and reads through a service or
a native extension. This project is a third reader for hosts those two
cannot reach, and it must not depend on the library.

## Decision

- **Package:** `provemark/c2pa-verifier`. "Verifier" says what it is and
  what it is not (no signer), and it is the word the WordPress core and
  AI-plugin discussions use for the missing piece.
- **Namespace:** `Provemark\C2paVerifier`. Follows the package name; `C2pa`
  spelled as `c2pa-rs` and `ext-c2pa` spell it.
- **Organisation:** `provemark`, next to the sister library, one brand.
- **Licence:** MIT, the same as the sister library. Maximises adoption in
  the WordPress and Drupal ecosystems, and keeps one boundary sharp: the
  only earlier pure-PHP COSE/CBOR attempt (WordPress/ai PR #294, Encypher)
  is GPL-2.0 — it may be read to learn from, never copied.
- **Visibility:** private on GitHub until the maintainer decides otherwise;
  no Packagist registration while private.
- **Report contract:** the verifier ships its own report object using
  `c2patool`'s vocabulary only (`validation_state`, C2PA 2.4 §15 status
  codes). The sister library may add an adapter that maps it onto its
  `ManifestReport` and runs its reader-equivalence test against it. The
  dependency direction is library → verifier, never the reverse, so this
  package stays dependency-free and a plugin can require it directly.

## Alternatives rejected

- `provemark/content-credentials-verifier`: long, and suggests a dependency
  on the library that this ADR rules out.
- `c2pa-php` or similar: claims more than a verifier.
- Reusing the library's `ManifestReport` as the contract: would pull the
  whole library, its PSR-18 client and its Laravel integration onto hosts
  that wanted nothing.

## Consequences

- `composer.json`: `provemark/c2pa-verifier`, MIT, PSR-4
  `Provemark\C2paVerifier\` → `src/`.
- Interpretations that belong to the library (for example "is this AI
  generated?", a reading of `digitalSourceType`) stay there. If the verifier
  ever answers such a question stand-alone, it gets its own spec with the
  same definition.
- The disclosure of how the project is built lives in `README.md` and
  `AI-LOG.md`, not in commit metadata. That is a separate decision of the
  maintainer, recorded in the log's first entry.
