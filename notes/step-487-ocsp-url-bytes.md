# Step 487 — Responder urls: visible ASCII only

*2026-10-28.* Finding 7 of the review of step 482, fixed before 0.9.0 is
tagged: SPEC-086 amendment 4, approved by Maurice. No verdict, status or
contract symbol changes.

## Why

`CertificateExtensions::ocspUrls()` gives the revocation source (SPEC-086)
the OCSP responder urls of a certificate's AIA. It checked the scheme
(`http` or `https`) and the length only. A `uniformResourceIdentifier` is
an IA5String, which holds any ASCII byte — CR, LF, NUL, space, tab — and
the certificate comes from whoever made the file. A caller that builds
its own HTTP request from the url, as one might on shared hosting with
`fsockopen`, could be made to send a header or a second request. RFC 3986
§2 allows none of these bytes in a URI.

## What changed

- A url is kept only when every byte is visible ASCII, 0x21 to 0x7E
  (`preg_match('#^https?://[\x21-\x7e]*$#iD')`); others are dropped, as an
  `ldap` url is. The limit of four counts the kept ones, as before.
- `RevocationSource`'s docblock and SPEC-086's *urls* line say so.
- `bin/make-aia-certificates.php` takes optional probe names, so that the
  new certificate is built without rebuilding the committed ones, and
  writes `tests/Fixtures/aia/control.crt`: OCSP urls with CR LF, a final
  LF, NUL, a space, a tab, DEL and a non-ASCII byte, then one plain url.
  openssl's configuration refuses those bytes in a URI, so the AIA is
  written as DER. Its key lived only in memory.

## Measured

- AC8 gains the dataset "control bytes": before the fix the first four
  of the eight urls were passed (the four-url limit hid the rest); after,
  only `http://ocsp-plain.example/`.
- Mutations (`RevocationSourceTest`): seven, all caught — no end anchor,
  space allowed, DEL allowed, no `D` modifier, the old check, tab
  allowed, high bytes allowed. The `D` mutation survived at first: no url
  ended in a lone LF. The final-LF url was added for it.
- Corpus: the OCSP urls of all 414 distinct certificates in the fixtures'
  `x5chain`s (17 carry urls), at `46ec540` (worktree with a copied
  `vendor/`) and now: identical. The urls reach only a caller's source,
  so without one no report can change (SPEC-086 AC9).
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.

## Read, not measured

`c2pa-rs` 0.91.1 passes the url to `url::Url::parse`
(`crypto/ocsp/fetch.rs`), whose WHATWG parsing strips tab, CR and LF and
uses the rest. This verifier drops the url: stricter, on a path that
changes no verdict (ADR-0005). A row in `docs/comparison.md`.
