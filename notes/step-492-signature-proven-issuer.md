# Step 492 — A good from an issuer only its signature proves

*2026-10-28.* Finding S1 of the review of steps 490–491 (a third review,
of the fix for R1, before tagging 0.9.0): SPEC-030 amendment 6, approved
by Maurice. No verdict and no contract symbol changes.

## Why

Step 490 reads a trusted signer's stored OCSP responses against the
issuer the chain walk verified. For a signer on the allowed list, or
itself an anchor, the walk has no certificate above the signer, and the
issuer is `x5chain[1]` when its subject is the signer's issuer and its
key verifies the signer's signature (SPEC-086 amendment 2, option b).
That proves the certificate by a signature, not by an anchor. For an RSA
PKCS#1 v1.5 signature, a key other than the issuer's that verifies one
given signature can be constructed (duplicate-signature key selection),
so an author could carry such a certificate and staple a `good` signed
with it: `signingCredential.ocsp.notRevoked`, and no source asked.

The verdict cannot be made right: the source is told the same issuer, and
a genuine answer does not verify against a constructed key. The report
can: a `good` that proves nothing must not read as one that does.

## What changed

- `OcspCheck::checkWithEvidence()` takes a last parameter `$anchored`.
  For a trusted signer whose issuer no anchor binds, a stored response
  counts only when it says `revoked`; a `good` or `unknown` is
  `notChosen`, the status `skipped` ("proven only by its signature on the
  signer, not by a trust anchor"), and the source is asked.
- `IngredientManifestCheck::anchored()` (internal): the anchor evidence's
  depth is one or more. `Verifier` and the ingredient check pass it.
- SPEC-086 amendment 2 records the limit of option b.

## Measured

- The construction itself was not built; the rule is about the route
  that proved the issuer, and the probes use the genuine R by that route.
- `bin/make-revocation-stored-issuer-variants.php` gains
  `leaf-root-revoked.png` ([L, R], R's revoked) and `child-root-good.png`
  (M's manifest with `leaf-root-good.png` as its ingredient); the script
  was run again, so every file of the directory has new throw-away keys.
  `c2patool` 0.28.3 calls all seven `Trusted`.
- `RevocationStoredIssuerTest.php`: 7 tests failed with the source
  changes stashed (the four `good` rows under the allowed list and with L
  as the anchor, the two ingredient rows, the explanation), the rest
  passed, including the `revoked` rows and the control under the root.
- Mutations: seven, all caught — the active or the ingredient caller
  always anchored, depth `>= 0`, depth `>= 2`, a `revoked` refused too,
  a signer that is not trusted affected, the `good` marked `chosen`.
- Corpus, 3,132 runs at `af68ccd` and now on the same fixtures, reports
  and evidence outcomes: identical.
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.
